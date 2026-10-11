<?php

namespace ArchMcp;

/**
 * Whether the Claude project binding check blocks or only logs (ARCH-62,
 * decided 2026-10-11: log-only first, then enforce).
 *
 * The mode lives in a small JSON file outside git, in the same secrets
 * directory as the other deploy-time settings, edited by hand:
 *
 *   {"mode": "enforce"}
 *
 * Anything else, a missing file, a file that does not parse, or an unknown
 * value, means "log". So the check can never start blocking by accident, and
 * deploying this code changes nothing until someone writes the file.
 */
final class BindingMode
{
    public const LOG = 'log';
    public const ENFORCE = 'enforce';

    private const CONFIG_PATH = '/home/crockart/arch-mcp-secrets/binding-mode.json';

    /** The mode named by the file's text; LOG for anything that is not exactly "enforce". */
    public static function parse(?string $json): string
    {
        if (null === $json) {
            return self::LOG;
        }
        $decoded = json_decode($json, true);
        if (\is_array($decoded) && self::ENFORCE === ($decoded['mode'] ?? null)) {
            return self::ENFORCE;
        }

        return self::LOG;
    }

    /** Reads the deploy-time file; $path is only for tests. */
    public static function load(?string $path = null): string
    {
        $path ??= self::CONFIG_PATH;
        if (!\is_file($path)) {
            return self::LOG;
        }
        $raw = @file_get_contents($path);

        return self::parse(\is_string($raw) ? $raw : null);
    }
}
