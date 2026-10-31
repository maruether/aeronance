<?php

declare(strict_types=1);

namespace App\Modules\Billing\Documents;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Support\Money;
use DOMDocument;
use DOMElement;

/**
 * Eine XRechnung (UBL 2.1, EN 16931) aus einer gestellten Rechnung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * MITGELIEFERT, NICHT ERZWUNGEN (Entscheidung 2026-10-30): Das PDF bleibt
 * das Dokument, das jeder Kunde bekommt; die XML liegt daneben für die, die
 * sie verlangen. Ab 2027 müssen Aussteller über 800.000 € Umsatz im
 * inländischen B2B-Geschäft elektronisch stellen, ab 2028 alle.
 *
 * WAS DAS HIER IST UND WAS NICHT. Die Struktur folgt der XRechnung 3.0 in
 * UBL-Ausprägung mit den Pflichtfeldern der EN 16931 und den deutschen
 * Zusatzregeln (BR-DE): Käuferreferenz, Kontakt des Verkäufers, Zahlungsweg.
 * Geprüft ist sie gegen den Aufbau, nicht gegen die vollständige
 * Schematron-Regelmenge des KoSIT-Validators -- das steht so in der Doku,
 * bis ein Kunde die erste XRechnung tatsächlich einreicht. Die Zahlen sind
 * dieselben wie auf dem PDF, aus denselben Spalten.
 *
 * GUTSCHRIFTEN tragen den Typ 381 im Invoice-Wurzelelement und positive
 * Beträge -- so will es die EN 16931; das Vorzeichen steckt im Typ.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class XRechnungWriter
{
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';

    private DOMDocument $doc;

    public function write(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'corrects']);
        $organisation = (array) config('aeronance.organisation', []);
        $credit = $invoice->isCreditNote();

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        $root = $this->doc->createElementNS(self::NS_INVOICE, 'ubl:Invoice');
        // Echte Namensraum-Deklarationen, nicht Attribute namens "xmlns:…":
        // Sonst wiederholt DOM die Deklaration auf jedem Kindelement.
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $this->doc->appendChild($root);

        $this->cbc($root, 'CustomizationID', self::CUSTOMIZATION);
        $this->cbc($root, 'ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');
        $this->cbc($root, 'ID', (string) $invoice->number);
        $this->cbc($root, 'IssueDate', $invoice->issued_at?->toDateString() ?? now()->toDateString());
        if ($invoice->due_at !== null && ! $credit) {
            $this->cbc($root, 'DueDate', $invoice->due_at->toDateString());
        }
        $this->cbc($root, 'InvoiceTypeCode', $invoice->kind->typeCode());

        $notice = $invoice->tax_treatment->notice();
        if ($notice !== null) {
            $this->cbc($root, 'Note', $notice);
        }
        if ($invoice->intro !== null && trim($invoice->intro) !== '') {
            $this->cbc($root, 'Note', trim($invoice->intro));
        }

        $this->cbc($root, 'DocumentCurrencyCode', 'EUR');
        // BT-10 ist in der XRechnung Pflicht (BR-DE-15); ohne Leitweg-ID steht
        // die Kundennummer oder die Rechnungsnummer selbst darin.
        $this->cbc($root, 'BuyerReference', $invoice->buyer_reference ?: ($invoice->customer_number ?: (string) $invoice->number));

        if ($invoice->service_from !== null || $invoice->service_to !== null) {
            $period = $this->cac($root, 'InvoicePeriod');
            if ($invoice->service_from !== null) {
                $this->cbc($period, 'StartDate', $invoice->service_from->toDateString());
            }
            if ($invoice->service_to !== null) {
                $this->cbc($period, 'EndDate', $invoice->service_to->toDateString());
            }
        }

        if ($credit && $invoice->corrects !== null) {
            $ref = $this->cac($this->cac($root, 'BillingReference'), 'InvoiceDocumentReference');
            $this->cbc($ref, 'ID', (string) $invoice->corrects->number);
            if ($invoice->corrects->issued_at !== null) {
                $this->cbc($ref, 'IssueDate', $invoice->corrects->issued_at->toDateString());
            }
        }

        $this->supplier($root, $organisation);
        $this->customer($root, $invoice);
        $this->paymentMeans($root, $organisation);

        if ($invoice->due_at !== null && ! $credit) {
            $this->cbc($this->cac($root, 'PaymentTerms'), 'Note', __('billing.document.payment_terms', [
                'date' => $invoice->due_at->format('d.m.Y'),
            ]));
        }

        $subtotal = self::abs($invoice->subtotal(), $credit);
        $discount = self::abs($invoice->discountAmount(), $credit);
        $net = self::abs((string) $invoice->net_total, $credit);
        $tax = self::abs((string) $invoice->tax_total, $credit);
        $gross = self::abs((string) $invoice->gross_total, $credit);
        $paid = self::abs((string) $invoice->paid_amount, $credit);

        if (! Money::isZero($discount)) {
            $allowance = $this->cac($root, 'AllowanceCharge');
            $this->cbc($allowance, 'ChargeIndicator', 'false');
            $this->cbc($allowance, 'AllowanceChargeReason', __('billing.document.discount'));
            $this->amount($allowance, 'Amount', $discount);
            $this->taxCategory($allowance, $invoice, $this->dominantRate($invoice));
        }

        $taxTotal = $this->cac($root, 'TaxTotal');
        $this->amount($taxTotal, 'TaxAmount', $tax);
        foreach ($this->taxBreakdown($invoice, $credit) as $rate => $sums) {
            $subtotalNode = $this->cac($taxTotal, 'TaxSubtotal');
            $this->amount($subtotalNode, 'TaxableAmount', $sums['net']);
            $this->amount($subtotalNode, 'TaxAmount', $sums['tax']);
            $this->taxCategory($subtotalNode, $invoice, (string) $rate);
        }

        $totals = $this->cac($root, 'LegalMonetaryTotal');
        $this->amount($totals, 'LineExtensionAmount', $subtotal);
        $this->amount($totals, 'TaxExclusiveAmount', $net);
        $this->amount($totals, 'TaxInclusiveAmount', $gross);
        if (! Money::isZero($discount)) {
            $this->amount($totals, 'AllowanceTotalAmount', $discount);
        }
        if (! Money::isZero($paid)) {
            $this->amount($totals, 'PrepaidAmount', $paid);
        }
        $this->amount($totals, 'PayableAmount', Money::sub($gross, $paid));

        foreach ($invoice->lines as $line) {
            $this->line($root, $invoice, $line, $credit);
        }

        return (string) $this->doc->saveXML();
    }

    /** @param  array<string, mixed>  $organisation */
    private function supplier(DOMElement $root, array $organisation): void
    {
        $party = $this->cac($this->cac($root, 'AccountingSupplierParty'), 'Party');
        $name = (string) ($organisation['name'] ?? '');

        $this->cbc($this->cac($party, 'PartyName'), 'Name', $name);
        $this->address($party, (string) ($organisation['address'] ?? ''), 'DE');

        $vat = trim((string) ($organisation['vat_id'] ?? ''));
        $taxNumber = trim((string) ($organisation['tax_number'] ?? ''));
        if ($vat !== '') {
            $scheme = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', $vat);
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'VAT');
        } elseif ($taxNumber !== '') {
            $scheme = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', $taxNumber);
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'FC');
        }

        $legal = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', $name);
        $register = trim((string) ($organisation['register'] ?? ''));
        if ($register !== '') {
            $this->cbc($legal, 'CompanyID', $register);
        }

        // BR-DE-2, -5, -6, -7: Kontakt mit Name, Telefon und E-Mail.
        $contact = $this->cac($party, 'Contact');
        $this->cbc($contact, 'Name', trim((string) ($organisation['representative'] ?? '')) ?: $name);
        $this->cbc($contact, 'Telephone', trim((string) ($organisation['phone'] ?? '')) ?: '-');
        $this->cbc($contact, 'ElectronicMail', trim((string) ($organisation['email'] ?? '')) ?: '-');
    }

    private function customer(DOMElement $root, Invoice $invoice): void
    {
        $party = $this->cac($this->cac($root, 'AccountingCustomerParty'), 'Party');

        $this->cbc($this->cac($party, 'PartyName'), 'Name', (string) $invoice->customer_name);
        $this->address($party, (string) $invoice->customer_address, (string) $invoice->customer_country);

        if ($invoice->customer_vat_id !== null && trim($invoice->customer_vat_id) !== '') {
            $scheme = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', trim($invoice->customer_vat_id));
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'VAT');
        }

        $this->cbc($this->cac($party, 'PartyLegalEntity'), 'RegistrationName', (string) $invoice->customer_name);
    }

    /** @param  array<string, mixed>  $organisation */
    private function paymentMeans(DOMElement $root, array $organisation): void
    {
        $iban = preg_replace('/\s+/', '', (string) ($organisation['iban'] ?? '')) ?? '';
        $means = $this->cac($root, 'PaymentMeans');

        // 58 SEPA-Überweisung, wenn es eine IBAN gibt; sonst 30 Überweisung
        // ohne Konto -- BR-DE-1 verlangt in jedem Fall einen Zahlungsweg.
        $this->cbc($means, 'PaymentMeansCode', $iban !== '' ? '58' : '30');

        if ($iban !== '') {
            $account = $this->cac($means, 'PayeeFinancialAccount');
            $this->cbc($account, 'ID', $iban);
            $this->cbc($account, 'Name', (string) ($organisation['name'] ?? ''));
            $bic = preg_replace('/\s+/', '', (string) ($organisation['bic'] ?? '')) ?? '';
            if ($bic !== '') {
                $this->cbc($this->cac($account, 'FinancialInstitutionBranch'), 'ID', $bic);
            }
        }
    }

    private function line(DOMElement $root, Invoice $invoice, InvoiceLine $line, bool $credit): void
    {
        $node = $this->cac($root, 'InvoiceLine');
        $this->cbc($node, 'ID', (string) $line->position);

        $quantity = $this->cbc($node, 'InvoicedQuantity', self::abs(rtrim(rtrim((string) $line->quantity, '0'), '.') ?: '0', $credit, 3));
        $quantity->setAttribute('unitCode', self::unitCode((string) $line->unit));

        $this->amount($node, 'LineExtensionAmount', self::abs((string) $line->net_amount, $credit));

        $item = $this->cac($node, 'Item');
        $this->cbc($item, 'Name', mb_substr(trim($line->description), 0, 100) ?: '-');
        $this->taxCategory($item, $invoice, (string) $line->tax_rate, 'ClassifiedTaxCategory');

        $this->amount($this->cac($node, 'Price'), 'PriceAmount', self::abs((string) $line->unit_price, $credit));
    }

    private function taxCategory(DOMElement $parent, Invoice $invoice, string $rate, string $element = 'TaxCategory'): void
    {
        $category = $this->cac($parent, $element);
        $treatment = $invoice->tax_treatment;

        $this->cbc($category, 'ID', $treatment->categoryCode());
        $this->cbc($category, 'Percent', $treatment->taxesApply() ? self::percent($rate) : '0');

        if (! $treatment->taxesApply() && $element === 'TaxCategory') {
            $this->cbc($category, 'TaxExemptionReason', (string) $treatment->notice());
        }

        $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');
    }

    /**
     * Netto und Steuer je Steuersatz -- eine TaxSubtotal je Satz.
     *
     * @return array<string, array{net: string, tax: string}>
     */
    private function taxBreakdown(Invoice $invoice, bool $credit): array
    {
        $byRate = [];
        $factor = $invoice->discount_percent !== null
            ? bcsub('1', bcdiv((string) $invoice->discount_percent, '100', 6), 6)
            : '1';

        foreach ($invoice->lines as $line) {
            $rate = $invoice->tax_treatment->taxesApply() ? self::percent((string) $line->tax_rate) : '0';
            $net = Money::mul((string) $line->net_amount, $factor);
            $tax = Money::mul((string) $line->tax_amount, $factor);

            $byRate[$rate] ??= ['net' => '0.00', 'tax' => '0.00'];
            $byRate[$rate]['net'] = Money::add($byRate[$rate]['net'], self::abs($net, $credit));
            $byRate[$rate]['tax'] = Money::add($byRate[$rate]['tax'], self::abs($tax, $credit));
        }

        if ($byRate === []) {
            $byRate['0'] = ['net' => '0.00', 'tax' => '0.00'];
        }

        return $byRate;
    }

    private function dominantRate(Invoice $invoice): string
    {
        $rates = [];
        foreach ($invoice->lines as $line) {
            $rate = self::percent((string) $line->tax_rate);
            $rates[$rate] = Money::add($rates[$rate] ?? '0.00', (string) $line->net_amount);
        }
        arsort($rates);

        return (string) (array_key_first($rates) ?? '0');
    }

    private function address(DOMElement $party, string $address, string $country): void
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $address) ?: [])));
        $node = $this->cac($party, 'PostalAddress');

        // Letzte Zeile "PLZ Ort", alles davor Strasse -- die deutsche Schreibweise.
        $last = $lines !== [] ? array_pop($lines) : '';
        $postal = '';
        $city = $last;
        if (preg_match('/^(\d{4,5})\s+(.+)$/u', $last, $m) === 1) {
            $postal = $m[1];
            $city = $m[2];
        }

        if ($lines !== []) {
            $this->cbc($node, 'StreetName', array_shift($lines));
        }
        if ($lines !== []) {
            $this->cbc($node, 'AdditionalStreetName', implode(', ', $lines));
        }
        $this->cbc($node, 'CityName', $city !== '' ? $city : '-');
        $this->cbc($node, 'PostalZone', $postal !== '' ? $postal : '-');
        $this->cbc($this->cac($node, 'Country'), 'IdentificationCode', strtoupper($country !== '' ? $country : 'DE'));
    }

    // ── Helfer ───────────────────────────────────────────────────────────────

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        $node = $this->doc->createElementNS(self::NS_CAC, 'cac:'.$name);
        $parent->appendChild($node);

        return $node;
    }

    private function cbc(DOMElement $parent, string $name, string $value): DOMElement
    {
        $node = $this->doc->createElementNS(self::NS_CBC, 'cbc:'.$name);
        $node->appendChild($this->doc->createTextNode($value));
        $parent->appendChild($node);

        return $node;
    }

    private function amount(DOMElement $parent, string $name, string $value): DOMElement
    {
        $node = $this->cbc($parent, $name, Money::of($value));
        $node->setAttribute('currencyID', 'EUR');

        return $node;
    }

    /** UN/ECE Rec. 20 -- die Einheiten, die hier vorkommen. */
    private static function unitCode(string $unit): string
    {
        return match (mb_strtolower(trim($unit))) {
            'h', 'std', 'std.', 'stunde', 'stunden' => 'HUR',
            'l', 'liter' => 'LTR',
            'kg' => 'KGM',
            'g' => 'GRM',
            'm' => 'MTR',
            'pausch.', 'pauschal', 'psch' => 'LS',
            default => 'C62',
        };
    }

    private static function percent(string $rate): string
    {
        $value = rtrim(rtrim(number_format((float) str_replace(',', '.', $rate), 2, '.', ''), '0'), '.');

        return $value === '' ? '0' : $value;
    }

    private static function abs(string $value, bool $credit, int $scale = 2): string
    {
        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            return $scale === 2 ? '0.00' : '0';
        }

        return $credit && str_starts_with($value, '-') ? substr($value, 1) : $value;
    }
}
