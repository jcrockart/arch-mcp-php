<?php

namespace ArchMcp;

require_once __DIR__.'/ValidateCrash.php';

/**
 * Small helpers that give decoded JSON the meaning it had in the Python
 * validator this code replaces (truthiness, membership, iteration), so the
 * port accepts and rejects the same files.
 *
 * JSON is decoded with objects as \stdClass and arrays as PHP arrays. That is
 * what keeps `{}` and `[]` apart: the validator accepts `{}` as "no fields"
 * and rejects `[]`. A PHP array is therefore always a JSON list here.
 */
final class PyValue
{
    /** Python truthiness: None, False, 0, 0.0, "", [] and {} are false. */
    public static function truthy(mixed $v): bool
    {
        if (null === $v || false === $v || 0 === $v || 0.0 === $v || '' === $v || [] === $v) {
            return false;
        }
        if ($v instanceof \stdClass) {
            return [] !== (array) $v;
        }

        return true;
    }

    public static function has(\stdClass $o, string $key): bool
    {
        return \array_key_exists($key, (array) $o);
    }

    public static function get(\stdClass $o, string $key, mixed $default = null): mixed
    {
        $a = (array) $o;

        return \array_key_exists($key, $a) ? $a[$key] : $default;
    }

    /** @return list<string> */
    public static function keys(\stdClass $o): array
    {
        return array_map('strval', array_keys((array) $o));
    }

    /** @return list<array{0: string, 1: mixed}> */
    public static function items(\stdClass $o): array
    {
        $out = [];
        foreach ((array) $o as $k => $v) {
            $out[] = [(string) $k, $v];
        }

        return $out;
    }

    /** Python's type(value).__name__ for a decoded JSON value. */
    public static function typeName(mixed $v): string
    {
        return match (true) {
            null === $v => 'NoneType',
            \is_bool($v) => 'bool',
            \is_int($v) => 'int',
            \is_float($v) => 'float',
            \is_string($v) => 'str',
            \is_array($v) => 'list',
            default => 'dict',
        };
    }

    /** Python str() of a decoded JSON value, for messages. */
    public static function str(mixed $v): string
    {
        if (null === $v) {
            return 'None';
        }
        if (\is_bool($v)) {
            return $v ? 'True' : 'False';
        }
        if (\is_string($v)) {
            return $v;
        }
        if (\is_float($v)) {
            return floor($v) === $v && abs($v) < 1e15 ? sprintf('%.1f', $v) : (string) $v;
        }
        if (\is_array($v)) {
            return '['.implode(', ', array_map([self::class, 'repr'], $v)).']';
        }
        if ($v instanceof \stdClass) {
            $parts = [];
            foreach (self::items($v) as [$k, $x]) {
                $parts[] = self::repr($k).': '.self::repr($x);
            }

            return '{'.implode(', ', $parts).'}';
        }

        return (string) $v;
    }

    /** Python repr() for the cases messages need: strings are quoted. */
    public static function repr(mixed $v): string
    {
        return \is_string($v) ? "'{$v}'" : self::str($v);
    }

    /**
     * Python's repr of a sorted list of strings, e.g. ['a', 'b'].
     *
     * @param list<string> $strings
     */
    public static function pyList(array $strings): string
    {
        $strings = array_values($strings);
        sort($strings, \SORT_STRING);

        return '['.implode(', ', array_map([self::class, 'repr'], $strings)).']';
    }

    /**
     * What `for x in value` visits: list items, the characters of a string, or
     * the keys of an object. Null when Python could not iterate it at all.
     *
     * @return list<mixed>|null
     */
    public static function iterate(mixed $v): ?array
    {
        if (\is_array($v)) {
            return array_values($v);
        }
        if (\is_string($v)) {
            return '' === $v ? [] : (preg_split('//u', $v, -1, \PREG_SPLIT_NO_EMPTY) ?: str_split($v));
        }
        if ($v instanceof \stdClass) {
            return self::keys($v);
        }

        return null;
    }

    /**
     * A key under which equal Python values (1, 1.0 and True are equal) share
     * one entry. Null when the value is unhashable (a list or an object).
     */
    public static function hashKey(mixed $v): ?string
    {
        if (null === $v) {
            return 'n';
        }
        if (\is_bool($v)) {
            return 'i:'.(int) $v;
        }
        if (\is_int($v)) {
            return 'i:'.$v;
        }
        if (\is_float($v)) {
            return is_finite($v) && floor($v) === $v && abs($v) < 1e15 ? 'i:'.(int) $v : 'f:'.$v;
        }
        if (\is_string($v)) {
            return 's:'.$v;
        }

        return null;
    }

    /**
     * `value in {set of strings}`. An unhashable value is an error in Python.
     *
     * @param array<array-key, mixed> $set keys are the members
     */
    public static function inKeys(mixed $v, array $set): bool
    {
        if (null === self::hashKey($v)) {
            throw new ValidateCrash('an unhashable value (list or object) where a name was expected');
        }

        return \is_string($v) && \array_key_exists($v, $set);
    }

    /**
     * `value in {the allowed strings}`.
     *
     * @param list<string> $allowed
     */
    public static function inList(mixed $v, array $allowed): bool
    {
        if (null === self::hashKey($v)) {
            throw new ValidateCrash('an unhashable value (list or object) where a name was expected');
        }

        return \is_string($v) && \in_array($v, $allowed, true);
    }

    /** `Regex.match(value)` for the two anchored name patterns. Non-strings are an error. */
    public static function matches(string $pattern, mixed $v): bool
    {
        if (!\is_string($v)) {
            throw new ValidateCrash('expected a string, got '.self::typeName($v));
        }

        // `$` matches before a final newline, as in Python.
        return 1 === preg_match($pattern, $v);
    }

    /** True when a string is empty or only whitespace (Python's `not s.strip()`). */
    public static function blank(string $s): bool
    {
        return 1 === preg_match('/\A[\s\x1c-\x1f]*\z/u', $s);
    }
}
