<?php

namespace ArchMcp;

/**
 * The work behind db_read_staging, db_read_prod, db_migrate_staging and
 * db_migrate_prod. ProjectsTools decides WHO may call and which profile to
 * use (DbGuard, the resolver); this class does the database part for an
 * already-resolved profile.
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

        $confirmed = $mayConfirm
            ? array_values(array_filter(array_map('trim', explode(',', $confirmDestructive)), static fn (string $s): bool => '' !== $s))
            : [];
        $plan = DbMigrations::plan($pending, trim($upTo), $confirmed);
        if (isset($plan['error'])) {
            return ['success' => false, 'error' => $plan['error']];
        }

        if ([] !== $plan['unconfirmed']) {
            $how = $mayConfirm
                ? 'pass confirmDestructive with their ids to run them'
                : 'destructive migrations on production are confirmed in Portal, not from a chat';
            $out = [
                'success' => false,
                'error' => 'nothing was applied: '.\count($plan['unconfirmed']).' destructive migration(s) in the selection; '.$how,
                'destructive' => $plan['unconfirmed'],
            ];
            if (null !== $plan['safe_up_to']) {
                $out['safe_up_to'] = $plan['safe_up_to'];
                $out['hint'] = "upTo='{$plan['safe_up_to']}' applies everything before the first destructive one";
            }

            return $out;
        }

        if ([] === $plan['selected']) {
            return ['success' => true, 'environment' => $kind, 'applied' => [], 'pending_after' => 0, 'note' => 'nothing pending'];
        }

        $result = DbMigrations::apply($pdo, $plan['selected'], 'staging' === $kind ? 'agent:db_migrate_staging' : 'agent:db_migrate_prod');
        $result['environment'] = $kind;
        if ($result['success']) {
            $result['pending_after'] = \count($pending) - \count($plan['selected']);
        }

        return $result;
    }
}
