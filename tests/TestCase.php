<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ─────────────────────────────────────────────────────────────────────
         * KEIN FRONTEND-BUILD FÜR EINEN FEATURE-TEST.
         *
         * Das Filament-Layout ruft @vite auf, und ohne gebaute Assets wirft das
         * "Vite manifest not found at public/build/manifest.json". Jeder Test,
         * der eine Seite abruft, stirbt daran -- sieben Stück.
         *
         * LOKAL FIEL DAS NIE AUF: public/build liegt auf der Entwickler-
         * maschine und steht in .gitignore. In der CI existiert es nicht, und
         * nur der pack-Job baut Assets -- der läuft auf Tags. Die Folge war
         * eine Pipeline, die auf master seit dem 31. Juli rot war, während die
         * volle Suite hier grün durchlief. Ein grüner lokaler Lauf war schlicht
         * kein Beleg.
         *
         * withoutVite() statt Assets im Testlauf zu bauen: Diese Tests prüfen,
         * ob eine Seite erreichbar ist und wer sie sehen darf, nicht ob ein
         * Stylesheet übersetzt wird. Node in den Testlauf zu holen hiesse, jede
         * Rechteprüfung von einem npm-Build abhängig zu machen.
         *
         * DASS DER BUILD FUNKTIONIERT, prüft die CI trotzdem -- in einem
         * eigenen Job (assets in .gitlab-ci.yml). Dort gehört es hin.
         * ─────────────────────────────────────────────────────────────────────
         */
        $this->withoutVite();
    }
}
