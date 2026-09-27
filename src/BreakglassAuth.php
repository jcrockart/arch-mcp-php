<?php

namespace ArchMcp;

/**
 * Authentication for the /projects-token address — the break-glass
 * fallback decided in claude/proposal-framework-connector-consolidation.md.
 *
 * WHY THIS EXISTS: folding arch-core/arch-mcp/arch-portal into the OAuth
 * /projects connector means the code that implements Portal's own OAuth
 * authorization server becomes reachable ONLY through that same OAuth
 * layer — so a bug or outage there could lock out the one path that
 * could fix it. This address is that path: a single static bearer
 * secret (no OAuth involved at all, so it cannot be taken down by an
 * OAuth-layer bug), authenticating one fixed Portal user, reaching every
 * tool PortalProjectResolver/ProjectsTools already implement — including,
 * deliberately, the two capabilities the framework carve-out normally
 * blocks (session_tools, push_allowed) for category='framework' projects.
 * See PortalProjectResolver::resolve()'s $bypassFrameworkCarveOut
 * parameter, which this address is the ONLY caller of.
 *
 * This is real elevated access, structurally equivalent to holding SSH
 * write access to every checkout on this host — worth resenting the
 * ergonomics that make it easy to reach for out of habit rather than
 * genuine need. Same header convention as ArchProfiles' static
 * connectors (X-Api-Key), not the OAuth bearer scheme /projects uses,
 * since this must keep working when Portal's own OAuth code is what's
 * broken. Same 404-not-401 fail-closed posture as ArchProfiles: an
 * unauthenticated caller learns nothing. The token is never logged;
 * every successful use IS logged, at WARNING (not INFO) — see
 * public/index.php's /projects-token block — precisely because this
 * path should stay rare enough that every use is worth a human noticing
 * in the logs afterward.
 */
final class BreakglassAuth
{
    /**
     * Deployment-time secret, outside git, outside the web root — same
     * secrets-directory convention as PortalProjectResolver::CONFIG_PATH
     * and ArchProfiles' own MAP_PATH/PROFILES_PATH. Deliberately a
     * SEPARATE file from tokens.json/profiles.json: this isn't a profile
     * (it grants no fixed lane set — every call names its own slug), and
     * mixing it into the per-label token map would make it one accidental
     * `mint-tokens.py` edit away from being rotated, relabelled, or
     * deleted by a tool that has no idea what it's touching.
     *
     * Expected content:
     *   <?php
     *   define('BREAKGLASS_TOKEN', '...');           // long random secret, this connector's ONLY credential
     *   define('BREAKGLASS_PORTAL_USER_ID', '1');    // whichever Portal user_id this should resolve as
     */
    private const CONFIG_PATH = '/home/crockart/arch-mcp-secrets/breakglass-token.php';

    /**
     * @param array<string, mixed> $server
     */
    public static function verifyRequest(array $server): ?string
    {
        $header = self::extractHeader($server);
        if (null === $header) {
            return null;
        }

        if (!\is_file(self::CONFIG_PATH)) {
            return null;
        }

        require_once self::CONFIG_PATH;

        if (!\defined('BREAKGLASS_TOKEN') || !\defined('BREAKGLASS_PORTAL_USER_ID')) {
            return null;
        }

        $configuredToken = \BREAKGLASS_TOKEN;
        if (!\is_string($configuredToken) || '' === $configuredToken) {
            return null;
        }

        if (!hash_equals($configuredToken, $header)) {
            return null;
        }

        $portalUserId = (string) \BREAKGLASS_PORTAL_USER_ID;

        return 1 === preg_match('/^[0-9]+$/', $portalUserId) ? $portalUserId : null;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function extractHeader(array $server): ?string
    {
        $raw = $server['HTTP_X_API_KEY'] ?? null;
        if (!\is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return '' !== $trimmed ? $trimmed : null;
    }
}
