<?php

declare(strict_types=1);

namespace App\Core\Http;

use OpenSSLCertificate;

/**
 * Completes a server's certificate chain the way a browser does.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PROBLEM. Some servers send only their own certificate and leave out the
 * intermediate that links it to a trusted root. Browsers paper over this by
 * "AIA chasing": the server certificate names, in its Authority Information
 * Access extension, a URL where the issuing intermediate can be fetched. A
 * strict client -- Guzzle, curl, OpenSSL -- does NOT do this, so verification
 * fails with "unable to get local issuer certificate", and the only fix used to
 * be a certificate file dropped in by hand. Schempp-Hirth's server is exactly
 * this case, and they do not answer requests to fix it.
 *
 * WHAT THIS DOES. The same chasing, once, cached:
 *
 *   1. If the host already verifies against the system roots, do nothing.
 *   2. Otherwise read the server certificate (unverified -- this step only
 *      discovers the AIA URL, and its result is verified in step 5).
 *   3. Fetch the intermediate named there.
 *   4. Build system-roots + intermediate into one bundle.
 *   5. THE SECURITY CHECK: open a FULLY VERIFIED connection with that bundle.
 *      It succeeds only if the chain reaches a trusted system root AND covers
 *      this host. A forged intermediate that does not chain to a real root
 *      fails here and is thrown away. This is what makes AIA chasing safe: the
 *      fetched intermediate is never trusted on its own word -- it only earns
 *      trust by completing a chain to a root the system already trusts.
 *
 * WHAT IT IS NOT. It never disables verification for a real request. The one
 * unverified socket is the discovery in step 2, and nothing that comes back
 * from it is used except to find a URL -- everything fetched is then verified.
 * A man in the middle there can only cause the whole thing to fail, never to
 * trust something it should not.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class CertificateChainResolver
{
    /**
     * How long a resolved bundle is reused before it is checked again.
     *
     * Intermediates change on the order of years. A month keeps the cost near
     * zero while still noticing a rotation within a sensible window; a stale
     * bundle also self-corrects, because a request that fails verification with
     * it triggers a fresh resolve.
     */
    private const CACHE_DAYS = 30;

    private const TIMEOUT = 15;

    public function __construct(private readonly string $cacheDir) {}

    /**
     * A CA bundle path that completes this host's chain, or null when the host
     * needs none (already valid) or cannot be completed (so the caller keeps
     * the strict default and the failure stays visible).
     */
    public function bundleFor(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        $cache = $this->cachePath($host);

        if (is_file($cache) && filemtime($cache) > time() - self::CACHE_DAYS * 86400) {
            return $cache;
        }

        // 1. Already trusted? Then there is nothing to complete, and adding a
        //    bundle would only narrow what the system already accepts.
        if ($this->verifies($host, $port, true)) {
            return null;
        }

        // 2. The server certificate, and the URL it names for its issuer.
        $leaf = $this->peerCertificate($host, $port);

        if ($leaf === null) {
            return null;
        }

        $issuerUrl = $this->caIssuerUrl($leaf);

        if ($issuerUrl === null) {
            return null;
        }

        // 3. The intermediate. Fetched over plain HTTP on purpose -- see below;
        //    it is verified cryptographically before it is ever used.
        $intermediate = $this->fetchCertificate($issuerUrl);

        if ($intermediate === null) {
            return null;
        }

        $roots = $this->systemRoots();

        if ($roots === null) {
            return null;
        }

        // 4 & 5. Write the combined bundle, then require a VERIFIED connection
        //        with it. Only a chain that reaches a trusted root survives.
        return $this->writeIfItVerifies($host, $port, $cache, $roots."\n".$intermediate."\n");
    }

    /**
     * Writes the bundle only if it produces a fully verified connection.
     *
     * The bundle is written to a temporary file, tested, and only then moved
     * into place -- so a concurrent reader never sees a half-written or
     * unverified bundle, and a bundle that fails the check leaves nothing
     * behind.
     */
    private function writeIfItVerifies(string $host, int $port, string $cache, string $bundle): ?string
    {
        if (! is_dir($this->cacheDir) && ! @mkdir($this->cacheDir, 0750, true) && ! is_dir($this->cacheDir)) {
            return null;
        }

        $temp = tempnam($this->cacheDir, 'chain');

        if ($temp === false) {
            return null;
        }

        file_put_contents($temp, $bundle);
        chmod($temp, 0640);

        if (! $this->verifies($host, $port, $temp)) {
            // The completed chain still does not reach a trusted root. Whatever
            // the AIA pointed at, it is not the missing link -- refuse it.
            @unlink($temp);

            return null;
        }

        if (! @rename($temp, $cache)) {
            @unlink($temp);

            return null;
        }

        return $cache;
    }

    /**
     * Whether the host verifies with the given trust setting.
     *
     * The hostname is checked, not just the chain -- verify_peer_name with the
     * SNI name is the difference between "some valid certificate" and "a valid
     * certificate FOR THIS HOST".
     *
     * @param  string|bool  $verify  a bundle path, or true for the system default
     */
    private function verifies(string $host, int $port, string|bool $verify): bool
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ];

        if (is_string($verify)) {
            $ssl['cafile'] = $verify;
        }

        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $ssl]),
        );

        if ($client === false) {
            return false;
        }

        fclose($client);

        return true;
    }

    /**
     * The server's own certificate, WITHOUT verifying it.
     *
     * This is the one deliberately-unverified connection, and it is safe because
     * its only output is the AIA URL -- the intermediate that URL yields is then
     * put through a fully verified connection before anything trusts it. An
     * attacker here can substitute a certificate, but the substituted chain will
     * not verify in step 5, so all they achieve is a failed resolve.
     */
    private function peerCertificate(string $host, int $port): ?OpenSSLCertificate
    {
        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ]]),
        );

        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;

        return $cert instanceof OpenSSLCertificate ? $cert : null;
    }

    /**
     * The "CA Issuers" URL from the certificate's AIA extension.
     *
     * Only http/https is accepted -- the AIA can in principle name ldap, which
     * is not a fetch this does. The URL comes from the (unverified) certificate,
     * so it is treated as untrusted input and validated as a plain http(s) URL
     * before any request is made to it.
     */
    private function caIssuerUrl(OpenSSLCertificate $cert): ?string
    {
        $parsed = openssl_x509_parse($cert);
        $aia = $parsed['extensions']['authorityInfoAccess'] ?? '';

        if (! is_string($aia) || preg_match('#CA Issuers\s*-\s*URI:(\S+)#', $aia, $m) !== 1) {
            return null;
        }

        return preg_match('#^https?://#i', $m[1]) === 1 ? $m[1] : null;
    }

    /**
     * The intermediate as PEM, whether the server hands out DER or PEM.
     *
     * Fetched over whatever the AIA named -- usually plain HTTP, and that is
     * fine. The certificate is public data, not a secret, and it is verified
     * cryptographically before use: tampering with it in transit makes the
     * verification in step 5 fail, it cannot make a false certificate trusted.
     */
    private function fetchCertificate(string $url): ?string
    {
        $data = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => self::TIMEOUT, 'follow_location' => 1, 'max_redirects' => 3],
        ]));

        if (! is_string($data) || $data === '') {
            return null;
        }

        if (str_contains($data, '-----BEGIN CERTIFICATE-----')) {
            return openssl_x509_read($data) !== false ? trim($data) : null;
        }

        // DER: wrap the raw bytes as PEM and confirm OpenSSL can read them.
        $pem = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($data), 64, "\n")
            .'-----END CERTIFICATE-----';

        return openssl_x509_read($pem) !== false ? $pem : null;
    }

    /**
     * The system's trusted roots, as a file's contents.
     *
     * openssl_get_cert_locations() reports the path OpenSSL was built to use, so
     * this follows the same store the rest of the system trusts rather than
     * guessing a distribution path. If it is a directory rather than a file
     * (capath-style), there is nothing to concatenate and the resolver bows out,
     * leaving the strict default and a visible error.
     */
    private function systemRoots(): ?string
    {
        $file = openssl_get_cert_locations()['default_cert_file'] ?? null;

        if (is_string($file) && is_file($file)) {
            $contents = file_get_contents($file);

            return $contents !== false ? $contents : null;
        }

        return null;
    }

    private function cachePath(string $host): string
    {
        $safe = preg_replace('/[^A-Za-z0-9.-]/', '_', $host) ?? 'host';

        return rtrim($this->cacheDir, '/').'/'.$safe.'.pem';
    }
}
