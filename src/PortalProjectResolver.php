<?php

namespace ArchMcp;

/**
 * Per-call profile resolution for the /projects and /projects-token
 * addresses (Slice 2 of claude/proposal-arch-mcp-oauth-projects-
 * connector.md, extended by claude/proposal-framework-connector-
 * consolidation.md).
 *
 * Every other address this server serves resolves its ONE profile once,
 * at request start, from a local, host-only file (ArchProfiles.php).
 * These two addresses are different by design: one bearer credential
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
 * this class's own PORTAL_DB_* deployment note and the Slice 2
 * build-log entry in the proposal doc for the exact GRANT statement
 * used.
 *
 * Same fail-closed discipline as every other resolver in this codebase:
 * unreadable config, a DB error, no matching row (project doesn't
 * exist, has no profile for this environment, or the caller isn't a
 * member — deliberately indistinguishable, same reasoning as
 * ArchProfiles' own 404-not-403 posture), or a root that doesn't exist
 * on disk all resolve to null.
 *
 * FRAMEWORK CARVE-OUT, built in here from the start (not retrofitted,
 * per the instruction that opened this slice): a project whose
 * `category` is 'framework' (arch-core, arch-mcp, arch-portal,
 * arch-bootstrap) NEVER gets session_tools or push_allowed through the
 * OAuth /projects path, no matter what project_profiles/project_members
 * says. This is enforced as an explicit, hardcoded override below — not
 * a WHERE clause a future query change could accidentally drop, and not
 * something the data model prevents on its own. A framework project
 * remains reachable here for pull/read access (an owner may still want
 * to inspect it), but never for the two capabilities that could let an
 * untrusted or lower-trust caller push code into, or run arbitrary
 * session/codegen tooling against, the very framework this server and
 * Portal are built from. EXTENDED 2026-09-27 (Confluence 49840130) to
 * also deny a locked dependency install (archDependencyInstall) for the
 * same reason — see the override below.
 *
 * EXTENDED 2026-09-27 (claude/proposal-framework-connector-consolidation.md,
 * slice 1): bootstrap_fill_allowed/bootstrap_fill_portal_url are now real
 * project_profiles columns instead of a hardcoded false/null, so
 * arch-bootstrap can fold into this address as the zero-argument case the
 * original OAuth proposal already decided it should be. This flag is
 * deliberately NOT added to the framework carve-out: it's the one
 * capability a framework project (specifically arch-bootstrap) is meant
 * to grant through this address — the carve-out exists to stop an
 * untrusted caller reaching a framework checkout's code/session tools,
 * not to block Portal's own inception-fill callback, which never touches
 * a framework checkout at all.
 *
 * EXTENDED 2026-09-28 (claude/proposal-framework-connector-consolidation.md,
 * break-glass design): $bypassFrameworkCarveOut, when true, skips the
 * carve-out entirely. The ONLY caller allowed to pass true is
 * public/index.php's /projects-token block (BreakglassAuth-gated, a
 * static secret independent of Portal's OAuth code) — see that address's
 * own docblock for why this bypass has to exist at all. The OAuth
 * /projects address must NEVER pass true here; doing so would silently
 * hand every OAuth-authenticated Portal user push/session access to this
 * server's and Portal's own source code, defeating the entire point of
 * the carve-out. There is no flag on project_profiles or anywhere in the
 * database that can turn this on — it is exclusively a call-site decision
 * made once, in one file, by one address.
 */
final class PortalProjectResolver
{
    /**
     * Deployment-time connection config for Portal's database — a
     * dedicated, read-only MySQL grant, never Portal's own app
     * credential. Same secrets-directory convention as
     * ArchProfiles::MAP_PATH/PROFILES_PATH: outside git, outside the web
     * root. EDIT THIS on deployment; staging and production each get
     * their own file pointing at their own Portal database — never
     * point arch-mcp-staging at production's database or vice versa.
     *
     * Expected content:
     *   <?php
     *   define('PORTAL_DB_HOST', 'localhost');
     *   define('PORTAL_DB_NAME', 'crockart_archportal_staging');
     *   define('PORTAL_DB_USER', '...');   // dedicated read-only grant
     *   define('PORTAL_DB_PASS', '...');
     *   define('PORTAL_DB_ENVIRONMENT', 'staging'); // or 'production'
     */
    private const CONFIG_PATH = '/home/crockart/arch-mcp-secrets/portal-db.php';

    private static ?\PDO $pdo = null;

    /**
     * @return array{label: string, kind: string, seed: bool, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null, pull_allowed: bool, pull_branch: string, push_allowed: bool, push_branch: string, db_apply_allowed: bool, bootstrap_fill_allowed: bool, bootstrap_fill_portal_url: string|null, dependency_manager: string, dependency_exclude: list<string>|null}|null
     */
    public static function resolve(string $portalUserId, string $slug, \Psr\Log\LoggerInterface $logger, bool $bypassFrameworkCarveOut = false): ?array
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

