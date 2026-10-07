<?php

namespace ArchMcp;

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
 */
final class ProjectInfo
{
    /**
     * @param array<string, mixed>      $staging    profile the staging-kind tools resolve to
     * @param array<string, mixed>|null $production profile the production-kind tools resolve to, or null if none
     *
     * @return array<string, mixed>
     */
    public static function describe(string $slug, string $portalEnvironment, array $staging, ?array $production): array
    {
        $out = [
            'success' => true,
            'slug' => $slug,
            'portal_environment' => $portalEnvironment,
            'staging' => self::view($staging),
            'production' => null === $production ? null : self::view($production),
        ];
        $out['same_checkout'] = null !== $production && self::checkoutDir($staging) === self::checkoutDir($production);
        if ('production' === ($staging['profile_environment'] ?? null)) {
            $out['staging_view_is_production'] = true;
            $out['staging_note'] = 'this project has no staging profile row, so the staging view is the production profile; the staging database tools are not available for it';
        }
        if (null === $production) {
            $out['note'] = 'no production profile row resolves for this project from this address';
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
        $view['code_stage_paths'] = self::stagePaths((string) ($p['root'] ?? ''));
        $view['git'] = self::gitState($dir);
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
