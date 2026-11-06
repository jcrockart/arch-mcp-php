<?php

namespace ArchMcp;

require_once __DIR__.'/RefGuard.php';

/**
 * Reads a project's COMMITTED code through git, never from the working copy,
 * so an open session's uncommitted edits can never show up in a reference read
 * (ARCH-80, arch_ref). Every function takes a `$git` callable (list of
 * arguments in; exit_code, stdout, stderr out) that runs git in the project's
 * code lane folder, as Git::in() makes. Paths are relative to that folder
 * (the "./" prefix on `show` keeps that true for a lane in a subfolder).
 */
final class RefRepo
{
    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     */
    public static function hasRef(callable $git): bool
    {
        return 0 === $git(['rev-parse', '--verify', '--quiet', RefGuard::REF])['exit_code'];
    }

    /**
     * code.stage.paths from the arch-gate.json committed on main, or null when
     * the file or the list is missing or unreadable.
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return list<string>|null
     */
    public static function stagePaths(callable $git): ?array
    {
        $r = $git(['show', RefGuard::REF.':./arch-gate.json']);
        if (0 !== $r['exit_code']) {
            return null;
        }
        $gate = json_decode($r['stdout'], true);
        $paths = \is_array($gate) ? ($gate['code']['stage']['paths'] ?? null) : null;
        if (!\is_array($paths)) {
            return null;
        }
        $out = [];
        foreach ($paths as $p) {
            if (\is_string($p) && '' !== trim($p, '/') && !str_contains($p, '..') && !str_contains($p, "\0")) {
                $out[] = trim($p, '/');
            }
        }

        return $out;
    }

    /**
     * Readable files committed on main under the stage paths, sorted.
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     * @param list<string>                                                                  $stagePaths
     *
     * @return list<string>
     */
    public static function files(callable $git, array $stagePaths): array
    {
        if ([] === $stagePaths) {
            return [];
        }
        $r = $git(array_merge(['ls-tree', '-r', '--name-only', '-z', RefGuard::REF, '--'], $stagePaths));
        if (0 !== $r['exit_code']) {
            return [];
        }
        $files = [];
        foreach (explode("\0", $r['stdout']) as $file) {
            if ('' !== $file && RefGuard::readable($file, $stagePaths)) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * One file's text as committed on main, or null when it is missing, too
     * large, binary or the path is not a plain relative path.
     *
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     */
    public static function show(callable $git, string $path): ?string
    {
        if (!RefGuard::safePath($path)) {
            return null;
        }
        $r = $git(['show', RefGuard::REF.':./'.$path]);
        if (0 !== $r['exit_code'] || \strlen($r['stdout']) > RefGuard::MAX_FILE_BYTES || str_contains($r['stdout'], "\0")) {
            return null;
        }

        return $r['stdout'];
    }
}
