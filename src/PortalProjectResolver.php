<?php

namespace ArchMcp;

/**
 * Per-call profile resolution for the /projects, /projects-staging, and
 * /projects-token addresses (Slice 2 of claude/proposal-arch-mcp-oauth-
 * projects-connector.md, extended by claude/proposal-framework-connector-
 * consolidation.md and claude/proposal-arch-mcp-projects-staging-split.md).
 *
 * Every other address this server serves resolves its ONE profile once,
 * at request start, from a local, host-only file (ArchProfiles.php).
 * These addresses are different by design: one bearer credential
 * authenticates a Portal USER (OAuth) or a fixed break-glass identity
 * (static token) rather than a single project, and each tool call names
 * which project it wants via a `slug` argument — so the profile has to
 * be resolved fresh, per call, from Portal's own live membership data.
 *
 * DESIGN DECISION (raised with James, decided 2026-09-21): resolves by
 * querying arch-portal's database directly, over a dedicated read-only
 * MySQL grant, rather than calling a new Portal-side HTTP endpoint. This
 * is the one place in this codebase where that trade-off was made
 * deliberately the other way from ArchTools::connectProjectDb's own
 * documented principle ("this server holds no agent-readable credential
 * of its own" — it always reads the TARGET project's own config.php).
 * Here, arch-mcp-php DOES hold a standing credential for a database it
 * does not own (Portal's), scoped as tightly as MySQL grants allow:
 * SELECT only, on exactly `projects`, `project_members`,
 * `project_profiles` — never anything wider, never a write grant. See
 * this class's own PORTAL_DB_CONFIG_PATH deployment note and the Slice 2
 * build-log entry in the proposal doc for the exact GRANT statement
 * used.
 *
 * TWO DATABASES, added 2026-09-28 (claude/proposal-arch-mcp-projects-
 * staging-split.md): this class used to hold ONE standing connection,
 * to whichever single Portal database PORTAL_DB_CONFIG_PATH's
 * PORTAL_DB_ENVIRONMENT constant named — found, live, to be hardcoded to
 * 'staging' since this address launched, meaning every /projects call
 * ever made (including through production's own service-OAuth
 * credential) silently resolved against STAGING Portal's project
 * registry, never production's. Now holds up to two standing
 * connections, one per environment, read from a JSON manifest instead of
 * PHP constants (same shape choice as OAuthBearer::TRUSTED_ISSUERS_PATH),
 * and every call site MUST name which one it wants — there is no default
 * environment any more, deliberately, so this class can never again
 * silently serve the wrong database when a caller asked for the other
 * one. See public/index.php: /projects always resolves with
 * 'production', /projects-staging always with 'staging', /projects-token
 * (break-glass) always with 'production' (decided 2026-09-28 — its whole
 * reason to exist is reaching production when OAuth is broken).
 *
 * Same fail-closed discipline as every other resolver in this codebase:
 * unreadable config, an unknown/missing environment, a DB error, no
 * matching row (project doesn't exist, has no profile for this
 * environment, or the caller isn't a member — deliberately
 * indistinguishable, same reasoning as ArchProfiles' own 404-not-403
 * posture), or a root that doesn't exist on disk all resolve to null.
 * 
 * ONE PORTAL DATABASE, TWO PROFILES, added 2026-10-06: on the production
 * Portal instance a project can have up to two profile rows (environment
 * 'staging' and 'production') in the same database. Which one a call uses is
 * decided by the TOOL, never by the caller: see profilePlan(). The staging
 * Portal instance resolves its own database exactly as before.
 *
 * NO FRAMEWORK CARVE-OUT, from 2026-10-02: framework projects (arch-core,
 * arch-mcp, arch-portal, arch-bootstrap) are resolved exactly like any
 * other project. What the project_profiles row says is what the caller
 * gets. Removed at James's direction; the earlier hardcoded override
 * (session_tools, push_allowed and dependency install forced off for
 * framework projects on the OAuth addresses) and its break-glass bypass
 * flag no longer exist.
 * 
 */
