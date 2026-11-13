<?php

namespace ArchMcp;

require_once __DIR__.'/ProjectUrls.php';
require_once __DIR__.'/ProjectDatabase.php';
require_once __DIR__.'/ProjectMail.php';
require_once __DIR__.'/DeployedVersion.php';
require_once __DIR__.'/LiveEvents.php';

/**
 * One-call project summary, added 2026-10-06 (arch_project_info): what a
 * chat needs to know before it starts work, without piecing it together
 * from status, list and log calls.
 *
 * Reports, for the STAGING profile (what edit/read tools use) and the
 * PRODUCTION profile (what pull/assets use): lanes, flags, write types,
 * the code lane's stage paths, the checkout's branch/HEAD/cleanliness and
 * any active session. Absolute server paths are never included.
 * `same_checkout` says whether the two profiles share one checkout (a
 * project with no staging row), in which case `staging` and `production`
 * describe the same directory.
 *
 * Read-only. Never fetches or contacts origin (arch_prod_state does that).
 *
 * Output size (ARCH-88): by default the production view lists only what
 * differs from the staging view, plus the always-useful keys (profile_environment,
 * git, deployed, live_events, session); `production_same_as_staging` names the
 * keys left out. `verbose` returns the full views and the raw environment notes.
 * The notes are split into titled sections in the default form.
 */
final class ProjectInfo
{
    /** Keys kept in the production view even when they equal staging's. */
    private const ALWAYS = ['profile_environment', 'git', 'deployed', 'live_events', 'session'];

    /** What the database rules are, once, instead of in every tool description (ARCH-87). */
    public const DB_RULES = "Who may use which database tool is decided by the caller's role on the project, not by db_apply_allowed. db_read_staging, db_pending_staging and db_migrate_staging work on staging for contributors and above. db_read_prod and db_pending_prod are read-only checks on production for the project Owner; db_migrate_prod is the Owner's too, and a destructive production migration is confirmed only by Portal. db_apply_allowed in a profile governs the old per-profile apply and is not what allows or refuses these tools. To change staging DATA once (a backfill), write a migration file in db/migrations with `-- id:` and `-- type:` headers and run it with db_migrate_staging; there is no tool for a loose UPDATE. A data change that must reach production goes the same way, as a migration.";

    /**
     * @param array<string, mixed>      $staging    profile the staging-kind tools resolve to
     * @param array<string, mixed>|null $production profile the production-kind tools resolve to, or null if none
     * @param array{text: ?string, truncated: bool, unavailable: bool}|null $notes the project's environment notes (ProjectNotes), or null if not read
     * @param array{environments: ?array<string, array<string, mixed>>, unavailable: bool}|null $hostFacts the project's host facts (ProjectHostFacts), or null if not read
     * @param array{environments: ?array<string, array<string, mixed>>, unavailable: bool}|null $liveEvents the project's live-events state (LiveEvents), or null if not read
     * @param bool $verbose return the full production view and the raw environment notes
     *
     * @return array<string, mixed>
     */
    public static function describe(string $slug, string $portalEnvironment, array $staging, ?array $production, ?array $notes = null, ?array $hostFacts = null, ?array $liveEvents = null, bool $verbose = false): array
    {
        $out = [
            'success' => true,
            'slug' => $slug,
            'portal_environment' => $portalEnvironment,
            'staging' => self::view($staging),
            'production' => null === $production ? null : self::view($production),
            'urls' => ProjectUrls::forCheckout(self::checkoutDir($staging)),
            'environment_notes' => $verbose ? ($notes['text'] ?? null) : self::sections($notes['text'] ?? null),
            'db_rules' => self::DB_RULES,
        ];
        foreach (['staging', 'production'] as $k) {
            if (null === $out[$k]) {
                continue;
            }
            $env = $out[$k]['profile_environment'] ?? $k;
            $out[$k]['live_events'] = $liveEvents['environments'][$env] ?? null;
        }
        if (true === ($liveEvents['unavailable'] ?? false)) {
            $out['live_events_unavailable'] = true;
        }
        if (true === ($notes['truncated'] ?? false)) {
            $out['environment_notes_truncated'] = true;
        }
        if (true === ($notes['unavailable'] ?? false)) {
            $out['environment_notes_unavailable'] = true;
        }
        // Machine-collected host facts (Portal), kept apart from the hand-written environment_notes above.
        $out['host_facts'] = $hostFacts['environments'] ?? null;
        if (true === ($hostFacts['unavailable'] ?? false)) {
            $out['host_facts_unavailable'] = true;
        }
        $out['same_checkout'] = null !== $production && self::checkoutDir($staging) === self::checkoutDir($production);
        if ('production' === ($staging['profile_environment'] ?? null)) {
            $out['staging_view_is_production'] = true;
            $out['staging_note'] = 'this project has no staging profile row, so the staging view is the production profile; the staging database tools are not available for it';
        }
        if (null === $production) {
            $out['note'] = 'no production profile row resolves for this project from this address';
        }
        if (!$verbose && null !== $production) {
            [$out['production'], $out['production_same_as_staging']] = self::slim($out['staging'], $out['production'], $out['same_checkout']);
        }

        return $out;
    }

