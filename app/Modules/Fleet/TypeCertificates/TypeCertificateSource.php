<?php

declare(strict_types=1);

namespace App\Modules\Fleet\TypeCertificates;

/**
 * Where a type certificate can be looked up.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: "am liebsten hätte ich eine durchsuchbare liste mit der möglichkeit zum
 * freitext. und daran angehangen den automatischen download der kennblätter. die
 * können von Easa, faa oder den nationalen behörden kommen."
 *
 * So: one adapter per authority, the same seam the directive sources use -- which
 * is the second time that pattern has paid for itself. EASA first, because its
 * library is searchable and its documents are reachable without credentials (I
 * checked: the download URL answers application/pdf).
 *
 * The searchable-with-free-text part is not this interface's job: a search returns
 * candidates, and typing a designation nobody catalogued has to keep working.
 * Hence Candidate objects rather than records -- nothing is written until somebody
 * picks one.
 * ─────────────────────────────────────────────────────────────────────────────
 */
interface TypeCertificateSource
{
    /** Which authority this speaks for. One of AircraftType::AUTHORITY_*. */
    public function authority(): string;

    public function label(): string;

    /**
     * Types matching a designation.
     *
     * @return list<TypeCertificateCandidate>
     */
    public function search(string $designation, CertificateSubject $subject = CertificateSubject::Aircraft): array;
}