final class PortalProjectResolver
{
    /**
     * Deployment-time connection config for BOTH Portal databases — two
     * dedicated, read-only MySQL grants, never Portal's own app
     * credential. Same secrets-directory convention as
     * ArchProfiles::MAP_PATH/PROFILES_PATH and
     * OAuthBearer::TRUSTED_ISSUERS_PATH: outside git, outside the web
     * root, a JSON manifest rather than PHP constants specifically so one
     * file can hold both environments' credentials at once (this server
     * has only one checkout serving both — see PROJECT-CONTEXT.md's
     * "Checkout topology" note). EDIT THIS on deployment.
     *
     * Expected content:
     *   {
     *     "staging": {"host": "localhost", "name": "crockart_archportal_staging", "user": "...", "pass": "..."},
     *     "production": {"host": "localhost", "name": "crockart_archportal", "user": "...", "pass": "..."}
     *   }
     *
     * Superseded, 2026-09-28: the old single-environment portal-db.php
     * (PORTAL_DB_HOST/NAME/USER/PASS/ENVIRONMENT constants). That file is
     * no longer read by this class at all — see this class's top
     * docblock, "TWO DATABASES".
     */
    private const CONFIG_PATH = '/home/crockart/arch-mcp-secrets/portal-db.json';

    /** @var array<string, \PDO> keyed by environment */
    private static array $pdoByEnvironment = [];

