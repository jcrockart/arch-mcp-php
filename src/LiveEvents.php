<?php

namespace ArchMcp;

require_once __DIR__.'/PortalProjectResolver.php';

/**
 * Live-events state per environment for arch_project_info (ARCH-82).
 *
 * Reads Portal's own record, the project_node_clients table (one row per
 * environment: hub app id, registered browser origin, connection state, last
 * test, and since migration 0043 the `game` flag). SELECT only, fail soft in
 * the same way as ProjectHostFacts: a Portal database without the table or
 * column, a missing grant or a failed read gives no data and `unavailable`,
 * and arch_project_info still answers.
 *
 * What is reported for each environment (production, staging):
 *   events_enabled     the hub connection exists and Portal marks it connected
 *   hub_connected      same fact under the name the retrospective used
 *   state              Portal's state word (pending, connected, disconnected, problem)
 *   game               whether game tokens are allowed (null if Portal has no game column yet)
 *   origin_registered  a browser origin is written to the hub entry
 *   origin             that origin, e.g. https://staging.example.com
 *   last_test          {at, ok} of Portal's last connection test, or null
 * Game is a separate switch from events: a project can have events on and game off.
 * An environment with no row is reported as {"configured": false}. The app id,
 * publisher fingerprint and any key are never passed on.
 */
final class LiveEvents
{
    public const ENVIRONMENTS = ['staging', 'production'];

    /**
     * @param 'staging'|'production' $environment the Portal database this address talks to
     *
     * @return array{environments: ?array<string, array<string, mixed>>, unavailable: bool}
     */
    public static function fetch(string $slug, string $environment, \Psr\Log\LoggerInterface $logger): array
    {
        $pdo = PortalProjectResolver::connect($environment, $logger);
        if (null === $pdo) {
            return self::unavailable();
        }
        $r = self::fetchWith($pdo, $slug);
        if ($r['unavailable']) {
            $logger->warning('live_events_unavailable', ['slug' => $slug, 'environment' => $environment]);
        }

        return $r;
    }

    /**
     * @return array{environments: ?array<string, array<string, mixed>>, unavailable: bool}
     */
    public static function fetchWith(\PDO $pdo, string $slug): array
    {
        $rows = self::query($pdo, $slug, true) ?? self::query($pdo, $slug, false);
        if (null === $rows) {
            return self::unavailable();
        }

        return self::describe($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{environments: array<string, array<string, mixed>>, unavailable: bool}
     */
    public static function describe(array $rows): array
    {
        $out = [];
        foreach (self::ENVIRONMENTS as $env) {
            $out[$env] = ['configured' => false];
        }
        foreach ($rows as $row) {
            $env = (string) ($row['environment'] ?? '');
            if (!\in_array($env, self::ENVIRONMENTS, true)) {
                continue;
            }
            $state = (string) ($row['state'] ?? '');
            $origin = \is_string($row['origin'] ?? null) ? trim($row['origin']) : '';
            $connected = 'connected' === $state;
            $testAt = $row['last_test_at'] ?? null;
            $out[$env] = [
                'configured' => true,
                'events_enabled' => $connected,
                'hub_connected' => $connected,
                'state' => $state,
                'game' => \array_key_exists('game', $row) && null !== $row['game'] ? (bool) $row['game'] : null,
                'origin_registered' => '' !== $origin,
                'origin' => '' === $origin ? null : $origin,
                'last_test' => \is_string($testAt) && '' !== $testAt
                    ? ['at' => $testAt, 'ok' => null === ($row['last_test_ok'] ?? null) ? null : (bool) $row['last_test_ok']]
                    : null,
            ];
        }

        return ['environments' => $out, 'unavailable' => false];
    }

    /**
     * @return list<array<string, mixed>>|null null when the query cannot run
     */
    private static function query(\PDO $pdo, string $slug, bool $withGame): ?array
    {
        $cols = 'c.environment, c.state, c.origin, c.last_test_at, c.last_test_ok'.($withGame ? ', c.game' : '');
        try {
            $stmt = $pdo->prepare("SELECT {$cols} FROM project_node_clients c JOIN projects p ON p.id = c.project_id WHERE p.slug = :slug");
            $stmt->execute(['slug' => $slug]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }

        return \is_array($rows) ? $rows : null;
    }

    /**
     * @return array{environments: null, unavailable: true}
     */
    private static function unavailable(): array
    {
        return ['environments' => null, 'unavailable' => true];
    }
}
