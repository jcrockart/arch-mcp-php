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
 * message, so it does not say whether the file exists.
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
        $content = null !== $stage && RefGuard::readable($path, $stage) ? RefRepo::show($git, $path) : null;
        if (null === $content) {
            return ['success' => false, 'error' => "cannot read '{$path}' from '{$slug}': not a readable committed code file there"];
        }
        $start = max(1, $start);
        if ($end <= 0) {
            $end = $start + CodeRead::DEFAULT_LINES - 1;
        }

        return ['project' => $slug, 'tag' => 'project', 'path' => $path, 'ref' => 'main']
            + CodeRead::slice(RefGuard::redactContent($content), $start, $end);
    }
}
