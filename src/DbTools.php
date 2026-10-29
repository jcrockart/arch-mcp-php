<?php

namespace ArchMcp;

require_once __DIR__.'/ConfirmIds.php';
require_once __DIR__.'/IncomingMigrations.php';

/**
 * The work behind db_read_staging, db_read_prod, db_pending_staging,
 * db_pending_prod, db_migrate_staging and db_migrate_prod. ProjectsTools
 * decides WHO may call and which profile to use (DbGuard, the resolver); this
 * class does the database part for an already-resolved profile.
 */
final class DbTools
{
    /**
     * The project's core lane directory (where config.php and db/ live), or
     * null if the profile has no core lane or it is not inside the root.
     *
     * @param array<string, mixed> $profile
     */
    public static function coreRoot(array $profile): ?string
    {
        $sub = $profile['lanes']['core'] ?? null;
        $base = $profile['root'] ?? null;
        if (!\is_string($sub) || !\is_string($base) || '' === $base) {
            return null;
        }
        $dir = realpath('' === $sub ? $base : $base.'/'.$sub);
        $realBase = realpath($base);
        if (false === $dir || false === $realBase || !is_dir($dir)) {
            return null;
        }
        if ($dir !== $realBase && !str_starts_with($dir, $realBase.\DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $dir;
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    public static function read(array $profile, string $sql, int $maxRows, bool $redact): array
    {
        $root = self::coreRoot($profile);
        if (null === $root) {
            return ['success' => false, 'error' => 'this project has no core lane'];
        }
        $conn = DbConnect::connect($root);
        if (!isset($conn['pdo'])) {
            return ['success' => false, 'error' => $conn['error'] ?? 'could not connect'];
        }

        return DbRead::run($conn['pdo'], $sql, $maxRows, $redact);
    }

    /**
     * @param array<string, mixed> $profile
     * @param string               $kind        'staging' or 'production'
     * @param bool                 $mayConfirm  may this caller confirm destructive migrations
     *
     * @return array<string, mixed>
     */
    public static function migrate(array $profile, string $kind, string $upTo, string $confirmDestructive, bool $mayConfirm): array
    {
        $root = self::coreRoot($profile);
        if (null === $root) {
            return ['success' => false, 'error' => 'this project has no core lane'];
        }
        $conn = DbConnect::connect($root);
        if (!isset($conn['pdo'])) {
            return ['success' => false, 'error' => $conn['error'] ?? 'could not connect'];
        }
        $pdo = $conn['pdo'];

        try {
            $pending = DbMigrations::pending($root.'/db/migrations', $pdo);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $skipped = DbMigrations::listSkipped($root.'/db/migrations');
        $withSkipped = static fn (array $r): array => [] === $skipped ? $r : $r + ['skipped' => $skipped];

        $parsed = ConfirmIds::parse($confirmDestructive);
        $confirmed = $mayConfirm ? $parsed['ids'] : [];
        // A brand-new production database has nothing to lose: the destructive check is waived
        // for it. Judged once, before anything is applied (see DbFreshCheck).
        $fresh = 'production' === $kind && DbFreshCheck::isFresh($pdo);
        if ($fresh) {
            $confirmed = array_map(static fn (array $m): string => (string) $m['id'], $pending);
        }
        if ($mayConfirm && !$fresh && isset($parsed['error'])) {
            return $withSkipped(['success' => false, 'error' => 'nothing was applied: '.$parsed['error']]);
        }
        $plan = DbMigrations::plan($pending, trim($upTo), $confirmed);
        if (isset($plan['error'])) {
            return ['success' => false, 'error' => $plan['error']];
        }

        if ([] !== $plan['unconfirmed']) {
            $out = [
                'success' => false,
                'error' => $mayConfirm
                    ? ConfirmIds::refusal($plan['unconfirmed'], $plan['selected'], $confirmed)
                    : 'nothing was applied: '.\count($plan['unconfirmed']).' destructive migration(s) in the selection; destructive migrations on production are confirmed in Portal, not from a chat',
                'destructive' => $plan['unconfirmed'],
            ];
            if ($mayConfirm) {
                $out['confirm_with'] = implode(',', ConfirmIds::destructiveIds($plan['selected']));
            }
            if (null !== $plan['safe_up_to']) {
                $out['safe_up_to'] = $plan['safe_up_to'];
                $out['hint'] = "upTo='{$plan['safe_up_to']}' applies everything before the first destructive one";
            }

            return $withSkipped($out);
        }

        if ([] === $plan['selected']) {
            return $withSkipped(['success' => true, 'environment' => $kind, 'applied' => [], 'pending_after' => 0, 'note' => [] === $skipped ? 'nothing pending' : 'nothing pending, but '.\count($skipped).' file(s) in db/migrations were ignored: see skipped']);
        }

        $result = DbMigrations::apply($pdo, $plan['selected'], 'staging' === $kind ? 'agent:db_migrate_staging' : 'agent:db_migrate_prod');
        $result['environment'] = $kind;
        if ($fresh) {
            $result['fresh_database'] = true;
        }
        if ($result['success']) {
            $result['pending_after'] = \count($pending) - \count($plan['selected']);
        }

        return $withSkipped($result);
    }

    /**
     * List the migrations declared in db/migrations but not yet recorded in
     * the database's schema_migrations (a missing table means all of them).
     * Read-only: nothing is applied or written. Each entry says whether
     * applying it would count as destructive, and why.
     *
     * @param array<string, mixed> $profile
     * @param string               $kind    'staging' or 'production'
     *
     * @return array<string, mixed>
     */
    public static function pending(array $profile, string $kind, bool $includeIncoming = false): array
    {
        $root = self::coreRoot($profile);
        if (null === $root) {
            return ['success' => false, 'error' => 'this project has no core lane'];
        }
        $conn = DbConnect::connect($root);
        if (!isset($conn['pdo'])) {
            return ['success' => false, 'error' => $conn['error'] ?? 'could not connect'];
        }

        try {
            $declared = DbMigrations::listDeclared($root.'/db/migrations');
            $pending = DbMigrations::pending($root.'/db/migrations', $conn['pdo']);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $list = [];
        foreach ($pending as $m) {
            $reason = DbMigrations::destructiveReason($m);
            $entry = [
                'id' => $m['id'],
                'type' => $m['type'] ?? null,
                'data_class' => $m['data-class'] ?? null,
                'description' => $m['description'] ?? null,
                'destructive' => null !== $reason,
            ];
            if (null !== $reason) {
                $entry['reason'] = $reason;
            }
            $list[] = $entry;
        }

        $out = [
            'success' => true,
            'environment' => $kind,
            'declared' => \count($declared),
            'pending_count' => \count($list),
            'pending' => $list,
        ];
        $skipped = DbMigrations::listSkipped($root.'/db/migrations');
        if ([] !== $skipped) {
            $out['skipped'] = $skipped;
        }
        if ([] === $list) {
            $out['note'] = [] === $skipped ? 'nothing pending' : 'nothing pending, but '.\count($skipped).' file(s) in db/migrations were ignored: see skipped';
        }

        // Opt-in: what a pull would bring in that is not in this checkout yet (see
        // IncomingMigrations). Never changes `pending`, which stays about the checkout.
        if ($includeIncoming) {
            $sub = $profile['lanes']['core'] ?? '';
            $branch = $profile['pull_branch'] ?? '';
            $out['incoming'] = \is_string($branch) && '' !== $branch && \is_string($profile['root'] ?? null)
                ? IncomingMigrations::forPull(
                    (string) $profile['root'],
                    \is_string($sub) ? $sub : '',
                    $branch,
                    DbMigrations::appliedIds($conn['pdo']),
                    array_map(static fn (array $m): string => (string) $m['id'], $declared)
                )
                : ['available' => false, 'error' => 'this profile has no pull_branch'];
        }

        return $out;
    }
}
