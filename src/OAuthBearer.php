<?php

namespace ArchMcp;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Bearer-JWT verification for the /projects address (Slice 2 of
 * claude/proposal-arch-mcp-oauth-projects-connector.md).
 *
 * Every OTHER address this server serves (/project/<name>, /group/<name>/
 * <sub>) is authenticated by ArchProfiles.php: a long-lived opaque token
 * in the X-Api-Key header, looked up in a local, host-only map. /projects
 * is authenticated differently, on purpose — a short-lived (1h) RS256 JWT
 * minted by arch-portal's own OAuth authorization server (Slice 1),
 * carried in the header the MCP spec actually calls for:
 * `Authorization: Bearer <jwt>`.
 *
 * MULTI-ISSUER TRUST, added 2026-09-28 (claude/note-2026-09-28-oauthbearer-
 * multi-issuer-trust.md): this class used to trust exactly ONE (issuer,
 * public key) pair at a time — see the 2026-09-22 cutover note in
 * PROJECT-CONTEXT.md, which repointed trust from staging Portal to
 * production Portal and, as a direct and expected consequence,
 * invalidated every staging-signed token. That was fine while only one
 * environment's tokens needed to verify at any given moment. It stopped
 * being fine the moment arch-portal grew a per-environment SERVICE OAuth
 * credential (src/PortalServiceCredential.php on arch-portal) that both
 * staging Portal AND production Portal each mint independently, against
 * this SAME single arch-mcp-php checkout — there is no "cutover" that
 * makes both work at once under the old one-key model.
 *
 * Now trusts a LIST of (issuer, public key) pairs, read from
 * TRUSTED_ISSUERS_PATH as a JSON array. verifyRequest() tries each
 * public key in turn until one verifies the JWT's signature, and
 * everything else about the fail-closed posture below is unchanged: any
 * single failure (missing header, malformed JWT, no key verifies it,
 * expired, wrong scope, unreadable/missing/malformed trust file) still
 * resolves to null. There is no `iss` claim on these tokens to key the
 * lookup by (league/oauth2-server's default JWT access tokens don't
 * carry one, and adding one on arch-portal's side would be a needless
 * cross-repo dependency for what a handful of RSA verify attempts
 * already solves cheaply) — trying every trusted key is simplest and
 * correct given the trust list will only ever hold a few entries
 * (staging + production Portal, today).
 *
 * DEPLOYMENT: TRUSTED_ISSUERS_PATH is host-specific deployment state,
 * outside git and outside the web root — same secrets directory as
 * ArchProfiles::MAP_PATH/PROFILES_PATH — maintained BY HAND, same
 * discipline as the single-pair file it replaces. Its shape:
 *
 *   [
 *     {"issuer": "https://staging-portal.crockart.com.au", "public_key": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n"},
 *     {"issuer": "https://portal.crockart.com.au", "public_key": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n"}
 *   ]
 *
 * Each entry's public_key is the PUBLIC half of that Portal environment's
 * own OAuth signing keypair (its storage/oauth/public.key), inlined as a
 * JSON string (embedded newlines as \n) rather than a separate file per
 * key — one file to maintain by hand instead of N. `issuer` is carried
 * only for the RFC 9728 protected-resource metadata route below
 * (advertising every trusted authorization server) — verification itself
 * tries every entry's key regardless of `issuer`, per the no-`iss`-claim
 * note above.
 */
final class OAuthBearer
{
    /**
     * The JSON manifest of every (issuer, public key) pair this
     * deployment trusts for /projects. Supersedes the old single
     * PUBLIC_KEY_PATH/ISSUER_URL_PATH pair (2026-09-21/22) — see this
     * class's own top docblock for why one pair stopped being enough.
     * Not committed to git; maintained by hand, once per host (this
     * server has only one checkout for both environments — see
     * PROJECT-CONTEXT.md's "Checkout topology" note).
     */
    private const TRUSTED_ISSUERS_PATH = '/home/crockart/arch-mcp-secrets/oauth-trusted-issuers.json';

    /** The one scope every /projects tool call requires. */
    private const REQUIRED_SCOPE = 'mcp';

    /**
     * Verify a bearer JWT against every trusted public key and return
     * its claims from whichever one verifies, or null on any failure at
     * all (deliberately undifferentiated to the caller — see this
     * class's own docblock).
     *
     * @param array<string, mixed> $server $_SERVER
     *
     * @return array{sub: string, aud: string, scopes: list<string>}|null
     */
    public static function verifyRequest(array $server): ?array
    {
        $token = self::extractToken($server);
        if (null === $token) {
            return null;
        }

        $trusted = self::loadTrustedIssuers();
        if ([] === $trusted) {
            return null;
        }

        $decoded = null;
        foreach ($trusted as $entry) {
            try {
                $decoded = JWT::decode($token, new Key($entry['public_key'], 'RS256'));
                break;
            } catch (ExpiredException|SignatureInvalidException|\UnexpectedValueException|\DomainException|\InvalidArgumentException $e) {
                // This key didn't verify it — try the next one. An
                // ExpiredException here is still worth trying other
                // keys for (a different environment's differently-timed
                // token could still be valid), and the loop naturally
                // ends in null if nothing verifies.
                continue;
            }
        }
        if (null === $decoded) {
            return null;
        }

        $claims = (array) $decoded;

        $sub = $claims['sub'] ?? null;
        $aud = $claims['aud'] ?? null;
        $scopesRaw = $claims['scopes'] ?? null;

        if (!\is_string($sub) || '' === $sub || 1 !== preg_match('/^[0-9]+$/', $sub)) {
            return null;
        }
        if (!\is_string($aud) || '' === $aud) {
            return null;
        }
        if (!\is_array($scopesRaw)) {
            return null;
        }

        $scopes = [];
        foreach ($scopesRaw as $scope) {
            if (\is_string($scope)) {
                $scopes[] = $scope;
            }
        }
        if (!\in_array(self::REQUIRED_SCOPE, $scopes, true)) {
            return null;
        }

        return ['sub' => $sub, 'aud' => $aud, 'scopes' => $scopes];
    }

    /**
     * Every trusted issuer URL, for the RFC 9728 protected-resource
     * metadata document's `authorization_servers` array (which the spec
     * defines as a list precisely for this multi-AS case) — or an empty
     * array if TRUSTED_ISSUERS_PATH is missing/empty/malformed, which
     * public/index.php treats as a deploy-time misconfiguration (fails
     * loudly, not with an empty advertised issuer list silently accepted
     * as normal).
     *
     * @return list<string>
     */
    public static function trustedIssuerUrls(): array
    {
        return array_values(array_map(static fn (array $e): string => $e['issuer'], self::loadTrustedIssuers()));
    }

    /**
     * @return list<array{issuer: string, public_key: string}>
     */
    private static function loadTrustedIssuers(): array
    {
        $raw = @file_get_contents(self::TRUSTED_ISSUERS_PATH);
        if (false === $raw || '' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $trusted = [];
        foreach ($decoded as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $issuer = $entry['issuer'] ?? null;
            $publicKey = $entry['public_key'] ?? null;
            if (\is_string($issuer) && '' !== $issuer && \is_string($publicKey) && '' !== trim($publicKey)) {
                $trusted[] = ['issuer' => $issuer, 'public_key' => $publicKey];
            }
        }

        return $trusted;
    }

    /**
     * Pull the bearer token out of the Authorization header.
     *
     * Unlike ArchProfiles::TOKEN_HEADER (X-Api-Key, chosen specifically
     * because CGI/FastCGI commonly strips Authorization before PHP sees
     * it — see that class's own docblock), /projects is bound to real
     * OAuth, where the MCP spec requires Authorization: Bearer. This
     * address's .htaccess/deployment MUST ensure HTTP_AUTHORIZATION
     * actually reaches PHP (CGIPassAuth, or an equivalent rewrite) — a
     * missing header here that turns out to be an Apache stripping
     * problem, not a missing-credential problem, is exactly the
     * scenario ArchProfiles.php's docblock already warned this move
     * would need revisiting for.
     *
     * @param array<string, mixed> $server
     */
    private static function extractToken(array $server): ?string
    {
        $header = null;

        if (isset($server['HTTP_AUTHORIZATION']) && \is_string($server['HTTP_AUTHORIZATION'])) {
            $header = $server['HTTP_AUTHORIZATION'];
        } elseif (isset($server['REDIRECT_HTTP_AUTHORIZATION']) && \is_string($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            // Some Apache/CGI configurations only preserve the header
            // under this REDIRECT_ prefix after an internal rewrite.
            $header = $server['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (\function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (0 === strcasecmp((string) $k, 'Authorization') && \is_string($v)) {
                    $header = $v;
                    break;
                }
            }
        }

        if (null === $header) {
            return null;
        }

        $header = trim($header);
        if (1 !== preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }

        return $m[1];
    }
}