    /**
     * The production view reduced to what differs from staging, plus the
     * always keys; with one shared checkout only the keys that are per
     * environment. Returns the slim view and the list of keys left out.
     *
     * @param array<string, mixed> $staging
     * @param array<string, mixed> $production
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function slim(array $staging, array $production, bool $sameCheckout): array
    {
        $keep = $sameCheckout ? ['profile_environment', 'live_events'] : self::ALWAYS;
        $slim = [];
        $left = [];
        foreach ($production as $key => $value) {
            if (\in_array($key, $keep, true) || ($staging[$key] ?? null) !== $value) {
                $slim[$key] = $value;
            } else {
                $left[] = $key;
            }
        }

        return [$slim, $left];
    }

    /**
     * Split environment notes into titled sections: blocks separated by a blank
     * line; a first line that is a markdown heading or a short line ending in a
     * colon becomes the title. Text is passed on unchanged.
     *
     * @return list<array{title: ?string, text: string}>|null
     */
    public static function sections(?string $text): ?array
    {
        if (null === $text || '' === trim($text)) {
            return null;
        }
        $out = [];
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $block) {
            $block = trim($block);
            if ('' === $block) {
                continue;
            }
            $lines = explode("\n", $block, 2);
            $first = trim($lines[0]);
            if (1 === preg_match('/^#{1,6}\s+(.+)$/', $first, $m)) {
                $out[] = ['title' => trim($m[1]), 'text' => trim($lines[1] ?? '')];
            } elseif (isset($lines[1]) && mb_strlen($first) <= 60 && str_ends_with($first, ':')) {
                $out[] = ['title' => rtrim($first, ':'), 'text' => trim($lines[1])];
            } else {
                $out[] = ['title' => null, 'text' => $block];
            }
        }

        return $out;
    }


    /**
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    public static function view(array $p): array
    {
        $dir = self::checkoutDir($p);
        $view = [
            'profile_environment' => $p['profile_environment'] ?? null,
            'lanes' => array_keys((array) ($p['lanes'] ?? [])),
            'session_tools' => (bool) ($p['session_tools'] ?? false),
            'write_extensions' => $p['write_extensions'] ?? null,
            'pull_allowed' => (bool) ($p['pull_allowed'] ?? false),
            'pull_branch' => $p['pull_branch'] ?? 'main',
            'push_allowed' => (bool) ($p['push_allowed'] ?? false),
            'push_branch' => $p['push_branch'] ?? 'main',
            'db_apply_allowed' => (bool) ($p['db_apply_allowed'] ?? false),
            'dependency_manager' => $p['dependency_manager'] ?? 'none',
        ];
        if (\array_key_exists('site', (array) ($p['lanes'] ?? []))) {
            // Every provisioned project still gets a 'site' lane. Nothing on this
            // connector reads or writes it, so say so rather than leave a chat guessing.
            $view['lane_notes'] = ['site' => "legacy lane left over from the retired site tools; no tool on this connector reads or writes it, so ignore it (the edit tools use the 'code' lane)"];
        }
        $view['code_stage_paths'] = self::stagePaths((string) ($p['root'] ?? ''));
        $view['database'] = ProjectDatabase::forCheckout($dir);
        $view['mail'] = ProjectMail::forCheckout($dir);
        $view['git'] = self::gitState($dir);
        $view['deployed'] = DeployedVersion::forCheckout($dir);
        $view['session'] = self::session((string) ($p['root'] ?? ''));

        return $view;
    }

    /**
     * The directory git runs in: the profile root plus its core-lane subdir,
     * the same rule arch_prod_state uses.
     *
     * @param array<string, mixed> $p
     */
    public static function checkoutDir(array $p): string
    {
        $root = (string) ($p['root'] ?? '');
        $sub = (string) (($p['lanes']['core'] ?? ''));

        return '' === $sub ? $root : $root.'/'.$sub;
    }

    /**
     * @return list<string>|null null when the lane has no readable arch-gate.json stage list
     */
    public static function stagePaths(string $root): ?array
    {
        $file = $root.'/arch-gate.json';
        if ('' === $root || !is_file($file)) {
            return null;
        }
        $cfg = json_decode((string) file_get_contents($file), true);
        $paths = \is_array($cfg) ? ($cfg['code']['stage']['paths'] ?? null) : null;
        if (!\is_array($paths)) {
            return null;
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    /**
     * @return array<string, mixed>|null null when no session is active
     */
    public static function session(string $root): ?array
    {
        $file = $root.'/.arch-session.json';
        if ('' === $root || !is_file($file)) {
            return null;
        }
        $s = json_decode((string) file_get_contents($file), true);
        if (!\is_array($s)) {
            return ['active' => true, 'note' => 'session file present but unreadable'];
        }

        return [
            'active' => true,
            'lane' => $s['lane'] ?? null,
            'branch' => $s['branch'] ?? null,
            'started_at' => $s['started_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function gitState(string $dir): array
    {
        if ('' === $dir || !is_dir($dir)) {
            return ['available' => false, 'error' => 'checkout directory not found'];
        }
        $branch = self::git($dir, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $head = self::git($dir, ['rev-parse', '--short', 'HEAD']);
        $status = self::git($dir, ['status', '--porcelain']);
        if (null === $branch || null === $head || null === $status) {
            return ['available' => false, 'error' => 'not a git checkout, or git failed'];
        }
        $dirty = '' === trim($status) ? [] : array_map(static fn (string $l): string => substr($l, 3), explode("\n", rtrim($status)));

        return [
            'available' => true,
            'branch' => trim($branch),
            'head' => trim($head),
            'clean' => [] === $dirty,
            'dirty_files' => \array_slice($dirty, 0, 20),
            'dirty_count' => \count($dirty),
        ];
    }

    /**
     * @param list<string> $args
     */
    private static function git(string $dir, array $args): ?string
    {
        $cmd = array_merge(['git', '-C', $dir], $args);
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['GIT_TERMINAL_PROMPT' => '0', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp']);
        if (!\is_resource($p)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($p) && \is_string($out) ? $out : null;
    }
}
