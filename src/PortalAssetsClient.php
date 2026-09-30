<?php

namespace ArchMcp;

use Psr\Log\LoggerInterface;

/**
 * Calls arch-portal's public/api_assets.php on behalf of the person driving
 * an OAuth-authenticated /projects or /projects-staging session. Added
 * 2026-09-30 (Confluence 51609602, "Agent Read/Write Access to Project
 * Assets via arch-projects").
 *
 * WHY: Portal's `project_assets` table is the source of truth for a
 * project's assets, so the arch_assets_* tools on the dynamic addresses
 * must read and write THAT, not a directory. Portal remains the only code
 * that writes its own database (same posture as the existing inception-fill
 * callback); this class just carries the request.
 *
 * AUTHENTICATION: forwards the caller's own Portal-issued OAuth access
 * token, in the JSON body (`access_token`), over HTTPS. Portal validates the
 * token itself (signature, expiry, revocation, scope) and applies the
 * project role checks — this server is NOT trusted to assert who the user
 * is, and cannot write assets for anyone whose token it does not hold. There
 * is therefore no shared secret between the two apps, and nothing to
 * configure here: the Portal base URL for an environment is the trusted
 * issuer URL already listed for it in OAuthBearer's trust file. A token the
 * other environment's Portal issued fails validation at this Portal, so the
 * environment split holds.
 *
 * The raw token is never logged, never returned, and is only read from the
 * live request's own Authorization header (the same header the address
 * already verified before any tool ran).
 */
final class PortalAssetsClient
{
    private const TIMEOUT_SECONDS = 20;

    /**
     * @param 'staging'|'production' $environment
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $environment,
        private readonly string $bearerToken,
    ) {
    }

    /**
     * The bearer token of the current request, or null if there is none
     * (for example on the break-glass address, which authenticates with
     * X-Api-Key instead and so can never reach Portal as a user).
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function bearerFromServer(array $server): ?string
    {
        $header = null;
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($server[$key]) && \is_string($server[$key])) {
                $header = $server[$key];
                break;
            }
        }
        if (null === $header && \function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (0 === strcasecmp((string) $k, 'Authorization') && \is_string($v)) {
                    $header = $v;
                    break;
                }
            }
        }
        if (null === $header || 1 !== preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * @param 'list'|'read'|'write' $action
     * @param array<string, mixed>  $fields extra JSON fields (name, content, on_conflict, ...)
     *
     * @return array<string, mixed> Portal's decoded JSON reply (always has `success`), or a
     *                              {success: false, error, code} array for transport problems
     */
    public function call(string $action, string $slug, array $fields = []): array
    {
        $issuers = OAuthBearer::trustedIssuerUrlsForEnvironment($this->environment);
        $base = $issuers[0] ?? null;
        if (null === $base || 1 !== preg_match('#^https://#i', $base)) {
            $this->logger->error('PortalAssetsClient: no https Portal URL for environment', ['environment' => $this->environment]);

            return ['success' => false, 'code' => 'server', 'error' => 'Portal is not reachable from this server (no trusted https issuer is configured for this environment).'];
        }
        $url = rtrim($base, '/').'/api_assets.php';

        $body = json_encode(
            ['action' => $action, 'slug' => $slug, 'access_token' => $this->bearerToken] + $fields,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (false === $body) {
            return ['success' => false, 'code' => 'invalid', 'error' => 'Could not encode the request.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $reply = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (0 !== $errno || !\is_string($reply)) {
            $this->logger->error('PortalAssetsClient: transport failure', ['environment' => $this->environment, 'curl_errno' => $errno]);

            return ['success' => false, 'code' => 'server', 'error' => 'Could not reach Portal; nothing is known to have changed.'];
        }

        $decoded = json_decode($reply, true);
        if (!\is_array($decoded) || !\array_key_exists('success', $decoded)) {
            $this->logger->error('PortalAssetsClient: unexpected reply', ['environment' => $this->environment, 'http_status' => $status]);

            return ['success' => false, 'code' => 'server', 'error' => "Portal returned an unexpected reply (HTTP {$status}); nothing is known to have changed."];
        }

        return $decoded;
    }
}
