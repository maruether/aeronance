<?php

declare(strict_types=1);

namespace App\Modules\Fleet\TypeCertificates\Lba;

/**
 * The volumes of the LBA's "Blaues Buch".
 *
 * Vorgabe: "da stehen alle in de registrierbaren Flugzeuge und co mit kennblatt
 * drin." One document per category, and between them they cover everything a
 * German club can register.
 *
 * Only the AIRCRAFT volumes are wired up. Engines, propellers, tow releases and
 * winches are in there too -- and the Tost coupling directives make those
 * genuinely interesting -- but they are components, not aircraft types, and this
 * seam produces AircraftTypes. Listed here so the gap is visible rather than
 * forgotten.
 */
enum BlueBookCategory: string
{
    case Gliders = 'segel';

    case PoweredSailplanes = 'motorsegel';

    /** Aeroplanes up to 2 t -- where a club's tug lives. */
    case AeroplanesUpTo2t = 'lfz_2t';

    case AeroplanesOver2t = 'lfz_ue_2t';

    /*
     * The component volumes -- "auch die haben tms", and they have Kennblätter
     * too. Not readable at all until the extractor could keep columns apart;
     * see PdfLayoutText for why that needed a system binary.
     */
    case Engines = 'motore';

    case Propellers = 'propeller';

    case TowReleases = 'kupplung';

    public function url(): string
    {
        /*
         * The coupling volume sits somewhere else entirely -- /dokumente/zuger/
         * rather than /data/bb/Blaues_Buch/ -- and is a good deal older than the
         * rest (2008). Written out per case rather than assembled from a common
         * prefix, because an assembled URL would have quietly produced a 404 for
         * exactly one volume.
         */
        return match ($this) {
            self::Gliders => 'https://www2.lba.de/data/bb/Blaues_Buch/04_segel.pdf',
            self::PoweredSailplanes => 'https://www2.lba.de/data/bb/Blaues_Buch/05_motorsegel.pdf',
            self::AeroplanesUpTo2t => 'https://www2.lba.de/data/bb/Blaues_Buch/01_lfz_2t.pdf',
            self::AeroplanesOver2t => 'https://www2.lba.de/data/bb/Blaues_Buch/02_lfz_ue_2t.pdf',
            self::Engines => 'https://www2.lba.de/data/bb/Blaues_Buch/08_1_motore.pdf',
            self::Propellers => 'https://www2.lba.de/data/bb/Blaues_Buch/09_1_propeller.pdf',
            self::TowReleases => 'https://www2.lba.de/dokumente/zuger/07-1-kupplung.pdf',
        };
    }

    /** Whether this volume lists aircraft or the things fitted to them. */
    public function isComponent(): bool
    {
        return in_array($this, [self::Engines, self::Propellers, self::TowReleases], true);
    }

    /**
     * The component volumes.
     *
     * Searched separately from the aircraft ones: a club looking up an engine
     * has no use for 157 gliders in the result, and the other way round.
     *
     * @return list<self>
     */
    public static function components(): array
    {
        return [self::Engines, self::Propellers, self::TowReleases];
    }

    public function label(): string
    {
        return __('fleet.type.blue_book.'.$this->value);
    }

    /**
     * The volumes searched by default.
     *
     * A gliding club's fleet: sailplanes, powered sailplanes, and the tug. The
     * over-2-tonne volume is a large document that would only slow the search
     * down for the clubs this is built for.
     *
     * @return list<self>
     */
    public static function forClubs(): array
    {
        return [self::Gliders, self::PoweredSailplanes, self::AeroplanesUpTo2t];
    }
}
