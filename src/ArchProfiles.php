<?php

namespace ArchMcp;

/**
 * Token -> capability profile resolution.
 *
 * Added 2026-09-01. DRAFT — not deployed. Changes the MCP security
 * boundary; needs explicit sign-off on this specific diff before it
 * goes anywhere near the live server (Codegen CLI Design §8).
 *
 * Replaces ArchConfig::REPO_PATH as the thing that decides which tree a
 * caller can reach. Previously one server served one hardcoded repo to
 * anyone who knew the URL. Now the token in the request path selects a
 * profile, and the profile decides the root directory, which lanes
 * exist, and whether the session/metadata tools are offered at all.
 *
 * FAILS CLOSED, deliberately and in every direction: no token, unknown
 * token, unreadable map, malformed map, or a profile whose root does not
 * exist on disk all resolve to null, and null means the request is
 * refused. There is no default profile and there must never be one —
 * the pre-2026-09-01 default was "full access to arch-collab-core", so
 * any fallback path silently restores exactly the hole this closes.
 */
final class ArchProfiles
{
    /**
     * Token map location. MUST be outside the web root — anything under
     * public/ is one webserver misconfiguration away from being served
     * as a static file.
     *
     * EDIT THIS on deployment, same as ArchConfig::REPO_PATH was.
     */
    private const MAP_PATH = '/home/crockart/arch-mcp-secrets/tokens.json';

    /** Tokens shorter than this are rejected regardless of the map. */
    private const MIN_TOKEN_LENGTH = 32;

    /**
     * Pull the token out of a request path of the form /t/<token>/...
     *
     * Path segment rather than query string: query strings land in
     * Referer headers and are habitually logged in full, and claude.ai's
     * custom-connector field takes a bare URL with no way to set a
     * header. This is still a bearer secret in a URL and still reaches
     * the access log — see the deployment notes. The scope profile, not
     * the transport, is what limits the damage.
     */
    public static function extractToken(string $requestUri): ?string
    {
        $path = parse_url($requestUri, \PHP_URL_PATH);
        if (!\is_string($path)) {
            return null;
        }

        if (1 !== preg_match('#^/t/([A-Za-z0-9_\-]{'.self::MIN_TOKEN_LENGTH.',128})(?:/|$)#', $path, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Resolve a token to its profile, or null.
     *
     * @return array{label: string, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null}|null
     */
    public static function resolve(?string $token): ?array
    {
        if (null === $token || \strlen($token) < self::MIN_TOKEN_LENGTH) {
            return null;
        }

        $raw = @file_get_contents(self::MAP_PATH);
        if (false === $raw) {
            // Unreadable map: refuse everything. Do NOT fall back.
            return null;
        }

        $map = json_decode($raw, true);
        if (!\is_array($map)) {
            return null;
        }

        // Compare every candidate with hash_equals rather than using the
        // token as an array key, so lookup time does not vary with how
        // much of a guessed token happens to be correct.
        $found = null;
        foreach ($map as $candidate => $profile) {
            if (hash_equals((string) $candidate, $token)) {
                $found = $profile;
            }
        }

        if (!\is_array($found)) {
            return null;
        }

        return self::validate($found);
    }

    /**
     * A profile that does not fully make sense is treated as no profile.
     *
     * @param array<string, mixed> $profile
     *
     * @return array{label: string, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null}|null
     */
    private static function validate(array $profile): ?array
    {
        $label = $profile['label'] ?? null;
        $root = $profile['root'] ?? null;
        $lanes = $profile['lanes'] ?? null;

        if (!\is_string($label) || '' === $label) {
            return null;
        }
        if (!\is_string($root) || '' === $root) {
            return null;
        }
        if (!\is_array($lanes) || [] === $lanes) {
            return null;
        }

        // The root must already exist. Creating it here would let a typo
        // in the map silently mint a new empty tree instead of failing
        // loudly, and would mean a profile could point somewhere nobody
        // has ever verified.
        $realRoot = realpath($root);
        if (false === $realRoot || !is_dir($realRoot)) {
            return null;
        }

        $cleanLanes = [];
        foreach ($lanes as $name => $subdir) {
            if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                return null;
            }
            if (!\is_string($subdir) || str_contains($subdir, '..') || str_contains($subdir, "\0")) {
                return null;
            }
            $cleanLanes[$name] = trim($subdir, '/');
        }

        // Optional write allowlist. Absent => unrestricted (James's own
        // profile); present => writes must match one of these extensions.
        $writeExt = null;
        if (isset($profile['write_extensions'])) {
            if (!\is_array($profile['write_extensions'])) {
                return null;
            }
            $writeExt = [];
            foreach ($profile['write_extensions'] as $ext) {
                if (!\is_string($ext) || 1 !== preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
                    return null;
                }
                $writeExt[] = $ext;
            }
            if ([] === $writeExt) {
                return null;
            }
        }

        return [
            'label' => $label,
            'root' => $realRoot,
            'lanes' => $cleanLanes,
            'session_tools' => true === ($profile['session_tools'] ?? false),
            'write_extensions' => $writeExt,
        ];
    }
}
