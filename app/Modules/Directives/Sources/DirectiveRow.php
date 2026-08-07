<?php

declare(strict_types=1);

namespace App\Modules\Directives\Sources;

use App\Modules\Directives\Enums\Bindingness;
use App\Modules\Directives\Enums\DirectiveKind;
use App\Modules\Directives\Enums\SubjectKind;

/**
 * One incoming line, before it becomes a Directive.
 *
 * A value object between parsing and storing, so a source never touches Eloquent
 * and an importer never touches CSV. It also gives the import screen something to
 * show for review before anything is written -- which matters, because a bad
 * import into a list nobody may delete is expensive.
 */
final readonly class DirectiveRow
{
    public function __construct(
        public string $number,
        public string $title,
        public DirectiveKind $kind,
        public SubjectKind $subjectKind,

        /**
         * Null means "derive from the kind": an LTA/AD is binding, a TM/SB is not
         * until an authority adopts it. A source that knows better says so.
         */
        public ?Bindingness $bindingness = null,
        public ?string $issuer = null,
        public ?string $summary = null,
        public ?string $issuedAt = null,
        public ?string $complyBefore = null,
        public ?string $subjectModel = null,
        public ?string $subjectDesignation = null,
        public ?string $subjectPartNumber = null,
        public ?string $serialFrom = null,
        public ?string $serialTo = null,
        public bool $isRecurring = false,
        public ?int $intervalMonths = null,
        public ?string $intervalCounter = null,
        public ?float $intervalValue = null,
        public ?string $referenceUrl = null,
        public ?string $externalReference = null,

    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'number' => trim($this->number),
            'title' => trim($this->title),
            'summary' => $this->summary,
            'kind' => $this->kind,
            'bindingness' => $this->bindingness ?? ($this->kind->isMandatory()
                ? Bindingness::Mandatory
                : Bindingness::Optional),
            'issuer' => $this->issuer,
            'issued_at' => $this->issuedAt,
            'comply_before' => $this->complyBefore,
            'subject_kind' => $this->subjectKind,
            'subject_model' => $this->subjectModel,
            'subject_designation' => $this->subjectDesignation,
            'subject_part_number' => $this->subjectPartNumber,
            'serial_from' => $this->serialFrom,
            'serial_to' => $this->serialTo,
            'is_recurring' => $this->isRecurring,
            'interval_months' => $this->intervalMonths,
            'interval_counter' => $this->intervalCounter,
            'interval_value' => $this->intervalValue,
            'reference_url' => $this->referenceUrl,
            'external_reference' => $this->externalReference,
        ];
    }
}