    /**
     * @param 'staging'|'production' $environment which Portal database to query — no default, every
     *                                             caller must say which one it means (see this class's
     *                                             top docblock, "TWO DATABASES")
     *
     * @return array{label: string, kind: string, seed: bool, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null, pull_allowed: bool, pull_branch: string, push_allowed: bool, push_branch: string, db_apply_allowed: bool, bootstrap_fill_allowed: bool, bootstrap_fill_portal_url: string|null, dependency_manager: string, dependency_exclude: list<string>|null}|null
     */
    public static function resolve(string $portalUserId, string $slug, \Psr\Log\LoggerInterface $logger, string $environment, string $kind = 'production'): ?array
    {
        if (1 !== preg_match('/^[0-9]+$/', $portalUserId)) {
            return null;
        }
        // Same segment charset ArchProfiles::extractAddress() uses for
        // path segments — a slug is a Portal-assigned identifier, safe
        // to interpolate nowhere (it's bound, not interpolated, below;
        // this is a defensive shape check before it ever reaches SQL).
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $slug)) {
            return null;
        }
        if (!\in_array($environment, ['staging', 'production'], true)) {
            $logger->error('PortalProjectResolver: unknown environment requested', ['environment' => $environment]);

            return null;
        }

        if (!\in_array($kind, ['staging', 'production'], true)) {
            $logger->error('PortalProjectResolver: unknown profile kind requested', ['kind' => $kind]);

            return null;
        }
        [$preferred, $allowFallback] = self::profilePlan($environment, $kind);

        $pdo = self::connect($environment, $logger);
        if (null === $pdo) {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT pp.root, pp.lanes, pp.session_tools, pp.write_extensions,
                        pp.pull_allowed, pp.pull_branch, pp.push_allowed, pp.push_branch,
                        pp.db_apply_allowed, pp.dependency_manager, pp.dependency_exclude,
                        pp.bootstrap_fill_allowed, pp.bootstrap_fill_portal_url
                 FROM projects p
                 JOIN project_members pm ON pm.project_id = p.id
                 JOIN project_profiles pp ON pp.project_id = p.id AND pp.environment IN (:env_a, :env_b)
                 WHERE p.slug = :slug AND pm.user_id = :user_id
                 ORDER BY (pp.environment = :env_c) DESC
                 LIMIT 1'
            );
            $stmt->execute([
                'env_a' => $preferred,
                'env_b' => $allowFallback ? 'production' : $preferred,
                'env_c' => $preferred,
                'slug' => $slug,
                'user_id' => (int) $portalUserId,
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $logger->error('PortalProjectResolver: query failed', ['exception' => $e->getMessage()]);

            return null;
        }

        if (false === $row) {
            // No such project, no profile for this environment, or the
            // caller isn't a member — deliberately not distinguished.
            return null;
        }

        return self::rowToProfile($slug, $row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{label: string, kind: string, seed: bool, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null, pull_allowed: bool, pull_branch: string, push_allowed: bool, push_branch: string, db_apply_allowed: bool, bootstrap_fill_allowed: bool, bootstrap_fill_portal_url: string|null, dependency_manager: string, dependency_exclude: list<string>|null}|null
     */
    private static function rowToProfile(string $slug, array $row): ?array
    {
        $root = $row['root'] ?? null;
        if (!\is_string($root) || '' === $root) {
            return null;
        }
        // Same "must already exist" rule as ArchProfiles::validate() —
        // a profile pointing at a nonexistent root fails closed rather
        // than silently creating one.
        $realRoot = realpath($root);
        if (false === $realRoot || !is_dir($realRoot)) {
            return null;
        }

        $lanesRaw = json_decode((string) $row['lanes'], true);
        if (!\is_array($lanesRaw) || [] === $lanesRaw) {
            return null;
        }
        $lanes = [];
        foreach ($lanesRaw as $name => $subdir) {
            if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                return null;
            }
            if (!\is_string($subdir) || str_contains($subdir, '..') || str_contains($subdir, "\0")) {
                return null;
            }
            $lanes[$name] = trim($subdir, '/');
        }

        $writeExt = null;
        if (null !== $row['write_extensions']) {
            $decoded = json_decode((string) $row['write_extensions'], true);
            if (!\is_array($decoded)) {
                return null;
            }
            $writeExt = [];
            foreach ($decoded as $ext) {
                if (!\is_string($ext) || 1 !== preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
                    return null;
                }
                $writeExt[] = $ext;
            }
        }

        $sessionTools = self::truthyJson($row['session_tools'] ?? null);
        $pushAllowed = (bool) ($row['push_allowed'] ?? false);
        $pullAllowed = (bool) ($row['pull_allowed'] ?? false);
        $dbApplyAllowed = (bool) ($row['db_apply_allowed'] ?? false);

        // Which locked-install tool (if any) this profile may run via
        // archDependencyInstall(), and an optional package denylist for it.
        // Added 2026-09-27 (Confluence 49840130) — same fail-closed shape as
        // write_extensions above: malformed input rejects the WHOLE
        // profile, not just this field.
        $dependencyExclude = null;
        if (null !== $row['dependency_exclude']) {
            $decoded = json_decode((string) $row['dependency_exclude'], true);
            if (!\is_array($decoded)) {
                return null;
            }
            $dependencyExclude = [];
            foreach ($decoded as $pkg) {
                // Package names span both ecosystems this feeds: Composer's
                // "vendor/name" and npm's plain or "@scope/name" shapes.
                // Looser than write_extensions' own [a-z0-9]{1,8} check for
                // that reason.
                if (!\is_string($pkg) || 1 !== preg_match('/^(@[a-z0-9-][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/i', $pkg)) {
                    return null;
                }
                $dependencyExclude[] = $pkg;
            }
        }

        $dependencyManager = \is_string($row['dependency_manager'] ?? null) ? $row['dependency_manager'] : 'none';
        if (!\in_array($dependencyManager, ['none', 'composer', 'npm'], true)) {
            $dependencyManager = 'none';
        }

        // Real project_profiles columns as of
        // db/migrations/0023_2026-09-27c-bootstrap-fill-columns.sql (see
        // claude/proposal-framework-connector-consolidation.md, slice 1).
        $bootstrapFillAllowed = (bool) ($row['bootstrap_fill_allowed'] ?? false);
        $bootstrapFillPortalUrl = \is_string($row['bootstrap_fill_portal_url'] ?? null) && '' !== $row['bootstrap_fill_portal_url']
            ? $row['bootstrap_fill_portal_url']
            : null;

         return [
            'label' => $slug,
            'kind' => 'project',
            'seed' => false,
            'root' => $realRoot,
            'lanes' => $lanes,
            'session_tools' => $sessionTools,
            'write_extensions' => $writeExt,
            'pull_allowed' => $pullAllowed,
            'pull_branch' => \is_string($row['pull_branch'] ?? null) ? $row['pull_branch'] : 'main',
            'push_allowed' => $pushAllowed,
            'push_branch' => \is_string($row['push_branch'] ?? null) ? $row['push_branch'] : 'main',
            'db_apply_allowed' => $dbApplyAllowed,
            'bootstrap_fill_allowed' => $bootstrapFillAllowed,
            'bootstrap_fill_portal_url' => $bootstrapFillPortalUrl,
            'dependency_manager' => $dependencyManager,
            'dependency_exclude' => $dependencyExclude,
        ];
    }

    /**
     * Which of a project's profile rows a tool call uses. Decided by the TOOL
     * (its $kind), never by the caller. Added 2026-10-06.
     *
     * On the production Portal instance a project can have both a 'staging'
     * and a 'production' profile row in the same database:
     *   - kind 'production' (promotion: the production pull, and the
     *     project-level assets and bootstrap-fill tools): the production row,
     *     strictly.
     *   - kind 'staging' (everything that edits or inspects the working copy:
     *     sessions, code, git reads, push, migrations, dependency install):
     *     the staging row, falling back to the production row ONLY when the
     *     project has no staging row at all. That fallback is exactly what
     *     those tools resolved to before this change, so nothing widens.
     * On the staging Portal instance nothing changes: only that database's
     * rows of environment 'staging' are ever used, so a 'production' row
     * sitting in the staging database can never be reached from there.
     *
     * @param 'staging'|'production' $portalEnvironment which Portal database the address talks to
     * @param 'staging'|'production' $kind              which checkout the tool works on
     *
     * @return array{0: string, 1: bool} [profile environment to prefer, may fall back to production]
     */
    public static function profilePlan(string $portalEnvironment, string $kind): array
    {
        if ('production' !== $portalEnvironment) {
            return [$portalEnvironment, false];
        }
        if ('staging' === $kind) {
            return ['staging', true];
        }

        return ['production', false];
    }

    /**
     * project_profiles.session_tools is a JSON column storing a bare
     * boolean (see Profiles.php: `$sessionTools ? 1 : 0` is NOT what's
     * stored here — it's json_encode'd as JSON true/false via the
     * column's JSON type). Decode defensively either way.
     */
    private static function truthyJson(mixed $raw): bool
    {
        if (\is_bool($raw)) {
            return $raw;
        }
        if (\is_string($raw)) {
            $decoded = json_decode($raw);

            return true === $decoded || 1 === $decoded || '1' === $raw;
        }

        return (bool) $raw;
    }

    /**
     * @param 'staging'|'production' $environment
     */
    private static function connect(string $environment, \Psr\Log\LoggerInterface $logger): ?\PDO
    {
        if (isset(self::$pdoByEnvironment[$environment])) {
            return self::$pdoByEnvironment[$environment];
        }

        if (!\is_file(self::CONFIG_PATH)) {
            $logger->error('PortalProjectResolver: config file missing', ['path' => self::CONFIG_PATH]);

            return null;
        }

        $raw = @file_get_contents(self::CONFIG_PATH);
        $manifest = \is_string($raw) ? json_decode($raw, true) : null;
        if (!\is_array($manifest)) {
            $logger->error('PortalProjectResolver: portal-db.json missing or malformed');

            return null;
        }

        $entry = $manifest[$environment] ?? null;
        if (!\is_array($entry) || !isset($entry['host'], $entry['name'], $entry['user'], $entry['pass'])) {
            $logger->error('PortalProjectResolver: portal-db.json has no valid entry for environment', ['environment' => $environment]);

            return null;
        }

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', (string) $entry['host'], (string) $entry['name']),
                (string) $entry['user'],
                (string) $entry['pass'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (\PDOException $e) {
            $logger->error('PortalProjectResolver: connection failed', ['environment' => $environment, 'exception' => $e->getMessage()]);

            return null;
        }

        return self::$pdoByEnvironment[$environment] = $pdo;
    }
}
