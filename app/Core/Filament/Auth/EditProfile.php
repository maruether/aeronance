<?php

declare(strict_types=1);

namespace App\Core\Filament\Auth;

use App\Core\Identity\ExternalIdentity;
use App\Core\Identity\IdentityProviderRegistry;
use App\Models\User;
use Filament\Auth\Pages\EditProfile as Base;
use Filament\Schemas\Components\Component;

/**
 * Das eigene Profil — mit derselben Regel wie in der Benutzerverwaltung.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Vorgabe: „die über einen provider kommen dürfen nur angezeigt, aber nicht
 * verändert werden."
 *
 * Das gilt HIER SOGAR MEHR ALS IN DER BENUTZERVERWALTUNG. Dort sitzt ein
 * Administrator, der weiß, woher die Konten kommen. Hier sitzt ein
 * Vereinsmitglied, das seine neue Adresse einträgt, „Speichern" drückt, eine
 * Bestätigung bekommt — und deren Adresse in derselben Nacht wieder die alte
 * ist. Es würde den Fehler nicht einmal bemerken, sondern sich wundern, warum
 * keine Mail ankommt.
 *
 * Deshalb: Name und Adresse sind bei einem Konto aus einem Provider gesperrt,
 * mit dem Hinweis, wo sie zu ändern sind. Ein gesperrtes Filament-Feld wird
 * nicht mit abgeschickt — die Sperre hält also auch gegen ein manipuliertes
 * Formular.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DAS PASSWORT BLEIBT OFFEN, und das ist kein Widerspruch: Es gehört nicht dem
 * Provider. Vereinsflieger ist ein Informations- und kein Identitätsanbieter,
 * es gibt dort kein Passwort für diese Anwendung. Wer seines ändern will, tut
 * das hier — es ist der einzige Ort dafür.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class EditProfile extends Base
{
    protected function getNameFormComponent(): Component
    {
        return $this->lockIfFromProvider(parent::getNameFormComponent());
    }

    protected function getEmailFormComponent(): Component
    {
        return $this->lockIfFromProvider(parent::getEmailFormComponent());
    }

    /**
     * Sperren, wenn der Abgleich dieses Feld führt — sonst unverändert lassen.
     */
    private function lockIfFromProvider(Component $component): Component
    {
        $provider = $this->providerLabel();

        if ($provider === null) {
            return $component;
        }

        /*
         * Die Basisklasse gibt TextInput zurueck; `disabled()` und
         * `helperText()` stammen aus deren Traits. Die Pruefung haelt den Fall
         * ab, dass eine kuenftige Filament-Fassung hier etwas anderes liefert
         * -- dann ist das Feld eben nicht gesperrt, aber die Seite bricht auch
         * nicht.
         */
        if (! method_exists($component, 'disabled') || ! method_exists($component, 'helperText')) {
            return $component;
        }

        return $component
            ->disabled()
            ->helperText(__('users.help.from_provider', ['provider' => $provider]));
    }

    /**
     * Aus welchem Provider das eigene Konto stammt — oder null.
     */
    private function providerLabel(): ?string
    {
        $user = $this->getUser();

        if (! $user instanceof User) {
            return null;
        }

        $provider = ExternalIdentity::query()
            ->where('user_id', $user->getKey())
            ->value('provider');

        if ($provider === null) {
            return null;
        }

        $registry = app(IdentityProviderRegistry::class);

        return $registry->has($provider) ? $registry->get($provider)->label() : $provider;
    }
}
