<?php

namespace ArchMcp;

/**
 * Token -> capability profile resolution.
 *
 * Added and deployed 2026-09-01, with each diff reviewed by James — this
 * file defines the MCP security boundary, so changes to it need sign-off
 * on the specific change (Codegen CLI Design §8).
 *
 * Replaces ArchConfig::REPO_PATH as the thing that decides which tree a
 * caller can reach. Previously one server served one hardcoded repo to
 * anyone who knew the URL. Now a token — in the X-Api-Key header, or
 * during migration the request path — selects a profile, and the profile
 * decides the root directory, which lanes exist, and whether the
 * session/metadata tools are offered at all.
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
     * Header carrying the token.
     *
     * Was 'X-Arch-Token' until 2026-09-01. Changed because claude.ai's
     * custom-connector dialog does not accept arbitrary header names:
     * it offers a fixed list and warns that "custom header names need
     * Anthropic approval — an unapproved name shows an error". A bespoke
     * name would have required a support request, so this uses one of the
     * names already accepted.
     *
     * Still deliberately NOT "Authorization: Bearer", which is what the
     * MCP HTTP transport nominally specifies: Apache under CGI/FastCGI
     * commonly strips Authorization before PHP sees it, needing
     * CGIPassAuth or a rewrite to rebuild HTTP_AUTHORIZATION. Any X-*
     * name passes through untouched, so the original reasoning is
     * unaffected by the rename. Revisit if this ever moves to OAuth,
     * where Authorization is required rather than optional.
     *
     * The name is cosmetic to the security model: the token's scope
     * profile is what limits damage, not which header carried it.
     */
    private const TOKEN_HEADER = 'X-Api-Key';

    /**
     * CUTOVER COMPLETE 2026-09-02. A token in the URL path (/t/<token>/)
     * is no longer accepted. Every registered connector sends the header,
     * so the URL is now an identifier only and a bearer secret can no
     * longer reach an access log, a Referer header, or a shared link.
     *
     * Two independent layers enforce this, deliberately: this constant
     * makes fromRequest() return before extractToken() is ever reached,
     * and .htaccess no longer routes /t/ at all, so such a request 404s
     * at Apache without PHP running. Either alone would do; both means a
     * single edit cannot silently reopen it.
     *
     * extractToken() and its tests are retained as dead code on purpose —
     * removing them is a tidy-up, not a security fix, and it would widen
     * the diff on the file that defines the boundary. Delete them in a
     * separate change if desired.
     *
     * DO NOT set this back to true to work around a broken connector.
     * The fix for a connector that stopped working is to register it
     * correctly, not to reopen the route that put tokens in logs.
     */
    private const ALLOW_PATH_TOKEN = false;

    /**
     * Parse an MCP endpoint address out of the request path.
     *
     * Two namespaces, deliberately distinguishable at a glance:
     *
     *   /project/<name>            a dedicated space. One profile, one
     *                              fixed root, one owner.
     *   /group/<name>/<sub>        a SHARED space. The token authorises
     *                              the GROUP; the trailing segment(s)
     *                              select a sub-project inside it.
     *
     * The name is an IDENTIFIER, not a credential — safe in access logs,
     * which is why the token lives in the X-Api-Key header. The path
     * exists at all because claude.ai deduplicates connectors by URL
     * across an organisation, so every endpoint needs a distinct one.
     *
     * @return array{kind: string, name: string, sub: string|null}|null
     */
    public static function extractAddress(string $requestUri): ?array
    {
        $path = parse_url($requestUri, \PHP_URL_PATH);
        if (!\is_string($path)) {
            return null;
        }

        // A segment may not begin with a dot, so ".git" and ".." can
        // never appear here — traversal is impossible by construction
        // rather than by filtering.
        $seg = '[a-z0-9][a-z0-9._-]*';

        if (1 === preg_match('#^/project/('.$seg.')/?$#i', $path, $m)) {
            return ['kind' => 'project', 'name' => $m[1], 'sub' => null];
        }

        // Sub-projects may nest up to 4 segments. A bound rather than a
        // hard rule: unbounded depth means unbounded directory creation
        // from a URL, and nobody needs /group/family/a/b/c/d/e/f.
        if (1 === preg_match('#^/group/('.$seg.')/('.$seg.'(?:/'.$seg.'){0,3})/?$#i', $path, $m)) {
            return ['kind' => 'group', 'name' => $m[1], 'sub' => $m[2]];
        }

        return null;
    }

    /**
     * The whole authorisation decision for one request.
     *
     * Returns the profile — with 'root' resolved to the EFFECTIVE
     * directory and 'address' set to the full endpoint name — or null,
     * plus how the caller tried to authenticate, for the log. Never
     * returns or logs the token.
     *
     * @param array<string, mixed> $server
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    public static function resolveRequest(array $server): array
    {
        list($token, $via) = self::fromRequest($server);
        $profile = self::resolve($token);

        if (null === $profile) {
            return [null, $via];
        }

        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '';
        $addr = self::extractAddress($uri);

        // Bare / and the transitional /t/<token>/ route carry no address,
        // so there is nothing to check and nothing to derive.
        if (null === $addr) {
            $profile['address'] = $profile['label'];

            return [$profile, $via];
        }

        $kind = \is_string($profile['kind'] ?? null) ? $profile['kind'] : 'project';

        // A project token at a /group/ URL, or the reverse, is a
        // misconfiguration. Refuse rather than guess.
        if ($addr['kind'] !== $kind) {
            return [null, $via.'/kind-mismatch'];
        }

        // The name in the path must match the token's own profile. Not a
        // second credential — the token already decides everything — but
        // it turns a misconfigured connector into a loud failure instead
        // of silent success against the wrong tree, which is the single
        // worst outcome this server can produce.
        if (!hash_equals((string) $profile['label'], $addr['name'])) {
            return [null, $via.'/label-mismatch'];
        }

        if ('project' === $kind) {
            $profile['address'] = $profile['label'];

            return [$profile, $via];
        }

        return self::resolveGroupSubProject($profile, $addr['sub'], $via);
    }

    /**
     * Derive a group sub-project's root, seeding it if permitted.
     *
     * SEEDING IS THE POINT: anyone holding the group key can start a new
     * shared space just by naming it in the URL. It is also the hazard —
     * /group/family/calender creates an empty space rather than failing,
     * so a typo looks like a working connector with nothing in it. A
     * group may set "seed": false to accept only sub-projects that
     * already exist on disk.
     *
     * NOTE ON ISOLATION: sub-projects are NOT isolated from each other.
     * The group key is the boundary. Anyone who can reach
     * /group/family/calendar can reach /group/family/anything-else by
     * editing the URL. That is the intended sharing model, but it means a
     * group key must only be given to people trusted with the whole
     * group.
     *
     * @param array<string, mixed> $profile
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private static function resolveGroupSubProject(array $profile, ?string $sub, string $via): array
    {
        if (null === $sub || '' === $sub) {
            return [null, $via.'/no-subproject'];
        }

        $groupRoot = $profile['root'];
        $subRoot = $groupRoot.'/'.$sub;

        if (!is_dir($subRoot)) {
            if (true !== ($profile['seed'] ?? false)) {
                return [null, $via.'/unseeded'];
            }
            if (!@mkdir($subRoot, 0775, true) && !is_dir($subRoot)) {
                return [null, $via.'/seed-failed'];
            }
        }

        // Re-check containment after resolution. The segment charset
        // already makes traversal impossible, but a symlink planted
        // inside the group root is not a traversal and would otherwise
        // escape unnoticed.
        $real = realpath($subRoot);
        $realGroup = realpath($groupRoot);
        if (false === $real || false === $realGroup) {
            return [null, $via.'/unresolvable'];
        }
        if ($real !== $realGroup && 0 !== strncmp($real, $realGroup.'/', \strlen($realGroup) + 1)) {
            return [null, $via.'/escape'];
        }

        $profile['root'] = $real;
        $profile['address'] = $profile['label'].'/'.$sub;

        return [$profile, $via];
    }

    /**
     * Resolve the token from a request, preferring the header.
     *
     * @param array<string, mixed> $server
     *
     * @return array{0: string|null, 1: string} [token, "header"|"path"|"none"]
     */
    public static function fromRequest(array $server): array
    {
        $header = self::headerValue($server, self::TOKEN_HEADER);

        if (null !== $header) {
            $header = trim($header);
            if (1 === preg_match('/^[A-Za-z0-9_\-]{'.self::MIN_TOKEN_LENGTH.',128}$/', $header)) {
                return [$header, 'header'];
            }

            // A header was SENT but is malformed. Refuse outright rather
            // than falling through to the path: silently accepting a URL
            // token when the caller believed it was authenticating by
            // header would mask a real misconfiguration, and would let a
            // stale URL keep working after the header was "fixed".
            return [null, 'header'];
        }

        if (!self::ALLOW_PATH_TOKEN) {
            return [null, 'none'];
        }

        $token = self::extractToken(\is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '');

        return [$token, null === $token ? 'none' : 'path'];
    }

    /**
     * Case-insensitive header lookup.
     *
     * $_SERVER is the normal source (Apache maps X-Arch-Token to
     * HTTP_X_ARCH_TOKEN). getallheaders() is a fallback for SAPIs that
     * populate one but not the other.
     *
     * @param array<string, mixed> $server
     */
    private static function headerValue(array $server, string $name): ?string
    {
        $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
        if (isset($server[$key]) && \is_string($server[$key])) {
            return $server[$key];
        }

        if (\function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (0 === strcasecmp((string) $k, $name) && \is_string($v)) {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * Pull the token out of a request path of the form /t/<token>/...
     *
     * TRANSITIONAL. Being retired in favour of the X-Api-Key header; see
     * ALLOW_PATH_TOKEN. A path segment was chosen over a query string
     * because query strings land in Referer headers and are habitually
     * logged in full — but it is still a bearer secret in a URL and still
     * reaches the access log, which is exactly why it is going away.
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
     * Non-secret profile declarations: root/lanes/write_extensions/etc
     * for every label. Contains no bearer secrets.
     *
     * MOVED 2026-09-12 out of git, to sit beside MAP_PATH in the same
     * secrets directory (not because this file is secret — it isn't —
     * but because it is now, like tokens.json, LIVE OPERATIONAL STATE
     * rather than deployed code). It was git-tracked from 2026-09-01 to
     * 2026-09-12 specifically so a `git pull` delivered a code change to
     * this file and any profiles.json shape it newly required in one
     * atomic step. That reasoning no longer holds up once profile
     * creation itself needs to happen without a human pausing to run
     * git: validate() below already defaults every optional field
     * (session_tools, seed, kind, write_extensions), so there is no
     * code/data lockstep left to protect. What git-tracking actually cost
     * was the thing that motivated this move: a provisioning action
     * (today, mint-tokens.py --provision; eventually, arch-portal or
     * other self-service tooling) could not take effect without a
     * human-gated commit+push+pull, which does not scale past occasional
     * manual use.
     *
     * DEPLOYMENT ORDER MATTERS: this file must exist and be readable at
     * this path BEFORE this code change is promoted to prod, or every
     * profile fails closed (confirmed: a missing profiles.json makes
     * resolve() return null for every bare-label token, same as a
     * malformed one). Copy the current, live profiles.json here first,
     * verify it, THEN promote this class.
     *
     * EDIT THIS on deployment, same as MAP_PATH.
     */
    private const PROFILES_PATH = '/home/crockart/arch-mcp-secrets/profiles.json';

    public static function resolve(?string $token): ?array
    {
        if (null === $token || \strlen($token) < self::MIN_TOKEN_LENGTH) {
            return null;
        }

        $raw = @file_get_contents(self::MAP_PATH);
        if (false === $raw) {
            return null;
        }

        $map = json_decode($raw, true);
        if (!\is_array($map)) {
            return null;
        }

        $found = null;
        foreach ($map as $candidate => $entry) {
            if (hash_equals((string) $candidate, $token)) {
                $found = $entry;
            }
        }

        // New shape: tokens.json maps the token straight to a label
        // string; the profile itself lives in profiles.json. Old shape
        // (the full profile object inline) is still accepted below,
        // unchanged — this is what let the two shapes coexist in the
        // same map during the 2026-09-12 migration.
        if (\is_string($found)) {
            $found = self::lookupProfileByLabel($found);
        }

        if (!\is_array($found)) {
            return null;
        }

        return self::validate($found);
    }

    /**
     * Look up a label's profile in profiles.json.
     * Same fail-closed rule as MAP_PATH: unreadable, malformed, or
     * missing label all resolve to null.
     *
     * @return array<string, mixed>|null
     */
    private static function lookupProfileByLabel(string $label): ?array
    {
        $raw = @file_get_contents(self::PROFILES_PATH);
        if (false === $raw) {
            return null;
        }

        $profiles = json_decode($raw, true);
        if (!\is_array($profiles) || !isset($profiles[$label]) || !\is_array($profiles[$label])) {
            return null;
        }

        $profile = $profiles[$label];
        $profile['label'] = $label; // profiles.json is keyed by label; validate() expects it inline too

        return $profile;
    }

    /**
     * A profile that does not fully make sense is treated as no profile.
     *
     * @param array<string, mixed> $profile
     *
     * @return array{label: string, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null, pull_allowed: bool, pull_branch: string}|null
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
            // NOT rejected when empty. write_extensions: [] is a valid,
            // deliberate value -- "every write refused, reads/lists still
            // work" -- distinct from omitting the key entirely (null =
            // unrestricted). Fixed 2026-09-12: this used to `return null`
            // right here, which rejected the WHOLE PROFILE the instant
            // write_extensions was [], so a --read-only-provisioned
            // profile 404'd on every call, not just writes.
            // extensionAllowed() in ArchTools.php already treats []
            // correctly (every in_array() check against it is false) --
            // this was the one place upstream of that logic still wrong.
        }

        // Gated per-profile: whether this token may run the one
        // write-capable git operation this server exposes -- a hardcoded,
        // zero-caller-argument `fetch` + `merge --ff-only`. Absent/false
        // by default; only a profile explicitly provisioned with
        // --allow-pull (mint-tokens.py) carries this. See
        // archCoreGitPullFastForward() in ArchTools.php.
        $pullAllowed = true === ($profile['pull_allowed'] ?? false);

        $pullBranch = 'main';
        if (isset($profile['pull_branch'])) {
            if (!\is_string($profile['pull_branch'])
                || 1 !== preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $profile['pull_branch'])
                || str_starts_with($profile['pull_branch'], '-')
            ) {
                return null;
            }
            $pullBranch = $profile['pull_branch'];
        }

        // "project" (default) or "group". A group's root holds one
        // subdirectory per sub-project; the URL selects which.
        $kind = $profile['kind'] ?? 'project';
        if (!\in_array($kind, ['project', 'group'], true)) {
            return null;
        }

        return [
            'label' => $label,
            'kind' => $kind,
            'seed' => true === ($profile['seed'] ?? false),
            'root' => $realRoot,
            'lanes' => $cleanLanes,
            'session_tools' => true === ($profile['session_tools'] ?? false),
            'write_extensions' => $writeExt,
            'pull_allowed' => $pullAllowed,
            'pull_branch' => $pullBranch,
        ];
    }
}
