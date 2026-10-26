<?php

namespace ArchMcp;

/**
 * What arch_project_info says about a project's database, per environment
 * (added 2026-10-13), so a chat does not have to guess where the database is:
 * the engine, and for SQLite the file's place inside the project folder.
 *
 * Source: that checkout's config.php, which is git-ignored and different for
 * staging and production. It is READ AS TEXT and never run: running it would
 * define constants for the whole process (see DbConnect, which does that on
 * purpose and only once). Only DB_ENGINE and DB_PATH are looked at. No other
 * setting, in particular no host, user or password, is ever read or returned.
 *
 * Rules:
 *   - DB_ENGINE missing means mysql, the same assumption DbConnect makes.
 *   - For mysql nothing but the engine is returned.
 *   - For sqlite the file is returned relative to the checkout (for example
 *     data/app.sqlite), never as an absolute path. If it is outside the
 *     checkout, or DB_PATH is not a plain string (or __DIR__ plus a plain
 *     string), `file` is null with a note. Never a guess.
 *   - `exists` says whether the file is there.
 *
 *   - `how_app_opens` (added 2026-10-26, ARCH-68) is fixed text telling a build
 *     chat how app code opens the database: from config.php, never a typed
 *     path. It is the same for every project and holds no project value.
 *
 * Pure apart from reading that one file and checking the database file exists.
 * argument() and literal() are public so ProjectMail reads config.php the same way.
 */
final class ProjectDatabase
{
    private const HOW_SQLITE = "Open the database from the DB_PATH constant in config.php, never from a path typed into the code. config.php is at the project root, beside public/, so from a file directly in public/: require dirname(__DIR__) . '/config.php'; new PDO('sqlite:' . DB_PATH); Portal provisions config.php (git-ignored, one per environment): do not create or edit it. The database tools use the same constant, so the app and the tools always see the same file. Details: Confluence AW page 58818561.";

    private const HOW_OTHER = "Read the connection settings from the constants in config.php, never type them into the code. config.php is at the project root, beside public/; Portal provisions it (git-ignored, one per environment): do not create or edit it. The database tools use the same settings. Details: Confluence AW page 58818561.";

    /**
     * @return array{engine: ?string, file: ?string, exists: ?bool, settings_file?: string, note?: string}
     */
    public static function forCheckout(string $dir): array
    {
        $none = ['engine' => null, 'file' => null, 'exists' => null];
        $config = $dir.'/config.php';
        if ('' === $dir || !is_file($config)) {
            return $none + ['note' => 'no config.php in this checkout, so no database is configured here; Portal creates config.php when it provisions a database for this environment'];
        }
        $text = (string) file_get_contents($config);

        $engineArg = self::argument($text, 'DB_ENGINE');
        if (null === $engineArg && str_contains($text, 'DB_ENGINE')) {
            // Mentioned, but not as a define() this can read (a const, say): do not
            // fall back to the mysql default, which would be a guess.
            return $none + ['note' => 'config.php sets DB_ENGINE in a form that cannot be read safely, so the engine is not shown'];
        }
        if (null === $engineArg) {
            $engine = 'mysql';
        } else {
            $engine = self::literal($engineArg);
            if (null === $engine || 1 !== preg_match('/^[a-z0-9_-]{1,20}$/', $engine)) {
                return $none + ['note' => 'config.php sets DB_ENGINE in a form that cannot be read safely, so the engine is not shown'];
            }
        }
        if ('sqlite' !== $engine) {
            return ['engine' => $engine, 'file' => null, 'exists' => null, 'note' => 'the connection settings are in config.php and are not shown', 'how_app_opens' => self::HOW_OTHER];
        }

        $out = ['engine' => 'sqlite', 'file' => null, 'exists' => null, 'settings_file' => 'config.php', 'how_app_opens' => self::HOW_SQLITE];
        $pathArg = self::argument($text, 'DB_PATH');
        if (null === $pathArg) {
            return $out + ['note' => str_contains($text, 'DB_PATH')
                ? 'DB_PATH is set in a form that cannot be read safely, so the file is not shown'
                : 'config.php says sqlite but defines no DB_PATH'];
        }
        $root = realpath($dir);
        $root = false === $root ? rtrim($dir, '/') : $root;

        $literal = self::literal($pathArg);
        if (null !== $literal) {
            $absolute = $literal;
        } else {
            $relative = self::dirRelative($pathArg);
            if (null === $relative) {
                return $out + ['note' => 'DB_PATH is set in a form that cannot be read safely, so the file is not shown'];
            }
            $absolute = $root.'/'.ltrim($relative, '/');
        }

        $out['exists'] = is_file($absolute);
        if (!str_starts_with($absolute, $root.'/')) {
            return $out + ['note' => 'the database file is outside the project folder'];
        }
        $relative = substr($absolute, \strlen($root) + 1);
        foreach (explode('/', $relative) as $segment) {
            if ('..' === $segment || '.' === $segment) {
                return $out + ['note' => 'DB_PATH is not a plain path inside the project folder, so the file is not shown'];
            }
        }
        $out['file'] = $relative;

        return $out;
    }

    /** The raw second argument of `define('NAME', ...);` at the start of a line or after a `;`, or null. */
    public static function argument(string $text, string $name): ?string
    {
        if (1 !== preg_match('/(?:^|;)[ \t]*define\(\s*([\'"])'.preg_quote($name, '/').'\1\s*,\s*(.+?)\s*\)\s*;/m', $text, $m)) {
            return null;
        }

        return $m[2];
    }

    /** A plain quoted string with nothing to evaluate in it, or null. */
    public static function literal(string $arg): ?string
    {
        if (1 === preg_match('/^\'([^\'\\\\]*)\'$/', $arg, $m)) {
            return $m[1];
        }
        if (1 === preg_match('/^"([^"\\\\$]*)"$/', $arg, $m)) {
            return $m[1];
        }

        return null;
    }

    /** The string part of `__DIR__ . 'text'`, or null. */
    private static function dirRelative(string $arg): ?string
    {
        if (1 === preg_match('/^__DIR__\s*\.\s*\'([^\'\\\\]*)\'$/', $arg, $m)) {
            return $m[1];
        }
        if (1 === preg_match('/^__DIR__\s*\.\s*"([^"\\\\$]*)"$/', $arg, $m)) {
            return $m[1];
        }

        return null;
    }
}
