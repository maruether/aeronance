<?php

declare(strict_types=1);

namespace App\Modules\Fleet\Models;

use App\Modules\Fleet\Enums\SheetVariant;
use App\Modules\Fleet\Enums\Undercarriage;
use App\Modules\Fleet\Events\AircraftTypeCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One aircraft type and its data sheet.
 *
 * A type certificate is a property of the type, not of an airframe: three ASK 21s
 * share one Kennblatt. That is the whole reason this is a table -- see the
 * migration for the second reason, which is exact directive matching.
 */
final class AircraftType extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    /** Where the data sheet file lives when a club keeps its own copy. */
    public const DATA_SHEET = 'data_sheet';

    public const AUTHORITY_EASA = 'easa';

    public const AUTHORITY_FAA = 'faa';

    public const AUTHORITY_LBA = 'lba';

    public const AUTHORITY_OTHER = 'other';

    protected $fillable = [
        'designation',
        'manufacturer',
        'sheet_variant',
        'undercarriage',
        'type_support',
        'without_type_support',
        'type_certificate',
        'certificate_authority',
        'data_sheet_url',
        'data_sheet_checked_at',
        'directive_overview_url',
        'source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'data_sheet_checked_at' => 'date',
            'without_type_support' => 'boolean',
            'sheet_variant' => SheetVariant::class,
            'undercarriage' => Undercarriage::class,
        ];
    }

    /**
     * Was am Muster über das Wägeblatt hinterlegt ist -- und nur das.
     *
     * Beide Werte einzeln nullbar, weil sie einzeln bekannt sein können: Dass
     * eine Aquila auf dem Flugzeugblatt gewogen wird, weiss man ohne zu wissen,
     * ob dieses Exemplar auf Bugrad oder Spornrad steht. Was fehlt, ergänzt
     * SheetSetup aus dem, was das Flugzeug selbst hergibt.
     */
    public function sheetVariant(): ?SheetVariant
    {
        return $this->sheet_variant;
    }

    public function undercarriage(): ?Undercarriage
    {
        return $this->undercarriage;
    }

    /**
     * Was beim Anlegen einer Wägung gewählt wurde, am Muster hinterlegen.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * NUR WAS LEER IST. Das Muster ist die Aussage, die jemand getroffen hat;
     * eine Wägung darf sie ergänzen, aber nicht überschreiben. Sonst würde ein
     * Exemplar mit Sonderfahrwerk das Muster für alle anderen umstellen -- und
     * niemand hätte es gesehen.
     *
     * Gibt zurück, ob tatsächlich etwas geschrieben wurde: Der Aufrufer sagt es
     * dem Benutzer, statt still am Muster zu drehen.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function rememberWeighingSetup(SheetVariant $variant, Undercarriage $undercarriage): bool
    {
        $werte = [];

        if ($this->sheet_variant === null) {
            $werte['sheet_variant'] = $variant;
        }

        if ($this->undercarriage === null) {
            $werte['undercarriage'] = $undercarriage;
        }

        if ($werte === []) {
            return false;
        }

        $this->fill($werte)->save();

        return true;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'designation', 'manufacturer', 'type_certificate', 'certificate_authority',
                'data_sheet_url',
                /*
                 * Logged because it changes how every directive list for this
                 * type reads. Who set the flag, and when, is exactly the
                 * question somebody will ask a year later.
                 */
                'type_support', 'without_type_support',
                // Blattart und Fahrwerk entscheiden, welches Wägeblatt jedes
                // Flugzeug dieses Musters bekommt -- eine Änderung hier wirkt
                // auf die nächste Wägung jedes Exemplars.
                'sheet_variant', 'undercarriage',
            ])
            ->logOnlyDirty()
            ->useLogName('fleet');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DATA_SHEET)
            ->acceptsMimeTypes(['application/pdf']);
    }

    /** @return HasMany<Aircraft, $this> */
    public function aircraft(): HasMany
    {
        return $this->hasMany(Aircraft::class);
    }

    /**
     * Every Kennblatt number this type is on file under.
     *
     * @return HasMany<AircraftTypeCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(AircraftTypeCertificate::class);
    }

    /**
     * Put a number on this type, or bring the one already there up to date.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * NEVER A SECOND ROW FOR THE SAME NUMBER. A club looks a type up at the EASA
     * and again in the Blaues Buch, and both name EASA.A.221; recorded twice,
     * every directive quoting it would arrive twice.
     *
     * Making one primary demotes the others rather than leaving two -- the
     * primary is what aircraft_types.type_certificate mirrors, and two of them
     * would make that mirror a coin toss.
     */
    public function recordCertificate(
        string $number,
        ?string $authority = null,
        ?string $dataSheetUrl = null,
        bool $primary = false,
    ): ?AircraftTypeCertificate {
        $number = trim($number);

        if ($number === '') {
            return null;
        }

        $certificate = $this->certificates()->firstOrNew(['number' => $number]);

        $certificate->authority = $authority ?? $certificate->authority;
        $certificate->data_sheet_url = $dataSheetUrl ?? $certificate->data_sheet_url;

        if ($primary) {
            $certificate->is_primary = true;
        }

        $certificate->save();

        if ($primary) {
            $this->certificates()
                ->whereKeyNot($certificate->getKey())
                ->update(['is_primary' => false]);
        }

        return $certificate;
    }

    /**
     * Keeping the mirror honest.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * type_certificate stays on the type because every screen, export and log
     * entry refers to it. That makes it a copy, and a copy drifts: somebody
     * edits the field in the type form and the lookup table still holds the old
     * number, so directives keep matching a certificate the club no longer says
     * it has.
     *
     * Done on save rather than at each call site, because the call sites are the
     * problem -- the Filament form, the adopt action and a future import each
     * write that column, and each would have to remember.
     */
    protected static function booted(): void
    {
        /*
         * Die Naht nach draussen: Ein neues Muster wird gemeldet, damit das
         * Directives-Modul passende Herstellerlisten anziehen kann -- ohne
         * dass die Flotte es kennt. Feldtest: "Liste sollte automatisch zu
         * einem angelegten Muster gezogen werden, ohne user interaktion."
         */
        self::created(function (self $type): void {
            event(new AircraftTypeCreated(
                typeId: $type->id,
                designation: (string) $type->designation,
                manufacturer: $type->manufacturer,
                userId: auth()->id(),
            ));
        });

        self::saved(function (self $type): void {
            if (blank($type->type_certificate)) {
                return;
            }

            $type->recordCertificate(
                (string) $type->type_certificate,
                $type->certificate_authority,
                $type->data_sheet_url,
                primary: true,
            );
        });
    }

    public function hasDataSheet(): bool
    {
        return filled($this->data_sheet_url) || $this->getMedia(self::DATA_SHEET)->isNotEmpty();
    }

    /**
     * Whether anything is known about the certificate.
     *
     * Reported rather than assumed: a type without a Kennblatt is a normal state
     * -- somebody has to look it up -- and the interface should say so rather
     * than showing an empty field and hoping.
     */
    public function isDocumented(): bool
    {
        return filled($this->type_certificate) && $this->hasDataSheet();
    }

    /**
     * Whether this type has been declared orphaned -- nobody looks after it.
     *
     * A stated fact, never inferred. "No source configured" and "no type support
     * left" both end in an empty list and want opposite reactions: the first is a
     * task for the administrator, the second is permanent and hands the research
     * back to the club. See the migration for the three states this separates.
     */
    public function isOrphaned(): bool
    {
        return (bool) $this->without_type_support;
    }

    /**
     * Who is named as looking after the type, if anybody is.
     *
     * Null when the type is orphaned OR when nobody has filled the field in --
     * deliberately the same answer, because a name is only ever an addition to
     * the warning, never what decides it.
     */
    public function typeSupport(): ?string
    {
        return $this->isOrphaned() ? null : ($this->type_support ?: null);
    }

    /**
     * What choosing this type should write onto an aircraft.
     *
     * Here rather than in a closure inside the form schema, which is where it
     * started: logic in a form closure is untestable by construction, and this
     * decides what a person sees in two fields.
     *
     * The manufacturer is only offered, never forced -- an aircraft may record a
     * different one legitimately (a licence-built airframe), and overwriting that
     * silently would be worse than leaving it.
     *
     * @return array<string, string> attribute => value
     */
    public function prefill(): array
    {
        $values = ['model' => $this->designation];

        if (filled($this->manufacturer)) {
            $values['manufacturer'] = $this->manufacturer;
        }

        return $values;
    }

    public function label(): string
    {
        return filled($this->type_certificate)
            ? sprintf('%s (%s)', $this->designation, $this->type_certificate)
            : $this->designation;
    }

    /**
     * Whether a model string means this type.
     *
     * The loose comparison, kept here rather than in the directives module: it is
     * a statement about type NAMES, which is this module's business. Substring in
     * both directions, because manufacturers write "ASK 21" where a club writes
     * "ASK 21 B" and either can be the longer.
     */
    public function matchesModel(?string $model): bool
    {
        if ($model === null || trim($model) === '') {
            return false;
        }

        $a = mb_strtolower(trim($this->designation));
        $b = mb_strtolower(trim($model));

        return $a === $b || str_contains($b, $a) || str_contains($a, $b);
    }
}
