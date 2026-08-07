/*
 * Der Scanner — Kamera an, QR-Code lesen, Bescheid geben.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „ich dachte eher daran das aeronance selbst einen scanner aufmacht
 * und somit darin nur Infos sind die das tool braucht." Und zum Einsatzort:
 * „warum bauen wir den scanner nicht gleich in das workorder modul ein?
 * Techniker holt teil aus schrank, scannt es und muss nicht weiter suchen und
 * nummern tippen."
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EIN EIGENES HTML-ELEMENT UND KEINE ALPINE-KOMPONENTE.
 *
 * Der Scanner hält eine Kamera offen — eine Ressource, die wieder losgelassen
 * werden MUSS. Ein Custom Element bekommt vom Browser gesagt, wann es aus dem
 * Dokument verschwindet (`disconnectedCallback`), und genau dort wird der
 * Kamerastrom beendet. Hängt die Logik an einem Alpine-Attribut, gibt es diesen
 * Zeitpunkt nicht zuverlässig: Livewire tauscht Teile der Seite aus, und was
 * bleibt, ist eine leuchtende Kameraleuchte an einem Gerät, auf dem niemand
 * mehr scannt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER POLYFILL IST DER STANDARD, NICHT EIN ERSATZ.
 *
 * `BarcodeDetector` ist eine Browser-API, die längst nicht überall da ist —
 * Safari kann sie nicht, und ein Verein voller iPhones wäre ohne Polyfill ein
 * Verein ohne Scanner. Eingebunden wird deshalb die Polyfill-Fassung, die die
 * native benutzt, wenn es sie gibt. Zieht Safari nach, fällt die Abhängigkeit
 * ersatzlos weg, ohne dass sich hier eine Zeile ändert.
 */

import { BarcodeDetector, setZXingModuleOverrides } from 'barcode-detector/ponyfill'

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS WASM KOMMT AUS DIESER INSTANZ, NICHT VON EINEM CDN.
 *
 * GEMESSEN: Im gebauten Bundle stand `https://fastly.jsdelivr.net/npm/zxing-wasm`
 * -- der Dekoder holt sich seinen WebAssembly-Teil zur Laufzeit nach, und zwar
 * per Voreinstellung aus dem Netz. Das waere drei Dinge auf einmal:
 *
 *   1. Von `connect-src 'self'` blockiert. Der Scanner haette stumm versagt,
 *      und der Grund staende nur in der Browserkonsole.
 *   2. Gegen die Leitplanke „das Projekt bindet keine CDNs ein".
 *   3. Bei einer oeffentlich erreichbaren Instanz eine Meldung an einen
 *      Dritten, dass es sie gibt -- bei JEDEM Scan.
 *
 * Die Datei wird deshalb beim Bauen mitkopiert und von hier ausgeliefert.
 * ─────────────────────────────────────────────────────────────────────────────
 */
setZXingModuleOverrides({
    locateFile: (path, prefix) => (path.endsWith('.wasm') ? `/wasm/${path}` : prefix + path),
})

class AeronanceScanner extends HTMLElement {
    connectedCallback() {
        this.innerHTML = `
            <div class="ae-scanner">
                <video class="ae-scanner__view" playsinline muted></video>
                <p class="ae-scanner__note" role="status"></p>
                <button type="button" class="ae-scanner__stop" hidden></button>
            </div>
        `

        this.video = this.querySelector('video')
        this.note = this.querySelector('.ae-scanner__note')
        this.stopButton = this.querySelector('.ae-scanner__stop')

        this.stopButton.textContent = this.dataset.stopLabel ?? 'Kamera aus'
        this.stopButton.addEventListener('click', () => this.stop())

        this.start()
    }

    /*
     * Wird vom Browser gerufen, wenn das Element aus dem Dokument faellt --
     * auch dann, wenn Livewire die halbe Seite austauscht. Der einzige Ort, an
     * dem das Abschalten der Kamera zuverlaessig haengen kann.
     */
    disconnectedCallback() {
        this.stop()
    }

    async start() {
        /*
         * DER HAEUFIGSTE FEHLSCHLAG IST KEIN FEHLER, SONDERN EIN AUFBAU:
         * Kamerazugriff gibt es nur im sicheren Kontext. Ueber http:// im
         * Vereinsnetz fehlt `mediaDevices` schlicht -- und ohne diesen Hinweis
         * sucht jemand den Fehler im Telefon statt im Aufruf.
         */
        if (!navigator.mediaDevices?.getUserMedia) {
            this.say(this.dataset.insecureLabel ?? 'Kamera nur über HTTPS erreichbar.')
            return
        }

        try {
            this.detector = new BarcodeDetector({ formats: ['qr_code'] })

            this.stream = await navigator.mediaDevices.getUserMedia({
                // Die rueckwaertige Kamera, sonst filmt ein Telefon das Gesicht
                // statt des Regals.
                video: { facingMode: { ideal: 'environment' } },
            })

            this.video.srcObject = this.stream
            await this.video.play()

            this.stopButton.hidden = false
            this.say(this.dataset.scanningLabel ?? 'Code vor die Kamera halten.')

            this.tick()
        } catch (error) {
            /*
             * Abgelehnte Erlaubnis, belegte Kamera, kein Geraet -- fuer den
             * Menschen davor ist das dasselbe: Es geht nicht, und er braucht
             * das Tastaturfeld daneben. Die technische Ursache steht in der
             * Konsole, nicht auf dem Bildschirm.
             */
            console.warn('[aeronance] Kamera nicht verfügbar:', error)
            this.say(this.dataset.deniedLabel ?? 'Keine Kamera. Nummer bitte eintippen.')
        }
    }

    tick() {
        if (!this.stream) {
            return
        }

        /*
         * Ueber requestAnimationFrame und nicht ueber einen Timer: Laeuft die
         * Seite im Hintergrund, hoert das Bild von selbst auf, und das Telefon
         * eines Technikers in der Hosentasche rechnet nicht weiter.
         */
        this.frame = requestAnimationFrame(async () => {
            try {
                const codes = await this.detector.detect(this.video)

                if (codes.length > 0) {
                    this.found(codes[0].rawValue)
                    return
                }
            } catch (error) {
                // Ein einzelnes unlesbares Bild ist der Normalfall, kein
                // Fehler. Weitermachen.
            }

            this.tick()
        })
    }

    found(value) {
        /*
         * EIN TREFFER, DANN AUS. Ein Scanner, der weiterlaeuft, meldet
         * denselben Code dreissigmal in der Sekunde -- und was daran haengt,
         * bucht dreissigmal.
         */
        this.stop()

        this.say(this.dataset.foundLabel ?? 'Gelesen.')

        if (navigator.vibrate) {
            navigator.vibrate(60)
        }

        this.dispatchEvent(new CustomEvent('scan', {
            detail: { code: value },
            bubbles: true,
        }))
    }

    stop() {
        if (this.frame) {
            cancelAnimationFrame(this.frame)
            this.frame = null
        }

        if (this.stream) {
            this.stream.getTracks().forEach((track) => track.stop())
            this.stream = null
        }

        if (this.video) {
            this.video.srcObject = null
        }

        if (this.stopButton) {
            this.stopButton.hidden = true
        }
    }

    say(text) {
        if (this.note) {
            this.note.textContent = text
        }
    }
}

if (!customElements.get('aeronance-scanner')) {
    customElements.define('aeronance-scanner', AeronanceScanner)
}
