<?php

namespace ArchMcp;

require_once __DIR__.'/ConfirmIds.php';
require_once __DIR__.'/IncomingMigrations.php';
require_once __DIR__.'/CommitPin.php';

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
     * @param string               $atCommit    empty = today's behaviour (the checkout's db/migrations).
     *                                          40 lowercase hex = apply the NON-destructive migrations
     *                                          declared at exactly that commit (production only; see
     *                                          migrateAtCommit)
     *
     * @return array<string, mixed>
     */
    public static function migrate(array $profile, string $kind, string $upTo, string $confirmDestructive, bool $mayConfirm, string $atCommit = ''): array
    {
        if ('' !== $atCommit) {
            return self::migrateAtCommit($profile, $kind, $upTo, $confirmDestructive, $atCommit);
        }
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
     * The SHA-pinned apply: run the migrations declared at ONE commit on origin against the
     * production database, before the code is pulled. Nothing destructive is ever applied here
     * (confirming one is Portal's, from the Review & pull page, not this call), and nothing
     * is applied unless the whole pinned set parses cleanly. The checkout is not touched.
     *
     * `upTo` keeps its meaning: apply pending migrations in id order up to and including that
     * id; empty means every pending one, which then must contain no destructive migration.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private static function migrateAtCommit(array $profile, string $kind, string $upTo, string $confirmDestructive, string $atCommit): array
    {
        $ctx = ['environment' => $kind, 'source' => 'commit', 'at_commit' => $atCommit];
        $refuse = static fn (string $reason, string $error, array $more = []): array => ['success' => false, 'reason' => $reason, 'error' => $error] + $more + $ctx;

        if ('production' !== $kind) {
            return $refuse('invalid_use', 'atCommit applies to production only; staging migrations come from the staging checkout');
        }
        if ('' !== trim($confirmDestructive)) {
            return $refuse('confirm_not_allowed', 'nothing was applied: a commit-pinned apply never applies destructive migrations, so confirmDestructive is not accepted here; destructive migrations on production are confirmed in Portal');
        }
        if (!CommitPin::isValid($atCommit)) {
            return $refuse('invalid_commit', CommitPin::NOT_VALID);
        }
        $branch = $profile['pull_branch'] ?? '';
        $gitRoot = $profile['root'] ?? null;
        if (!\is_string($branch) || '' === $branch || !\is_string($gitRoot) || '' === $gitRoot) {
            return $refuse('no_checkout', 'this profile has no pull_branch or checkout');
        }
        $root = self::coreRoot($profile);
        if (null === $root) {
            return $refuse('no_checkout', 'this project has no core lane');
        }

        // The commit must be on origin and a fast-forward of production's HEAD, and the tree
        // clean, so the pull that follows can land exactly this commit.
        $pin = CommitPin::verify($gitRoot, $branch, $atCommit, true, true, true);
        if (!$pin['ok']) {
            return $refuse($pin['reason'], 'nothing was applied: '.$pin['error']);
        }
        $sub = $profile['lanes']['core'] ?? '';
        $at = IncomingMigrations::filesAtCommit($gitRoot, \is_string($sub) ? $sub : '', $atCommit);
        if (!$at['available']) {
            return $refuse('git_error', 'nothing was applied: '.($at['error'] ?? 'could not read the migrations at that commit'));
        }
        if ([] !== $at['problems']) {
            return $refuse('migration_problems', 'nothing was applied: the migrations at that commit have problems: '.implode('; ', $at['problems']), ['problems' => $at['problems']]);
        }

        $conn = DbConnect::connect($root);
        if (!isset($conn['pdo'])) {
            return $refuse('git_error', $conn['error'] ?? 'could not connect');
        }
        $pdo = $conn['pdo'];
        try {
            $applied = DbMigrations::appliedIds($pdo);
        } catch (\Throwable $e) {
            return $refuse('git_error', $e->getMessage());
        }
        $pending = array_values(array_filter($at['migrations'], static fn (array $m): bool => !\in_array($m['id'], $applied, true)));

        // Same waiver as today: a brand-new production database has nothing to lose.
        $fresh = DbFreshCheck::isFresh($pdo);
        $confirmed = $fresh ? array_map(static fn (array $m): string => (string) $m['id'], $pending) : [];
        $plan = DbMigrations::plan($pending, trim($upTo), $confirmed);
        if (isset($plan['error'])) {
            return $refuse('upto_not_pending', $plan['error']);
        }
        if ([] !== $plan['unconfirmed']) {
            $more = ['destructive' => $plan['unconfirmed']];
            $hint = '';
            if (null !== $plan['safe_up_to']) {
                $more['safe_up_to'] = $plan['safe_up_to'];
                $hint = "; upTo='{$plan['safe_up_to']}' applies everything before the first destructive one";
            }

            return $refuse('destructive_in_selection', 'nothing was applied: '.\count($plan['unconfirmed']).' destructive migration(s) in the selection; destructive migrations on production are confirmed in Portal, not from a chat'.$hint, $more);
        }
        if ([] === $plan['selected']) {
            return ['success' => true, 'applied' => [], 'pending_after' => 0, 'note' => 'nothing pending at that commit'] + $ctx;
        }

        $result = DbMigrations::apply($pdo, $plan['selected'], 'agent:db_migrate_prod');
        $result += $ctx;
        if ($fresh) {
            $result['fresh_database'] = true;
        }
        if ($result['success']) {
            $result['pending_after'] = \count($pending) - \count($plan['selected']);
        }

        return $result;
    }

    /**
     * The pinned half of pending(): the migrations declared at one commit on origin that are
     * not applied to the database. Read-only. Does not need a clean tree (a judgement is not
     * a change), but the commit must be on origin and a fast-forward of production's HEAD.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private static function pendingAtCommit(array $profile, string $kind, string $atCommit): array
    {
        $ctx = ['environment' => $kind, 'source' => 'commit', 'at_commit' => $atCommit];
        $refuse = static fn (string $reason, string $error): array => ['success' => false, 'reason' => $reason, 'error' => $error] + $ctx;

        if (!CommitPin::isValid($atCommit)) {
            return $refuse('invalid_commit', CommitPin::NOT_VALID);
        }
        $branch = $profile['pull_branch'] ?? '';
        $gitRoot = $profile['root'] ?? null;
        if (!\is_string($branch) || '' === $branch || !\is_string($gitRoot) || '' === $gitRoot) {
            return $refuse('no_checkout', 'this profile has no pull_branch or checkout');
        }
        $root = self::coreRoot($profile);
        if (null === $root) {
            return $refuse('no_checkout', 'this project has no core lane');
        }
        $pin = CommitPin::verify($gitRoot, $branch, $atCommit, false, true, true);
        if (!$pin['ok']) {
            return $refuse($pin['reason'], $pin['error']);
        }
        $sub = $profile['lanes']['core'] ?? '';
        $at = IncomingMigrations::filesAtCommit($gitRoot, \is_string($sub) ? $sub : '', $atCommit);
        if (!$at['available']) {
            return $refuse('git_error', $at['error'] ?? 'could not read the migrations at that commit');
        }
        $conn = DbConnect::connect($root);
        if (!isset($conn['pdo'])) {
            return $refuse('git_error', $conn['error'] ?? 'could not connect');
        }
        try {
            $applied = DbMigrations::appliedIds($conn['pdo']);
        } catch (\Throwable $e) {
            return $refuse('git_error', $e->getMessage());
        }
        $pending = array_values(array_filter($at['migrations'], static fn (array $m): bool => !\in_array($m['id'], $applied, true)));

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
        $plan = DbMigrations::plan($pending, '', []);
        $out = [
            'success' => true,
            'declared' => \count($at['migrations']),
            'pending_count' => \count($list),
            'pending' => $list,
            'problems' => $at['problems'],
            'safe_up_to' => $plan['safe_up_to'] ?? null,
        ] + $ctx;
        if ([] === $list) {
            $out['note'] = 'nothing pending at that commit';
        }

        return $out;
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
    public static function pending(array $profile, string $kind, bool $includeIncoming = false, string $atCommit = ''): array
    {
        if ('' !== $atCommit) {
            return self::pendingAtCommit($profile, $kind, $atCommit);
        }
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
