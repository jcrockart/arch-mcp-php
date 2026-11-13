<?php

namespace ArchMcp;

require_once __DIR__.'/RefGuard.php';
require_once __DIR__.'/RefRepo.php';

/**
 * The listing behind arch_ref action `list` for arch-library (ARCH-94): one
 * call that returns, for every function, slug, name, summary, status, version,
 * requires, tags, sanitizedOn and its files (path and role), instead of a text
 * search for `"slug":` and a read of each function.json.
 *
 * Reads the committed manifests on main (never a working copy), by the rules in
 * the library's content/function-manifest.md: `src/functions/<slug>/function.json`
 * with a slug equal to the folder name, name, summary, version (digits
 * major.minor.patch), status (draft, reviewed, approved, deprecated),
 * sanitizedOn, and at least one file whose path exists inside the folder with a
 * role from the closed list. Unknown keys are ignored. A folder whose manifest
 * is invalid is not listed as a function; it comes back under `problems` with
 * the reasons, as on the library site's "Needs attention" list.
 *
 * A listed file that arch_ref will not show (a never-copy path such as
 * arch_node_publish.php, arch-gate.json, config values, src/demos/ or a
 * your_* stand-in) carries `not_copyable: true`.
 *
 * `refs` lists src/refs/*.md guides with each file's title and its read-when line.
 */
final class RefLibrary
{
    private const STATUSES = ['draft', 'reviewed', 'approved', 'deprecated'];
    private const ROLES = ['php', 'js', 'css', 'html', 'sql', 'other'];
    private const FUNCTIONS = 'src/functions';
    private const REFS = 'src/refs';

    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return array<string, mixed>
     */
    public static function list(callable $git): array
    {
        if (!RefRepo::hasRef($git)) {
            return ['success' => false, 'error' => 'arch-library has no committed main branch to list'];
        }
        $stage = RefRepo::stagePaths($git);
        if (null === $stage || !RefGuard::inStagePaths(self::FUNCTIONS.'/x', $stage)) {
            return ['success' => false, 'error' => 'arch-library has no src/functions folder in its committed code stage paths'];
        }

        $names = RefRepo::allNames($git, self::FUNCTIONS);
        $byFolder = [];
        foreach ($names as $n) {
            $rest = substr($n, \strlen(self::FUNCTIONS) + 1);
            $slash = strpos($rest, '/');
            if (false !== $slash) {
                $byFolder[substr($rest, 0, $slash)][] = $n;
            }
        }
        ksort($byFolder);

        $functions = [];
        $problems = [];
        foreach ($byFolder as $folder => $paths) {
            $manifestPath = self::FUNCTIONS.'/'.$folder.'/function.json';
            $text = \in_array($manifestPath, $paths, true) ? RefRepo::show($git, $manifestPath) : null;
            $m = null === $text ? null : json_decode($text, true);
            if (!\is_array($m)) {
                $problems[] = ['folder' => $folder, 'reasons' => [null === $text ? 'no readable function.json' : 'function.json is not valid JSON']];
                continue;
            }
            $reasons = self::check((string) $folder, $m, $paths);
            if ([] !== $reasons) {
                $problems[] = ['folder' => $folder, 'reasons' => $reasons];
                continue;
            }
            $functions[] = self::entry($stage, (string) $folder, $m);
        }

        return [
            'success' => true,
            'project' => RefGuard::LIBRARY,
            'tag' => 'library',
            'function_count' => \count($functions),
            'functions' => $functions,
            'problems' => $problems,
            'refs' => self::refs($git, $stage),
            'note' => 'Read a function with arch_ref action read, scope arch-library, path src/functions/<slug>/function.json (usage, securityNotes) before copying. Status draft means a curator has not reviewed it: tell the person.',
        ];
    }

    /**
     * @param array<string, mixed> $m
     * @param list<string>         $paths every committed path in the function's folder
     *
     * @return list<string>
     */
    private static function check(string $folder, array $m, array $paths): array
    {
        $r = [];
        if (1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $folder)) {
            $r[] = 'folder name is not lowercase letters, digits and single hyphens';
        }
        if (($m['slug'] ?? null) !== $folder) {
            $r[] = 'slug must equal the folder name';
        }
        foreach (['name', 'summary', 'sanitizedOn'] as $k) {
            if (!\is_string($m[$k] ?? null) || '' === trim($m[$k])) {
                $r[] = "{$k} is required (a non-empty string)";
            }
        }
        if (!\is_string($m['version'] ?? null) || 1 !== preg_match('/^\d+\.\d+\.\d+$/', $m['version'])) {
            $r[] = 'version must be major.minor.patch, digits only';
        }
        if (!\in_array($m['status'] ?? null, self::STATUSES, true)) {
            $r[] = 'status must be one of '.implode(', ', self::STATUSES);
        }
        foreach (['tags', 'requires'] as $k) {
            if (isset($m[$k]) && (!\is_array($m[$k]) || array_filter($m[$k], static fn ($v): bool => !\is_string($v)) !== [])) {
                $r[] = "{$k} must be a list of strings";
            }
        }
        $files = $m['files'] ?? null;
        if (!\is_array($files) || [] === $files) {
            $r[] = 'files must list at least one file';

            return $r;
        }
        foreach ($files as $i => $f) {
            $path = \is_array($f) && \is_string($f['path'] ?? null) ? $f['path'] : null;
            if (null === $path || !RefGuard::safePath($path)) {
                $r[] = 'files['.$i.'].path must be a plain path inside the function folder';
                continue;
            }
            if (!\in_array(self::FUNCTIONS.'/'.$folder.'/'.$path, $paths, true)) {
                $r[] = "listed file '{$path}' does not exist in the folder";
            }
            if (!\in_array($f['role'] ?? null, self::ROLES, true)) {
                $r[] = "role of '{$path}' must be one of ".implode(', ', self::ROLES);
            }
        }

        return $r;
    }

    /**
     * @param list<string>         $stage
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    private static function entry(array $stage, string $folder, array $m): array
    {
        $files = [];
        foreach ($m['files'] as $f) {
            $full = self::FUNCTIONS.'/'.$folder.'/'.$f['path'];
            $one = ['path' => $f['path'], 'role' => $f['role']];
            if (!RefGuard::readableIn(RefGuard::LIBRARY, $full, $stage)) {
                $one['not_copyable'] = true;
            }
            $files[] = $one;
        }

        return [
            'slug' => $m['slug'],
            'name' => $m['name'],
            'summary' => $m['summary'],
            'status' => $m['status'],
            'version' => $m['version'],
            'requires' => array_values($m['requires'] ?? []),
            'tags' => array_values($m['tags'] ?? []),
            'sanitizedOn' => $m['sanitizedOn'],
            'files' => $files,
        ];
    }

    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     * @param list<string>                                                                  $stage
     *
     * @return list<array{path: string, title: ?string, read_when: ?string}>
     */
    private static function refs(callable $git, array $stage): array
    {
        $out = [];
        foreach (RefRepo::allNames($git, self::REFS) as $path) {
            if (!str_ends_with($path, '.md') || 'README.md' === basename($path) || !RefGuard::readableIn(RefGuard::LIBRARY, $path, $stage)) {
                continue;
            }
            $text = RefRepo::show($git, $path);
            $title = null;
            $when = null;
            foreach (null === $text ? [] : explode("\n", $text) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                if (null === $title) {
                    $title = ltrim($line, "# \t");
                    continue;
                }
                $when = mb_substr($line, 0, 300);
                break;
            }
            $out[] = ['path' => $path, 'title' => $title, 'read_when' => $when];
        }

        return $out;
    }
}
