<?php

namespace ArchMcp;

/**
 * Pure rules for what a cross-project reference read may show (ARCH-80,
 * arch_ref). No files, no git, no Portal: strings in, answers out.
 *
 * Reference reads only ever see COMMITTED code on the main branch, only inside
 * the code lane's stage paths from that same commit's arch-gate.json, and
 * never a file that looks like config or a secret by name. As a last line of
 * defence a line that looks like a hard-coded secret is replaced before it
 * leaves the server. None of this is a promise that a secret cannot slip
 * through (a secret in a plain-looking line is not detected), which is why
 * results are labelled unsanitized reference material.
 */
final class RefGuard
{
    /** The committed branch every reference read uses. */
    public const REF = 'refs/heads/main';
    public const MAX_FILE_BYTES = 1048576;
    public const MAX_LINE_CHARS = 300;
    public const WITHHELD = '[line withheld: looks like a secret]';

    /** The one project whose files are meant to be copied (ARCH-94). */
    public const LIBRARY = 'arch-library';
    public const MAX_FLAGS = 20;

    private const EXTENSIONS = ['php', 'js', 'css', 'html', 'md', 'txt', 'json', 'yml', 'yaml', 'xml', 'svg', 'sql', 'py', 'sh'];
    private const SKIP_DIRS = ['vendor', 'node_modules'];

    /** A plain repo-relative path: no traversal, no empty parts, no backslash, not absolute. */
    public static function safePath(string $path): bool
    {
        if ('' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ('' === $part || '.' === $part || '..' === $part) {
                return false;
            }
        }

        return true;
    }

    /** True for a file a reference read must never show, whatever the stage paths say. */
    public static function isExcluded(string $path): bool
    {
        $parts = explode('/', $path);
        $name = strtolower((string) array_pop($parts));
        foreach ($parts as $dir) {
            if (str_starts_with($dir, '.') || \in_array(strtolower($dir), self::SKIP_DIRS, true)) {
                return true;
            }
        }
        if ('' === $name || str_starts_with($name, '.')) {
            return true;
        }
        $ext = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
        if (!\in_array($ext, self::EXTENSIONS, true)) {
            return true;
        }
        if (1 === preg_match('/^(config|configuration)([._-].*)?\.php$/', $name)) {
            return true;
        }

        return 1 === preg_match('/(secret|password|passwd|credential|private[_-]?key|tokens?\.json|profiles?\.json|portal-db|\.env)/', $name);
    }

    /**
     * The path part of the constitution's "never copy" list (Reusing code, rule 5), for
     * files in arch-library. isExcluded() already covers config.php, .htaccess and
     * secret-named files for every project; this adds the rest that a path can decide:
     * arch_node_publish.php, arch-gate.json, project.config.json, another project's
     * db/migrations/, anything under src/demos/ and the your_* stand-ins. What a path
     * cannot decide (real names, user data, wording that points back to a project) stays
     * a human check, and the content scan below only warns.
     */
    public static function neverCopy(string $path): bool
    {
        $parts = explode('/', strtolower($path));
        $name = (string) array_pop($parts);
        if (\in_array($name, ['arch_node_publish.php', 'arch-gate.json', 'project.config.json'], true) || str_starts_with($name, 'your_')) {
            return true;
        }
        $dirs = '/'.implode('/', $parts).'/';
        foreach ($parts as $dir) {
            if (str_starts_with($dir, 'your_')) {
                return true;
            }
        }

        return str_contains($dirs, '/src/demos/') || str_contains($dirs, '/db/migrations/') || str_starts_with($dirs, '/demos/');
    }

    /**
     * Whether a file of the named project may be shown: the usual rules, plus the
     * never-copy paths when the project is arch-library.
     *
     * @param list<string> $stagePaths
     */
    public static function readableIn(string $slug, string $path, array $stagePaths): bool
    {
        return self::readable($path, $stagePaths) && (self::LIBRARY !== $slug || !self::neverCopy($path));
    }

    /**
     * The content scan from the library's sanitizing rules, as warnings: private key blocks,
     * a quoted password/secret/token/api_key value of 4+ characters, a 32+ character key-like
     * string, /home/<user>/ paths, email addresses and absolute sqlite paths. One flag is one
     * line matching one rule. Advisory: a safety net, not proof that a file is clean.
     *
     * @return list<array{line: int, rule: string}>
     */
    public static function copyFlags(string $content): array
    {
        $rules = [
            'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            'secret-looking assignment' => '/(pass(word|wd)?|secret|token|api[_-]?key)s?[\"\']?\s*(=>|=|:)\s*[\"\'][^\"\']{4,}[\"\']/i',
            'long key-like string' => '/[A-Za-z0-9+\/_-]{32,}/',
            'server path' => '#/home/[A-Za-z0-9._-]+/#',
            'email address' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            'absolute sqlite path' => '#sqlite:/[^\s\'\"]+#',
        ];
        $flags = [];
        foreach (explode("\n", $content) as $i => $line) {
            foreach ($rules as $name => $re) {
                if (1 === preg_match($re, $line)) {
                    $flags[] = ['line' => $i + 1, 'rule' => $name];
                    if (\count($flags) >= self::MAX_FLAGS) {
                        return $flags;
                    }
                    break;
                }
            }
        }

        return $flags;
    }

    /**
     * @param list<string> $stagePaths code.stage.paths from the project's committed arch-gate.json
     */
    public static function inStagePaths(string $path, array $stagePaths): bool
    {
        foreach ($stagePaths as $stage) {
            $stage = trim($stage, '/');
            if ('' !== $stage && ($path === $stage || str_starts_with($path, $stage.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $stagePaths
     */
    public static function readable(string $path, array $stagePaths): bool
    {
        return self::safePath($path) && !self::isExcluded($path) && self::inStagePaths($path, $stagePaths);
    }

    /** A line that assigns a quoted literal to a secret-like name. */
    public static function looksSecret(string $line): bool
    {
        return 1 === preg_match('/(pass(word|wd)?|secret|api[_-]?key|token|bearer|private[_-]?key|credential)s?["\']?\s*(=>|=|:)\s*["\'][^"\']{6,}["\']/i', $line);
    }

    /** One line for a search hit: secret-like lines withheld, long lines cut. */
    public static function display(string $line): string
    {
        $line = rtrim($line, "\r");
        if (self::looksSecret($line)) {
            return self::WITHHELD;
        }

        return mb_strlen($line) > self::MAX_LINE_CHARS ? mb_substr($line, 0, self::MAX_LINE_CHARS).'…' : $line;
    }

    /** A whole file's text with secret-like lines withheld (line numbers unchanged). */
    public static function redactContent(string $content): string
    {
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (self::looksSecret($line)) {
                $lines[$i] = self::WITHHELD;
            }
        }

        return implode("\n", $lines);
    }
}
