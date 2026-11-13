<?php

namespace ArchMcp;

/**
 * Commit-time check for a project whose src/bootstrap.php loads its class
 * files by hand (ARCH-85, from the ttxmas-2026 retrospective).
 *
 * THE MISTAKE IT CATCHES: a new class file (GameView.php) was added to src/
 * but never required from src/bootstrap.php. `php -l` and the metadata
 * validator both passed, because the file is valid on its own; the page that
 * used the class stopped mid-render, and stayed that way for seven versions.
 *
 * THE CHECK: every file under src/ that declares a class, interface, trait or
 * enum must be reachable from src/bootstrap.php through require/include
 * statements (bootstrap may load a file that loads more). Comments do not
 * count, so a commented-out require_once is still a failure. It is a static
 * text check; nothing is executed.
 *
 * WHY THIS ONE AND NOT "RENDER THE HOME PAGE": it needs no web server, no
 * database and no URL, runs in milliseconds inside the commit, and cannot be
 * fooled by a page that renders fine without the class today.
 *
 * WHEN IT DOES NOT APPLY (the step is then left out of the commit, not
 * passed): no src/bootstrap.php, or a bootstrap that registers an autoloader
 * (spl_autoload_register or vendor/autoload.php), where files need no
 * require. arch-mcp itself has no src/bootstrap.php.
 */
final class ClassFileCheck
{
    public const STEP = 'bootstrap-requires';

    private const MAX_LISTED = 20;

    public static function applies(string $repoPath): bool
    {
        $file = rtrim($repoPath, '/').'/src/bootstrap.php';
        if (!is_file($file)) {
            return false;
        }

        return 1 !== preg_match('#spl_autoload_register|vendor/autoload\.php#', self::code((string) file_get_contents($file)));
    }

    /**
     * @return array{ok: bool, output: string}
     */
    public static function run(string $repoPath): array
    {
        $src = rtrim($repoPath, '/').'/src';
        if (!self::applies($repoPath)) {
            return ['ok' => true, 'output' => "{$src}: not applicable\n"];
        }

        $reachable = self::reachable($src);
        $missing = [];
        foreach (self::phpFiles($src) as $rel) {
            if ('bootstrap.php' === $rel || isset($reachable[$rel])) {
                continue;
            }
            $declared = self::declaredTypes((string) file_get_contents($src.'/'.$rel));
            if ([] !== $declared) {
                $missing[$rel] = $declared;
            }
        }

        if ([] === $missing) {
            return ['ok' => true, 'output' => 'every class file in src/ is loaded by src/bootstrap.php'."\n"];
        }

        $out = "src/bootstrap.php does not load these class files, so any page that uses them stops part way through rendering:\n";
        $n = 0;
        foreach ($missing as $rel => $types) {
            if (++$n > self::MAX_LISTED) {
                $out .= '  ... and '.(\count($missing) - self::MAX_LISTED)." more\n";
                break;
            }
            $out .= "  - src/{$rel} (".implode(', ', $types).")\n";
        }
        $out .= "Add a require_once for each to src/bootstrap.php, for example:\n";
        $first = array_key_first($missing);
        $out .= "  require_once __DIR__ . '/{$first}';\n";

        return ['ok' => false, 'output' => $out];
    }

    /**
     * Paths (relative to src/) of the files reachable from bootstrap.php.
     *
     * @return array<string, true>
     */
    private static function reachable(string $src): array
    {
        $known = array_flip(self::phpFiles($src));
        $seen = [];
        $queue = ['bootstrap.php'];
        while ([] !== $queue) {
            $rel = array_shift($queue);
            $code = self::code((string) file_get_contents($src.'/'.$rel));
            foreach (self::requiredStrings($code) as $lit) {
                foreach (array_keys($known) as $cand) {
                    if (isset($seen[$cand]) || 'bootstrap.php' === $cand) {
                        continue;
                    }
                    if ($lit === $cand || str_ends_with($lit, '/'.$cand)) {
                        $seen[$cand] = true;
                        $queue[] = $cand;
                    }
                }
            }
        }

        return $seen;
    }

    /**
     * String literals used by require/include statements in this code.
     *
     * @return list<string>
     */
    private static function requiredStrings(string $code): array
    {
        $out = [];
        if (false !== preg_match_all('/\b(?:require|include)(?:_once)?\b([^;]*);/', $code, $stmts)) {
            foreach ($stmts[1] as $stmt) {
                if (false !== preg_match_all('/([\'"])(.*?)\1/', $stmt, $lits)) {
                    foreach ($lits[2] as $lit) {
                        $out[] = ltrim(str_replace('\\', '/', $lit), './');
                    }
                }
            }
        }

        return $out;
    }

    /** PHP source with comments removed, so a commented-out require does not count. */
    private static function code(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $t) {
            if (\is_array($t)) {
                if (\T_COMMENT === $t[0] || \T_DOC_COMMENT === $t[0]) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    /**
     * "class Foo", "interface Bar" ... declared in this file (not Foo::class, not `new class`).
     *
     * @return list<string>
     */
    private static function declaredTypes(string $php): array
    {
        $tokens = array_values(array_filter(token_get_all($php), static fn ($t): bool => !\is_array($t) || !\in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
        $kinds = [\T_CLASS => 'class', \T_INTERFACE => 'interface', \T_TRAIT => 'trait'];
        if (\defined('T_ENUM')) {
            $kinds[\T_ENUM] = 'enum';
        }
        $found = [];
        foreach ($tokens as $i => $t) {
            if (!\is_array($t) || !isset($kinds[$t[0]])) {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            if (\is_array($prev) && \in_array($prev[0], [\T_DOUBLE_COLON, \T_NEW], true)) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            if (\is_array($name) && \T_STRING === $name[0]) {
                $found[] = $kinds[$t[0]].' '.$name[1];
            }
        }

        return $found;
    }

    /**
     * Every .php file under src/, as paths relative to src/ with forward slashes.
     *
     * @return list<string>
     */
    private static function phpFiles(string $src): array
    {
        if (!is_dir($src)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && 'php' === $f->getExtension()) {
                $out[] = str_replace('\\', '/', substr($f->getPathname(), \strlen($src) + 1));
            }
        }
        sort($out);

        return $out;
    }
}
