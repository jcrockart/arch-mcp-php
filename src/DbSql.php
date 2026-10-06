<?php

namespace ArchMcp;

/**
 * Pure text checks on SQL, used by the database tools (DbRead, DbMigrations).
 * No database, no file access. Everything here is a REFUSAL check: nothing
 * in this class ever grants anything. The read tool also runs its query
 * inside a read-only transaction, so these checks are the first of two
 * layers, not the only one.
 */
final class DbSql
{
    /** Identifier fragments that mark secrets. Used to refuse and to redact. */
    private const SENSITIVE = '/(password|passwd|secret|token|api_?key|oauth|hash|salt|credential|private_?key)/i';

    /** Words that must not appear anywhere in a read-only statement. */
    private const FORBIDDEN_WORDS = '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|RENAME|GRANT|REVOKE|CALL|EXECUTE|LOCK|UNLOCK|HANDLER|LOAD|ATTACH|DETACH|VACUUM|REINDEX|INTO|SLEEP|BENCHMARK|LOAD_FILE|GET_LOCK|RELEASE_LOCK)\b|\bREPLACE\s+INTO\b|\bFOR\s+(UPDATE|SHARE)\b/i';

    /** Statements a read may start with. */
    private const FIRST_WORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'];

    /**
     * Replace comments and quoted strings with blanks so keyword checks
     * cannot be fooled by (or trip on) text inside them. Backtick-quoted
     * names are kept, without the backticks. Returns [text, wellFormed];
     * wellFormed is false for an unterminated string, comment or backtick.
     *
     * @param bool $backslashEscapes true for MySQL (backslash escapes a quote
     *                               inside a string), false for SQLite (it does not)
     *
     * @return array{0: string, 1: bool}
     */
    public static function blank(string $sql, bool $backslashEscapes = true): array
    {
        $out = '';
        $n = \strlen($sql);
        $i = 0;
        $ok = true;
        while ($i < $n) {
            $c = $sql[$i];
            $d = $i + 1 < $n ? $sql[$i + 1] : '';
            if (('-' === $c && '-' === $d) || '#' === $c) {
                $j = strpos($sql, "\n", $i);
                $i = false === $j ? $n : $j;
                $out .= ' ';
                continue;
            }
            if ('/' === $c && '*' === $d) {
                $j = strpos($sql, '*/', $i + 2);
                if (false === $j) {
                    $ok = false;
                    $i = $n;
                } else {
                    $i = $j + 2;
                }
                $out .= ' ';
                continue;
            }
            if ("'" === $c || '"' === $c) {
                $q = $c;
                ++$i;
                $closed = false;
                while ($i < $n) {
                    $e = $sql[$i];
                    if ('\\' === $e && $backslashEscapes) {
                        $i += 2;
                        continue;
                    }
                    if ($e === $q) {
                        if ($i + 1 < $n && $sql[$i + 1] === $q) {
                            $i += 2;
                            continue;
                        }
                        $closed = true;
                        ++$i;
                        break;
                    }
                    ++$i;
                }
                if (!$closed) {
                    $ok = false;
                }
                $out .= ' '.$q.$q.' ';
                continue;
            }
            if ('`' === $c) {
                $j = strpos($sql, '`', $i + 1);
                if (false === $j) {
                    $ok = false;
                    $i = $n;
                    $out .= ' ';
                    continue;
                }
                $out .= ' '.substr($sql, $i + 1, $j - $i - 1).' ';
                $i = $j + 1;
                continue;
            }
            $out .= $c;
            ++$i;
        }

        return [$out, $ok];
    }

    /**
     * Null if $sql is acceptable as a single read-only statement, otherwise
     * the reason it is refused.
     */
    public static function checkReadOnly(string $sql, bool $backslashEscapes = true): ?string
    {
        if ('' === trim($sql)) {
            return 'empty query';
        }
        if (str_contains($sql, '/*!') || str_contains($sql, '/*+')) {
            return 'version and optimizer comments are not allowed';
        }
        [$b, $ok] = self::blank($sql, $backslashEscapes);
        if (!$ok) {
            return 'unterminated string, comment or quoted name';
        }
        $b = trim($b);
        $b = rtrim($b, "; \t\r\n");
        if (str_contains($b, ';')) {
            return 'only one statement per call';
        }
        if (1 !== preg_match('/^\(*\s*([A-Za-z]+)/', $b, $m) || !\in_array(strtoupper($m[1]), self::FIRST_WORDS, true)) {
            return 'only SELECT, SHOW, DESCRIBE and EXPLAIN statements are allowed';
        }
        if (1 === preg_match(self::FORBIDDEN_WORDS, $b, $m)) {
            return 'not allowed in a read-only query: '.strtoupper(trim($m[0]));
        }

        return null;
    }

    /** Is this column or table name one that holds secrets? */
    public static function isSensitiveName(string $name): bool
    {
        return 1 === preg_match(self::SENSITIVE, $name);
    }

    /**
     * Names in the query that look like secrets (tables or columns).
     *
     * @return list<string>
     */
    public static function sensitiveNamesIn(string $sql, bool $backslashEscapes = true): array
    {
        [$b] = self::blank($sql, $backslashEscapes);
        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $b, $m);
        $found = [];
        foreach ($m[0] as $word) {
            if (self::isSensitiveName($word)) {
                $found[strtolower($word)] = strtolower($word);
            }
        }

        return array_values($found);
    }

    /**
     * Does a migration's SQL body contain a statement that removes or
     * rewrites existing schema or data? A refusal check only, never a grant.
     */
    public static function looksDestructive(string $sql): bool
    {
        [$b] = self::blank($sql, true);

        return 1 === preg_match(
            '/\bDROP\b|\bTRUNCATE\b|\bDELETE\s+FROM\b|\bRENAME\s+(TABLE|COLUMN|TO)\b|\bALTER\s+TABLE\b[^;]*?\b(DROP|MODIFY|CHANGE)\b/is',
            $b
        );
    }
}
