<?php

namespace ArchMcp;

/**
 * Per-call profile resolution for the /projects address (Slice 2 of
 * claude/proposal-arch-mcp-oauth-projects-connector.md).
 *
 * Every other address this server serves resolves its ONE profile once,
 * at request start, from a local, host-only file (ArchProfiles.php).
 * /projects is different by design: one bearer token authenticates a
 * Portal USER, not a single project, and each tool call names which
 * project it wants via a `slug` argument — so the profile has to be
 * resolved fresh, per call, from Portal's own live membership data.
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
 * arch-bootstrap) NEVER gets session_tools or push_allowed through this
 * path, no matter what project_profiles/project_members says. This is
 * enforced as an explicit, hardcoded override below — not a WHERE
 * clause a future query change could accidentally drop, and not
 * something the data model prevents on its own. A framework project
 * remains reachable here for pull/read access (an owner may still want
 * to inspect it), but never for the two capabilities that could let an
 * untrusted or lower-trust caller push code into, or run arbitrary
 * session/codegen tooling against, the very framework this server and
 * Portal are built from.
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
     * @return array{label: string, kind: string, seed: bool, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null, pull_allowed: bool, pull_branch: string, push_allowed: bool, push_branch: string, db_apply_allowed: bool, bootstrap_fill_allowed: bool, bootstrap_fill_portal_url: string|null}|null
     */
    public static function resolve(string $portalUserId, string $slug, \Psr\Log\LoggerInterface $logger): ?array
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
                        pp.db_apply_allowed
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

        return self::rowToProfile($slug, $row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{label: string, kind: string, seed: bool, root: string, lanes: array<string, string>, session_tools: bool, write_extensions: list<string>|null, pull_allowed: bool, pull_branch: string, push_allowed: bool, push_branch: string, db_apply_allowed: bool, bootstrap_fill_allowed: bool, bootstrap_fill_portal_url: string|null}|null
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

        // FRAMEWORK CARVE-OUT — see this class's top docblock. Applied
        // here, unconditionally, after every other field has already
        // been read from the row: a framework project never grants
        // session_tools or push through this address, regardless of
        // what project_profiles says. Pull/read access is unaffected.
        if ('framework' === ($row['category'] ?? null)) {
            $sessionTools = false;
            $pushAllowed = false;
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
            // Not yet stored in project_profiles (see db/schema.sql) —
            // bootstrap-fill stays profiles.json-only for now, out of
            // scope for this slice. Revisit if /projects ever needs it.
            'bootstrap_fill_allowed' => false,
            'bootstrap_fill_portal_url' => null,
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
