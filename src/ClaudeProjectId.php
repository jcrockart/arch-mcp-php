<?php

namespace ArchMcp;

/**
 * Validates a Claude project ID and cleans the optional name label
 * (ARCH-62 / ARCH-63, decided 2026-10-11).
 *
 * The binding between a Claude project and an ARCH project is the GUID in
 * the Claude project URL (https://claude.ai/project/<guid>). The server
 * cannot check that a GUID belongs to a real Claude project; it only checks
 * the shape. A pasted project URL is accepted and reduced to its GUID, since
 * that is what a person does when Claude cannot see its own ID. The result
 * is always lower case, so one project never registers twice under two
 * spellings. The name is a label for people reading logs and Portal; it is
 * never matched against anything.
 *
 * Pure functions, no I/O.
 */
final class ClaudeProjectId
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const URL = '~^https://claude\.ai/(?:[a-z]{2}(?:-[A-Za-z]{2})?/)?project/([0-9a-fA-F-]{36})(?:[/?#].*)?$~';
    private const MAX_INPUT = 300;
    private const MAX_LABEL = 100;

    /**
     * The canonical (lower case) GUID, or null if the input is not a GUID or a
     * claude.ai project URL carrying one. The all-zero GUID is refused.
     */
    public static function normalize(string $input): ?string
    {
        $s = trim($input);
        if ('' === $s || \strlen($s) > self::MAX_INPUT) {
            return null;
        }
        if (1 === preg_match(self::URL, $s, $m)) {
            $s = $m[1];
        }
        $s = strtolower($s);
        if (1 !== preg_match(self::UUID, $s) || '00000000-0000-0000-0000-000000000000' === $s) {
            return null;
        }

        return $s;
    }

    /**
     * The name label, trimmed, with control characters turned into spaces and
     * cut to 100 characters. Empty string when nothing usable is left.
     */
    public static function label(string $name): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        if (!\is_string($clean)) {
            return '';
        }

        return mb_substr(trim($clean), 0, self::MAX_LABEL);
    }
}
