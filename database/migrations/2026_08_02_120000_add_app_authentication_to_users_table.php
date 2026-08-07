<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zwei-Faktor-Anmeldung: das Geheimnis und die Wiederherstellungscodes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CLAUDE.md führt 2FA als Kernbestandteil ("2FA als Option im Kern"). Filament
 * bringt beides mit -- eine Authenticator-App und den E-Mail-Weg --, das Panel
 * schaltete es nur nicht ein. Es fehlte also die Zeile, nicht die Umsetzung.
 *
 * VERSCHLÜSSELT, UND ZWAR ZWINGEND. Das TOTP-Geheimnis ist kein Hash: wer es
 * liest, kann jeden Code erzeugen, den der Benutzer erzeugt -- ein
 * Datenbank-Abzug hebelte damit den zweiten Faktor für alle Konten aus. Es geht
 * deshalb über den encrypted-Cast, wie die Leitplanke es für Secrets verlangt.
 * Dasselbe gilt für die Wiederherstellungscodes, die den Faktor gerade ersetzen
 * sollen.
 *
 * text und nicht string: verschlüsselte Werte sind deutlich länger als ihr
 * Klartext, und zehn Wiederherstellungscodes als verschlüsseltes JSON sprengen
 * jede sinnvolle Spaltenbreite.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
