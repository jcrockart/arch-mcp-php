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
 *
 * tool PortalProjectResolver/ProjectsTools already implement. Since the
 * framework carve-out was removed (2026-10-02) this address resolves
 * projects exactly as /projects does; the only difference is how it
 * authenticates (one fixed identity, static secret, production only).*
 *
 */
final class BreakglassAuth
{
    /**
     * Deployment-time secret, outside git, outside the web root — same
     * secrets-directory convention as PortalProjectResolver::CONFIG_PATH
     * and ArchProfiles' own MAP_PATH/PROFILES_PATH. Deliberately a
     * SEPARATE file from the (now retired) tokens.json/profiles.json: this isn't a profile
     * (it grants no fixed lane set — every call names its own slug), and
     * mixing it into the per-label token map would have made it one accidental
     * `mint-tokens.py` edit (that script is now removed) away from being rotated, relabelled, or
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
