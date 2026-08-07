<?php

declare(strict_types=1);

namespace App\Core\Identity;

use RuntimeException;

/**
 * Welche Connectoren vorhanden sind.
 *
 * Dasselbe Muster wie SourceRegistry und TypeCertificateRegistry: Der Kern
 * kennt die Schnittstelle, die Module tragen sich ein. Ist keiner eingetragen,
 * bleibt das lokale Login -- und genau das ist die Anforderung, dass der Kern
 * ohne jedes Modul lauffähig sein muss.
 */
final class IdentityProviderRegistry
{
    /** @var array<string, IdentityProvider> */
    private array $providers = [];

    public function register(IdentityProvider $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    /** @return array<string, IdentityProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function get(string $name): IdentityProvider
    {
        return $this->providers[$name]
            ?? throw new RuntimeException(sprintf('Kein Identity-Provider "%s".', $name));
    }
}
