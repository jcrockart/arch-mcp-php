<?php

namespace ArchMcp;

require_once __DIR__.'/CodeRead.php';
require_once __DIR__.'/RefGuard.php';
require_once __DIR__.'/RefRepo.php';

/**
 * Reads one committed file (or a line range of it) from another project for
 * reference (ARCH-80, arch_ref). Same rules as RefSearch: main branch only,
 * inside the committed stage paths, never an excluded file, secret-like lines
 * withheld. A refusal for an unreadable path is deliberately one generic
 * message, so it does not say whether the file exists. For arch-library the
 * never-copy paths are refused the same way and the file is scanned for
 * `copy_warnings` (ARCH-94).
 */
final class RefRead
{
    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array<string, mixed>
     */
    public static function read(callable $git, string $slug, string $path, int $start = 0, int $end = 0): array
    {
        $stage = RefRepo::hasRef($git) ? RefRepo::stagePaths($git) : null;
        $content = null !== $stage && RefGuard::readableIn($slug, $path, $stage) ? RefRepo::show($git, $path) : null;
        if (null === $content) {
            return ['success' => false, 'error' => "cannot read '{$path}' from '{$slug}': not a readable committed code file there"];
        }
        $start = max(1, $start);
        if ($end <= 0) {
            $end = $start + CodeRead::DEFAULT_LINES - 1;
        }

        $out = ['project' => $slug, 'tag' => RefGuard::LIBRARY === $slug ? 'library' : 'project', 'path' => $path, 'ref' => 'main']
            + CodeRead::slice(RefGuard::redactContent($content), $start, $end);
        if (RefGuard::LIBRARY === $slug) {
            // Copy only from the library, in your own session, and record where it came from.
            $out['copy_note'] = 'Library file. Copy it into your own session with the code tools, never link to it. In metadata/notes/ record the function slug, manifest version, status, date copied, where each file went and any local changes. Not enforced by the tool: real names, user data and wording that points back to a project; check those yourself.';
            $flags = RefGuard::copyFlags($content);
            if ([] !== $flags) {
                $out['copy_warnings'] = $flags;
            }
        }

        return $out;
    }
}
