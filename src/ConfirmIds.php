<?php

namespace ArchMcp;

require_once __DIR__.'/DbSql.php';
require_once __DIR__.'/DbMigrations.php';

/**
 * The `confirmDestructive` argument of db_migrate_staging and db_migrate_prod,
 * made forgiving and clear, added 2026-10-29.
 *
 * WHY: the argument used to be split on commas and nothing else, so a JSON
 * list such as ["a","b"] came out as `["a"` and `"b"]`, matched no migration
 * and gave a refusal that did not say what format was wanted.
 *
 * parse() accepts a comma- (or newline-) separated string or a JSON list, with
 * whitespace and quotes around each id tolerated. refusal() builds the one-line
 * reply for a call that left destructive migrations unconfirmed: it names the
 * ids that would be accepted and says how to pass them.
 */
final class ConfirmIds
{
    public const FORMAT = 'confirmDestructive takes migration ids as a comma-separated string (a,b) or a JSON list (["a","b"])';

    /**
     * @return array{ids: list<string>, error?: string}
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return ['ids' => []];
        }

        if ('[' === $raw[0]) {
            $decoded = json_decode($raw, true);
            if (!\is_array($decoded) || !array_is_list($decoded)) {
                return ['ids' => [], 'error' => self::FORMAT.'; the JSON list could not be read'];
            }
            $items = [];
            foreach ($decoded as $value) {
                if (!\is_string($value) && !\is_int($value)) {
                    return ['ids' => [], 'error' => self::FORMAT.'; every entry of the JSON list must be an id string'];
                }
                $items[] = (string) $value;
            }
        } else {
            $items = preg_split('/[,\n]+/', $raw) ?: [];
        }

        $ids = [];
        foreach ($items as $item) {
            $id = trim(trim($item), "\"' \t\r");
            if ('' !== $id && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return ['ids' => $ids];
    }

    /**
     * Ids of the destructive migrations among $selected, in order.
     *
     * @param list<array<string, mixed>> $selected
     *
     * @return list<string>
     */
    public static function destructiveIds(array $selected): array
    {
        $ids = [];
        foreach ($selected as $m) {
            if (null !== DbMigrations::destructiveReason($m)) {
                $ids[] = (string) $m['id'];
            }
        }

        return $ids;
    }

    /**
     * One-line reply for a refused call.
     *
     * @param list<array<string, string>> $unconfirmed destructive migrations still unconfirmed (id, reason, sql)
     * @param list<array<string, mixed>>  $selected    every migration the call would apply
     * @param list<string>                $supplied    the ids the caller passed
     */
    public static function refusal(array $unconfirmed, array $selected, array $supplied): string
    {
        $accepted = self::destructiveIds($selected);
        $list = implode(',', $accepted);

        if ([] === $supplied) {
            return 'nothing was applied: '.\count($unconfirmed).' destructive migration(s) in the selection; re-call with confirmDestructive="'.$list.'" to run them (comma-separated ids, or a JSON list)';
        }

        $unknown = array_values(array_diff($supplied, $accepted));
        $msg = 'nothing was applied: confirmDestructive did not cover every destructive migration';
        if ([] !== $unknown) {
            $msg .= '; no destructive migration has the id(s) '.implode(', ', $unknown);
        }
        $still = array_map(static fn (array $m): string => (string) $m['id'], $unconfirmed);

        return $msg.'; still unconfirmed: '.implode(', ', $still).'; ids that would be accepted: '.$list.' (pass them comma-separated, or as a JSON list)';
    }
}
