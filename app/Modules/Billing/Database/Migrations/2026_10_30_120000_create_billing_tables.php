<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abrechnung: Kunden, Preise, Rechnungen, Zahlungen.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * KEIN FREMDSCHLÜSSEL IN FREMDE MODULE. `holder_id` am Kunden und
 * `work_order_id` an der Rechnung sind Zahlen ohne Constraint -- so, wie die
 * Arbeitskarten das Luftfahrzeug halten. Was die Rechnung aus dem Vorgang
 * braucht, wird beim Anlegen KOPIERT (Nummer, Kennzeichen, Zeitraum): Eine
 * Rechnung bleibt lesbar, auch wenn die Arbeitskarten abgeschaltet sind, und
 * ein Prüfer in acht Jahren liest den Beleg, nicht die Verknüpfung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DIE POSITION IST EIN SNAPSHOT. Listenpreis, Rabatt, Sichtbarkeit, Netto,
 * Steuersatz -- alles steht auf der Zeile. Ändert sich die Preisliste, ändert
 * sich keine gestellte Rechnung; und der Listenpreis bleibt auch dann
 * gespeichert, wenn das Papier ihn nicht zeigt (`discount_visible`).
 *
 * DECIMAL, NICHT FLOAT, für alles, was Geld ist. Mengen mit drei Stellen,
 * weil Stunden als 1,250 kommen und Flüssigkeiten in Litern.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DER NUMMERNKREIS IST EINE EIGENE TABELLE mit einer Zeile je Art und Jahr.
 * Die Nummer wird in einer Transaktion unter Sperre vergeben -- zwei
 * gleichzeitige „Stellen" dürfen nicht dieselbe bekommen, und ein Zähler, der
 * aus den vorhandenen Rechnungen gerechnet würde, hätte nach einem
 * Soft-Delete eine Lücke zu erklären. Die Nummer ist UNIQUE, als Netz unter
 * dem Netz.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();

            /** Eine Kundennummer, falls die Werft eine führt; sonst leer. */
            $table->string('customer_number', 32)->nullable()->unique();

            $table->string('name', 160);
            $table->text('address')->nullable();
            $table->char('country', 2)->default('DE');
            $table->string('vat_id', 32)->nullable();
            $table->string('tax_number', 32)->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 64)->nullable();

            /*
             * Alles nullable: null heisst "die Vorgabe der Organisation gilt".
             * So aendert eine neue Vorgabe alle Kunden, die keine eigene
             * Abmachung haben -- und keinen, der eine hat.
             */
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('default_discount_percent', 5, 2)->nullable();
            $table->boolean('discount_visible')->nullable();
            $table->string('tax_treatment', 24)->nullable();

            /** Leitweg-ID oder Bestellnummer -- BT-10 der E-Rechnung. */
            $table->string('buyer_reference', 64)->nullable();

            /** Der Halter in der Flotte, ohne Constraint (siehe Kopf). */
            $table->unsignedBigInteger('holder_id')->nullable()->index();

            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
        });

        Schema::create('invoice_number_ranges', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['kind', 'year']);
        });

        Schema::create('part_prices', function (Blueprint $table): void {
            $table->id();

            /*
             * Geschluesselt ueber die TEILENUMMER, nicht ueber eine ID des
             * Lagers: Das Lager kennt keine Preise (E6), und die Teilenummer
             * ist die Sprache, in der Karte, Lager und Preisliste dasselbe
             * meinen. Mehrere Zeilen je Nummer mit verschiedenem valid_from
             * sind die Historie; es gilt die juengste vor dem Leistungsdatum.
             */
            $table->string('part_number', 128);
            $table->string('description')->nullable();
            $table->string('unit', 16)->default('Stk');
            $table->decimal('net_price', 12, 2);
            $table->date('valid_from');
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['part_number', 'valid_from']);
        });

        Schema::create('labour_rates', function (Blueprint $table): void {
            $table->id();

            /** Die Taetigkeitsart der Karte (TaskCards ActivityKind), als Wert. */
            $table->string('activity_kind', 32);
            $table->decimal('net_rate_per_hour', 12, 2);
            $table->date('valid_from');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['activity_kind', 'valid_from']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();

            $table->string('kind', 16)->default('invoice');
            $table->string('state', 16)->default('draft')->index();

            /** Erst beim Stellen vergeben; Entwuerfe haben keine. */
            $table->string('number', 32)->nullable()->unique();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // Der Empfaenger, wie er auf dem Beleg steht -- Snapshot (E7).
            $table->string('customer_name', 160);
            $table->text('customer_address')->nullable();
            $table->char('customer_country', 2)->default('DE');
            $table->string('customer_vat_id', 32)->nullable();
            $table->string('customer_number', 32)->nullable();
            $table->string('buyer_reference', 64)->nullable();

            // Der Vorgang, aus dem sie kam -- Referenz plus Kopie.
            $table->unsignedBigInteger('work_order_id')->nullable()->index();
            $table->string('work_order_number', 32)->nullable();
            $table->string('aircraft_registration', 32)->nullable();
            $table->date('service_from')->nullable();
            $table->date('service_to')->nullable();

            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();

            $table->string('tax_treatment', 24)->default('standard');

            /** Gesamtrabatt auf die Zwischensumme, mit derselben Sichtbarkeitswahl. */
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->boolean('discount_visible')->default(true);

            $table->decimal('net_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('gross_total', 12, 2)->default(0);

            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->date('paid_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_by_name', 160)->nullable();

            /** Gutschrift/Storno: zeigt auf das Original. */
            $table->foreignId('corrects_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();

            /** Text ueber den Positionen, Text darunter, interne Notiz (nie gedruckt). */
            $table->text('intro')->nullable();
            $table->text('note')->nullable();
            $table->text('internal_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['state', 'due_at']);
            $table->index(['customer_id', 'issued_at']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->string('origin', 16)->default('manual');
            /** Karte, Bewegung, Fremdauftrag -- als Text, wie es auf dem Beleg steht. */
            $table->string('source_reference', 64)->nullable();

            $table->text('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 16)->default('Stk');

            $table->decimal('list_unit_price', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->boolean('discount_visible')->default(true);
            /** Nach Rabatt -- was der Kunde je Einheit zahlt. */
            $table->decimal('unit_price', 12, 2)->default(0);

            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);

            /*
             * Eine Position ohne Preis blockiert das Stellen. Gesetzt vom
             * Aufbau aus dem Vorgang, wenn die Preisliste nichts hergab --
             * eine vergessene Position ist Geld, das niemand wiederholt.
             */
            $table->boolean('needs_price')->default(false);

            $table->timestamps();

            $table->index(['invoice_id', 'position']);
        });

        Schema::create('invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('method', 32)->nullable();
            $table->string('reference', 128)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('labour_rates');
        Schema::dropIfExists('part_prices');
        Schema::dropIfExists('invoice_number_ranges');
        Schema::dropIfExists('customers');
    }
};
