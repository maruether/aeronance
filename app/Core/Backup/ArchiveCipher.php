<?php

declare(strict_types=1);

namespace App\Core\Backup;

use RuntimeException;
use SensitiveParameter;

/**
 * Sicherungen verschlüsseln -- mit öffentlichem Schlüssel oder mit Passwort.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WARUM ES BEIDES GIBT. Vorgabe: "ich habe etwas sorgen mit der asymetrischen
 * verschlüsselung. das bekommen viele nicht hin. wir sollten das einbauen und
 * empfehlen, aber auch ein passwort anbieten."
 *
 * Das ist die richtige Konsequenz: Was ein Verein nicht hinbekommt, schützt
 * niemanden. Ein Passwort, das benutzt wird, ist besser als ein Schlüsselpaar,
 * an dem der Abend scheitert.
 *
 * Die beiden schützen aber gegen VERSCHIEDENE Dinge, und das gehört
 * ausgesprochen:
 *
 *   Öffentlicher Schlüssel -- der Server kann seine eigenen Sicherungen NICHT
 *   lesen. Wer den Server übernimmt, bekommt die Daten von heute, aber nicht
 *   die Historie. Der private Schlüssel liegt beim Verein, offline.
 *
 *   Passwort -- das Passwort muss der geplante Lauf kennen, liegt also in der
 *   .env auf demselben Rechner. Wer den Server hat, hat auch das Passwort. Es
 *   schützt die Sicherung DORT, wo sie hingeht: im Backup-Space des Anbieters,
 *   auf einer verlorenen Platte, in einem falsch gesetzten Bucket. Genau dafür
 *   ist es gedacht, und dafür reicht es.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS DATEIFORMAT, und warum es so aussieht.
 *
 * Beide Verfahren teilen sich denselben Rumpf: ein zufälliger Inhaltsschlüssel
 * verschlüsselt die Datei in Blöcken mit AES-256-GCM. Nur der KOPF unterscheidet
 * sich -- dort liegt der Inhaltsschlüssel entweder mit RSA-OAEP an den
 * Empfänger versiegelt oder mit einem aus dem Passwort abgeleiteten Schlüssel
 * eingewickelt.
 *
 * In Blöcken, weil eine Vereinssicherung Gigabytes hat: alles in den Speicher
 * zu laden wäre auf einem kleinen Server das Ende.
 *
 * DREI SCHUTZMASSNAHMEN GEGEN DAS, WAS BEI SELBSTGEBAUTEM RAHMENWERK SCHIEFGEHT:
 *
 *  1. Jeder Block trägt seine NUMMER im mitauthentifizierten Zusatz (AAD).
 *     Zwei Blöcke zu vertauschen scheitert damit, statt eine andere, ebenfalls
 *     gültige Datei zu ergeben.
 *  2. Der LETZTE Block ist als solcher markiert, ebenfalls im AAD. Ohne das
 *     liesse sich eine Sicherung hinten abschneiden, und das Ergebnis
 *     entschlüsselte sauber -- eine halbe Datenbank, die wie eine ganze aussieht.
 *  3. Der Kopf geht als Hash in jeden Block ein. Wer am Kopf dreht -- etwa das
 *     Verfahren von "öffentlicher Schlüssel" auf "Passwort" umschreibt --,
 *     bekommt keinen einzigen Block mehr auf.
 *
 * Die Nonce ist nie zweimal dieselbe: acht Byte Zufall je Datei, dazu der
 * Blockzähler. Der Inhaltsschlüssel ist ohnehin je Sicherung neu.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ArchiveCipher
{
    /** Damit eine fremde Datei nicht als halb entschlüsselte Sicherung endet. */
    private const MAGIC = "AERONANCE-BACKUP\x00";

    /** Im Kopf mitgeschrieben, damit ein späteres Format alte Dateien noch liest. */
    private const VERSION = 1;

    public const MODE_RECIPIENT = 'recipient';

    public const MODE_PASSPHRASE = 'passphrase';

    /**
     * Ein Megabyte je Block.
     *
     * Gross genug, dass der Mehraufwand je Block (16 Byte Prüfsumme) nicht ins
     * Gewicht fällt, klein genug, dass der Speicherbedarf konstant bleibt.
     */
    private const CHUNK = 1024 * 1024;

    private const CIPHER = 'aes-256-gcm';

    /**
     * Wie oft das Passwort durchgerührt wird.
     *
     * Im Kopf mitgeschrieben, nicht fest verdrahtet: Rechner werden schneller,
     * und eine alte Sicherung muss lesbar bleiben, wenn dieser Wert steigt.
     */
    private const PBKDF2_ITERATIONS = 210_000;

    /**
     * Verschlüsselt $quelle nach $ziel für den Inhaber des privaten Schlüssels.
     *
     * @param  string  $publicKeyPem  der ÖFFENTLICHE Schlüssel des Vereins
     */
    public function encryptForRecipient(string $quelle, string $ziel, string $publicKeyPem): void
    {
        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            throw new RuntimeException(
                'Der öffentliche Schlüssel liess sich nicht lesen. Erwartet wird ein '
                .'PEM-Block ("-----BEGIN PUBLIC KEY-----"), wie ihn '
                .'"openssl rsa -in privat.pem -pubout" erzeugt.'
            );
        }

        $inhaltsschluessel = random_bytes(32);
        $versiegelt = '';

        if (! openssl_public_encrypt($inhaltsschluessel, $versiegelt, $key, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('Der Inhaltsschlüssel liess sich nicht versiegeln.');
        }

        $this->write($quelle, $ziel, $inhaltsschluessel, [
            'mode' => self::MODE_RECIPIENT,
            'sealed' => base64_encode($versiegelt),
        ]);
    }

    /**
     * Verschlüsselt $quelle nach $ziel mit einem Passwort.
     */
    public function encryptWithPassphrase(
        string $quelle,
        string $ziel,
        #[SensitiveParameter] string $passwort,
    ): void {
        if (mb_strlen($passwort) < 12) {
            /*
             * Keine Zierde: Dieses Passwort ist das Einzige zwischen einem
             * fremden Backup-Space und den Daten eines Vereins, und es wird
             * einmal gesetzt und nie wieder getippt. Kurz gewählt ist es
             * offline in Stunden gebrochen.
             */
            throw new RuntimeException(
                'Das Backup-Passwort muss mindestens 12 Zeichen haben. Es schützt die '
                .'Sicherung dort, wo sie liegt, und wird nur einmal gesetzt.'
            );
        }

        $inhaltsschluessel = random_bytes(32);
        $salz = random_bytes(16);
        $iterationen = self::PBKDF2_ITERATIONS;

        $einwickler = hash_pbkdf2('sha512', $passwort, $salz, $iterationen, 32, true);
        $nonce = random_bytes(12);
        $tag = '';

        $eingewickelt = openssl_encrypt(
            $inhaltsschluessel,
            self::CIPHER,
            $einwickler,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($eingewickelt === false) {
            throw new RuntimeException('Der Inhaltsschlüssel liess sich nicht einwickeln.');
        }

        $this->write($quelle, $ziel, $inhaltsschluessel, [
            'mode' => self::MODE_PASSPHRASE,
            'salt' => base64_encode($salz),
            'iterations' => $iterationen,
            'nonce' => base64_encode($nonce),
            'wrapped' => base64_encode($eingewickelt),
            'tag' => base64_encode($tag),
        ]);
    }

    /**
     * Entschlüsselt mit dem privaten Schlüssel.
     */
    public function decryptWithKey(
        string $quelle,
        string $ziel,
        string $privateKeyPem,
        #[SensitiveParameter] ?string $keyPassphrase = null,
    ): void {
        [$kopf, $handle, $kopfHash] = $this->readHeader($quelle);

        if (($kopf['mode'] ?? null) !== self::MODE_RECIPIENT) {
            fclose($handle);

            throw new RuntimeException(
                'Diese Sicherung ist mit einem Passwort verschlüsselt, nicht mit einem '
                .'Schlüssel. Sie braucht --passphrase.'
            );
        }

        $key = openssl_pkey_get_private($privateKeyPem, $keyPassphrase);

        if ($key === false) {
            fclose($handle);

            throw new RuntimeException('Der private Schlüssel liess sich nicht lesen.');
        }

        $inhaltsschluessel = '';

        if (! openssl_private_decrypt(
            base64_decode((string) $kopf['sealed'], true) ?: '',
            $inhaltsschluessel,
            $key,
            OPENSSL_PKCS1_OAEP_PADDING,
        )) {
            fclose($handle);

            throw new RuntimeException(
                'Der Inhaltsschlüssel liess sich nicht öffnen -- dieser private Schlüssel '
                .'gehört nicht zu dieser Sicherung.'
            );
        }

        $this->read($handle, $ziel, $inhaltsschluessel, $kopfHash, $this->nonceBaseOf($kopf));
    }

    /**
     * Entschlüsselt mit dem Passwort.
     */
    public function decryptWithPassphrase(
        string $quelle,
        string $ziel,
        #[SensitiveParameter] string $passwort,
    ): void {
        [$kopf, $handle, $kopfHash] = $this->readHeader($quelle);

        if (($kopf['mode'] ?? null) !== self::MODE_PASSPHRASE) {
            fclose($handle);

            throw new RuntimeException(
                'Diese Sicherung ist an einen Schlüssel gerichtet, nicht an ein Passwort. '
                .'Sie braucht den privaten Schlüssel.'
            );
        }

        $einwickler = hash_pbkdf2(
            'sha512',
            $passwort,
            base64_decode((string) $kopf['salt'], true) ?: '',
            (int) $kopf['iterations'],
            32,
            true,
        );

        $inhaltsschluessel = openssl_decrypt(
            base64_decode((string) $kopf['wrapped'], true) ?: '',
            self::CIPHER,
            $einwickler,
            OPENSSL_RAW_DATA,
            base64_decode((string) $kopf['nonce'], true) ?: '',
            base64_decode((string) $kopf['tag'], true) ?: '',
        );

        if ($inhaltsschluessel === false) {
            fclose($handle);

            /*
             * Falsches Passwort und beschädigte Datei sehen hier gleich aus, und
             * das ist beabsichtigt: Wer raten will, soll aus der Antwort nicht
             * lernen, ob er näher dran ist.
             */
            throw new RuntimeException(
                'Das Passwort passt nicht zu dieser Sicherung -- oder die Datei ist '
                .'beschädigt.'
            );
        }

        $this->read($handle, $ziel, $inhaltsschluessel, $kopfHash, $this->nonceBaseOf($kopf));
    }

    /**
     * Womit diese Datei verschlüsselt wurde, ohne sie zu öffnen.
     *
     * Damit ein Restore sagen kann "die braucht ein Passwort", statt den Nutzer
     * raten zu lassen.
     */
    public function modeOf(string $datei): string
    {
        [$kopf, $handle] = $this->readHeader($datei);
        fclose($handle);

        return (string) ($kopf['mode'] ?? 'unbekannt');
    }

    public function isEncrypted(string $datei): bool
    {
        $handle = @fopen($datei, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    /**
     * @param  array<string, mixed>  $kopf
     */
    private function write(string $quelle, string $ziel, string $inhaltsschluessel, array $kopf): void
    {
        $ein = @fopen($quelle, 'rb');

        if ($ein === false) {
            throw new RuntimeException(sprintf('%s liess sich nicht lesen.', $quelle));
        }

        $aus = @fopen($ziel, 'wb');

        if ($aus === false) {
            fclose($ein);

            throw new RuntimeException(sprintf('%s liess sich nicht schreiben.', $ziel));
        }

        $kopf['version'] = self::VERSION;
        $kopf['nonce_base'] = base64_encode($basis = random_bytes(8));

        $kopfJson = json_encode($kopf, JSON_THROW_ON_ERROR);
        $kopfHash = hash('sha256', $kopfJson, true);

        fwrite($aus, self::MAGIC);
        fwrite($aus, pack('N', strlen($kopfJson)));
        fwrite($aus, $kopfJson);

        $nummer = 0;
        $puffer = (string) fread($ein, self::CHUNK);

        while (true) {
            $naechster = feof($ein) ? '' : (string) fread($ein, self::CHUNK);
            $letzter = $naechster === '' && feof($ein);

            $this->writeChunk($aus, $puffer, $inhaltsschluessel, $basis, $nummer, $letzter, $kopfHash);

            if ($letzter) {
                break;
            }

            $puffer = $naechster;
            $nummer++;
        }

        fclose($ein);
        fclose($aus);
    }

    private function writeChunk(
        mixed $aus,
        string $klartext,
        string $schluessel,
        string $basis,
        int $nummer,
        bool $letzter,
        string $kopfHash,
    ): void {
        $tag = '';

        $geheim = openssl_encrypt(
            $klartext,
            self::CIPHER,
            $schluessel,
            OPENSSL_RAW_DATA,
            $basis.pack('N', $nummer),
            $tag,
            $this->aad($nummer, $letzter, $kopfHash),
        );

        if ($geheim === false) {
            throw new RuntimeException('Ein Block liess sich nicht verschlüsseln.');
        }

        fwrite($aus, pack('N', strlen($geheim)));
        fwrite($aus, $letzter ? "\x01" : "\x00");
        fwrite($aus, $tag);
        fwrite($aus, $geheim);
    }

    /**
     * @return array{0: array<string, mixed>, 1: mixed, 2: string}
     */
    private function readHeader(string $datei): array
    {
        $handle = @fopen($datei, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('%s liess sich nicht lesen.', $datei));
        }

        if ((string) fread($handle, strlen(self::MAGIC)) !== self::MAGIC) {
            fclose($handle);

            throw new RuntimeException(
                sprintf('%s ist keine verschlüsselte Aeronance-Sicherung.', $datei)
            );
        }

        $laenge = unpack('N', (string) fread($handle, 4));
        $kopfJson = (string) fread($handle, (int) ($laenge[1] ?? 0));

        try {
            $kopf = json_decode($kopfJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            fclose($handle);

            throw new RuntimeException('Der Kopf dieser Sicherung ist beschädigt.');
        }

        if (! is_array($kopf) || (int) ($kopf['version'] ?? 0) > self::VERSION) {
            fclose($handle);

            throw new RuntimeException(
                'Diese Sicherung wurde mit einer neueren Fassung geschrieben. Zum Lesen '
                .'braucht es dieselbe oder eine neuere Fassung von Aeronance.'
            );
        }

        return [$kopf, $handle, hash('sha256', $kopfJson, true)];
    }

    private function read(mixed $handle, string $ziel, string $schluessel, string $kopfHash, string $basis): void
    {
        $aus = @fopen($ziel, 'wb');

        if ($aus === false) {
            fclose($handle);

            throw new RuntimeException(sprintf('%s liess sich nicht schreiben.', $ziel));
        }

        $nummer = 0;
        $fertig = false;

        while (! feof($handle)) {
            $laengeRoh = (string) fread($handle, 4);

            if ($laengeRoh === '' || strlen($laengeRoh) < 4) {
                break;
            }

            $laenge = (int) (unpack('N', $laengeRoh)[1] ?? 0);
            $letzter = (string) fread($handle, 1) === "\x01";
            $tag = (string) fread($handle, 16);
            $geheim = (string) fread($handle, $laenge);

            $klartext = openssl_decrypt(
                $geheim,
                self::CIPHER,
                $schluessel,
                OPENSSL_RAW_DATA,
                $basis.pack('N', $nummer),
                $tag,
                $this->aad($nummer, $letzter, $kopfHash),
            );

            if ($klartext === false) {
                fclose($handle);
                fclose($aus);
                @unlink($ziel);

                throw new RuntimeException(sprintf(
                    'Block %d liess sich nicht prüfen -- die Sicherung ist beschädigt oder '
                    .'wurde verändert.',
                    $nummer,
                ));
            }

            fwrite($aus, $klartext);

            if ($letzter) {
                $fertig = true;

                break;
            }

            $nummer++;
        }

        fclose($handle);
        fclose($aus);

        if (! $fertig) {
            @unlink($ziel);

            /*
             * DER ABGESCHNITTENE FALL. Ohne diese Prüfung ergäbe eine halbe
             * Sicherung eine halbe Datenbank, die sich sauber wiederherstellen
             * liesse -- und niemandem fiele auf, dass die Hälfte fehlt.
             */
            throw new RuntimeException(
                'Die Sicherung endet vorzeitig -- der Schlussblock fehlt. Sie ist '
                .'unvollständig und wird nicht wiederhergestellt.'
            );
        }
    }

    /**
     * Die Nonce-Basis dieser Datei, aus ihrem Kopf.
     *
     * @param  array<string, mixed>  $kopf
     */
    private function nonceBaseOf(array $kopf): string
    {
        $basis = base64_decode((string) ($kopf['nonce_base'] ?? ''), true);

        if ($basis === false || strlen($basis) !== 8) {
            throw new RuntimeException('Dem Kopf dieser Sicherung fehlt die Nonce-Basis.');
        }

        return $basis;
    }

    private function aad(int $nummer, bool $letzter, string $kopfHash): string
    {
        return $kopfHash.pack('N', $nummer).($letzter ? "\x01" : "\x00");
    }
}