        $pdo = self::connect($logger);
        if (null === $pdo) {
            return null;
        }

        $environment = \defined('PORTAL_DB_ENVIRONMENT') ? \PORTAL_DB_ENVIRONMENT : null;
        if (!\is_string($environment) || !\in_array($environment, ['staging', 'production'], true)) {
            $logger->error('PortalProjectResolver: PORTAL_DB_ENVIRONMENT missing or invalid');

            return null;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT p.category, pp.root, pp.lanes, pp.session_tools, pp.write_extensions,
                        pp.pull_allowed, pp.pull_branch, pp.push_allowed, pp.push_branch,
                        pp.db_apply_allowed, pp.dependency_manager, pp.dependency_exclude,
                        pp.bootstrap_fill_allowed, pp.bootstrap_fill_portal_url
                 FROM projects p
                 JOIN project_members pm ON pm.project_id = p.id
                 JOIN project_profiles pp ON pp.project_id = p.id AND pp.environment = :environment
                 WHERE p.slug = :slug AND pm.user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute([
                'environment' => $environment,
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

        return self::rowToProfile($slug, $row, $bypassFrameworkCarveOut);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{label: string, kind: string, seed: bool, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null, pull_allowed: bool, pull_branch: string, push_allowed: bool, push_branch: string, db_apply_allowed: bool, bootstrap_fill_allowed: bool, bootstrap_fill_portal_url: string|null, dependency_manager: string, dependency_exclude: list<string>|null}|null
     */
    private static function rowToProfile(string $slug, array $row, bool $bypassFrameworkCarveOut = false): ?array
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
        // Deliberately NOT folded into the framework carve-out below —
        // see this class's top docblock.
        $bootstrapFillAllowed = (bool) ($row['bootstrap_fill_allowed'] ?? false);
        $bootstrapFillPortalUrl = \is_string($row['bootstrap_fill_portal_url'] ?? null) && '' !== $row['bootstrap_fill_portal_url']
            ? $row['bootstrap_fill_portal_url']
            : null;

        // FRAMEWORK CARVE-OUT — see this class's top docblock. Applied
        // here, unconditionally, after every other field has already
        // been read from the row: a framework project never grants
        // session_tools or push through the OAuth /projects address,
        // regardless of what project_profiles says. Pull/read access is
        // unaffected. Skipped ENTIRELY when $bypassFrameworkCarveOut is
        // true — see this class's top docblock ("EXTENDED 2026-09-28")
        // for the one caller allowed to pass that.
        //
        // DECIDED 2026-09-27, alongside archDependencyInstall (Confluence
        // 49840130): extended to also deny a locked dependency install for
        // framework projects (arch-core, arch-mcp, arch-portal,
        // arch-bootstrap). Rationale: this server and Portal are built FROM
        // these repos, and even a locked, network-egress-only install
        // still runs arbitrary packages' own install/postinstall scripts
        // against a framework checkout — the same "untrusted caller
        // reaches the framework itself" risk category session_tools/push
        // were already carved out for, not a materially smaller one just
        // because the install is locked.
        //
        // bootstrap_fill_allowed/bootstrap_fill_portal_url are deliberately
        // NOT zeroed here regardless of $bypassFrameworkCarveOut — see
        // this class's top docblock, "EXTENDED 2026-09-27 ... slice 1".
        if (!$bypassFrameworkCarveOut && 'framework' === ($row['category'] ?? null)) {
            $sessionTools = false;
            $pushAllowed = false;
            $dependencyManager = 'none';
            $dependencyExclude = null;
        }

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

    private static function connect(\Psr\Log\LoggerInterface $logger): ?\PDO
    {
        if (null !== self::$pdo) {
            return self::$pdo;
        }

        if (!\is_file(self::CONFIG_PATH)) {
            $logger->error('PortalProjectResolver: config file missing', ['path' => self::CONFIG_PATH]);

            return null;
        }

        require_once self::CONFIG_PATH;

        if (!\defined('PORTAL_DB_HOST') || !\defined('PORTAL_DB_NAME') || !\defined('PORTAL_DB_USER') || !\defined('PORTAL_DB_PASS')) {
            $logger->error('PortalProjectResolver: portal-db.php missing required constants');

            return null;
        }

        try {
            self::$pdo = new \PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', \PORTAL_DB_HOST, \PORTAL_DB_NAME),
                \PORTAL_DB_USER,
                \PORTAL_DB_PASS,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (\PDOException $e) {
            $logger->error('PortalProjectResolver: connection failed', ['exception' => $e->getMessage()]);

            return null;
        }

        return self::$pdo;
    }
}
