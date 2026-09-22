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
 * Same fail-closed discipline as ArchProfiles::resolve(): a missing
 * header, a malformed one, a bad signature, an expired token, a token
 * without the required scope, or an unreadable/missing public key all
 * resolve to null, and null means the request is refused. Never logs or
 * echoes the raw token.
 *
 * DEPLOYMENT: this class reads Portal's OAuth RSA *public* key from a
 * path outside git and outside the web root — same secrets directory as
 * ArchProfiles::MAP_PATH/PROFILES_PATH. The key itself is not a secret
 * (it is the PUBLIC half of the keypair arch-portal signs tokens with)
 * but it is host-specific deployment state, copied by hand from
 * arch-portal's storage/oauth/public.key — see this class's own
 * PUBLIC_KEY_PATH constant and the Slice 2 build-log entry in the
 * proposal doc for the exact copy command. EDIT PUBLIC_KEY_PATH on
 * deployment if the secrets directory ever moves.
 */
final class OAuthBearer
{
    /**
     * Portal's OAuth RSA public key. Deployment-time constant, same
     * discipline as ArchProfiles::MAP_PATH — EDIT THIS if the secrets
     * directory ever moves. Not committed to git; copied by hand once
     * per environment (staging reads staging's key, prod reads prod's —
     * never cross the two, since a staging-signed token must not verify
     * against production or vice versa).
     */
    private const PUBLIC_KEY_PATH = '/home/crockart/arch-mcp-secrets/oauth-public.key';

    /**
     * Path to a one-line file holding THIS deployment's Portal issuer
     * URL — the value the /oauth-protected-resource/projects metadata
     * route (public/index.php) advertises as `authorization_servers`.
     * Same "copied/edited by hand once per environment, never
     * committed" discipline as PUBLIC_KEY_PATH just above: staging's
     * file holds staging Portal's URL (https://staging-portal.crockart.
     * com.au), production's file holds production Portal's URL.
     *
     * Deliberately NOT a git-tracked ArchConfig-style constant. That
     * exact mistake was already made and caught once in this project —
     * see claude/proposal-portal-first-project-inception.md's
     * 2026-09-21c entry: staging and production are two live checkouts
     * of the SAME git history (staging pushes to origin, production
     * fast-forward-pulls from it), so a single hardcoded value can't
     * safely differ between them. This file lives outside git for the
     * same reason PUBLIC_KEY_PATH does.
     */
    private const ISSUER_URL_PATH = '/home/crockart/arch-mcp-secrets/oauth-issuer-url.txt';

    /** The one scope every /projects tool call requires. */
    private const REQUIRED_SCOPE = 'mcp';

    /**
     * Verify a bearer JWT and return its claims, or null on any failure
     * at all (deliberately undifferentiated to the caller — see this
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

        $publicKey = @file_get_contents(self::PUBLIC_KEY_PATH);
        if (false === $publicKey || '' === trim($publicKey)) {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key($publicKey, 'RS256'));
        } catch (ExpiredException|SignatureInvalidException|\UnexpectedValueException|\DomainException|\InvalidArgumentException $e) {
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
     * This deployment's Portal issuer URL, for the RFC 9728 protected-
     * resource metadata document — or null if ISSUER_URL_PATH is
     * missing/empty, which public/index.php treats as a deploy-time
     * misconfiguration (fails loudly, not with an empty advertised
     * issuer list).
     */
    public static function portalIssuer(): ?string
    {
        $issuer = @file_get_contents(self::ISSUER_URL_PATH);
        if (false === $issuer || '' === trim($issuer)) {
            return null;
        }

        return trim($issuer);
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
