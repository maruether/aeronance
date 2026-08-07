<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Http\CertificateChainResolver;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Completing a broken certificate chain -- the parts that can be checked
 * without a live server.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * The resolution itself needs a real server with a real broken chain, and that
 * is verified against Schempp-Hirth by hand -- a fresh install logs in with no
 * manual certificate file. What CAN be pinned down here is the surrounding
 * behaviour: a fresh cache is reused without touching the network, a rubbish
 * host is refused rather than probed, and the security promise that a bundle is
 * only ever kept once a VERIFIED connection has accepted it.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CertificateChainResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/ca-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function a_fresh_cached_bundle_is_reused_without_the_network(): void
    {
        // A bundle already resolved for this host. If the resolver went to the
        // network here it would be slow at best and, on a host that does not
        // resolve, would hang -- the whole point of the cache is that it does
        // not. A believable path proves it never opened a socket: an
        // unroutable host with a cache file present must still return instantly.
        $host = 'nonexistent.invalid';
        $cache = $this->dir.'/'.$host.'.pem';
        File::put($cache, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");

        $resolver = new CertificateChainResolver($this->dir);

        $this->assertSame($cache, $resolver->bundleFor('https://'.$host.'/x'));
    }

    #[Test]
    public function a_url_without_a_host_yields_null(): void
    {
        $resolver = new CertificateChainResolver($this->dir);

        $this->assertNull($resolver->bundleFor('not-a-url'));
        $this->assertNull($resolver->bundleFor(''));
    }

    #[Test]
    public function a_host_that_cannot_be_reached_returns_null_not_an_exception(): void
    {
        // .invalid never resolves (RFC 2606). The resolver must give up quietly
        // and hand back null so the caller keeps the strict default -- a failure
        // to COMPLETE a chain is not a licence to weaken one.
        $resolver = new CertificateChainResolver($this->dir);

        $this->assertNull($resolver->bundleFor('https://nothing-here.invalid/login'));

        // And nothing was written: a host it could not resolve leaves no bundle.
        $this->assertSame([], glob($this->dir.'/*.pem') ?: []);
    }
}
