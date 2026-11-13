<?php

namespace ArchMcp;

require_once __DIR__.'/PortalProjectResolver.php';

/**
 * Live-events state per environment for arch_project_info (ARCH-82).
 *
 * Reads Portal's project_node_clients table (one row per project and
 * environment), SELECT only, fail soft like ProjectHostFacts: a Portal database
 * without the columns, a missing grant or a failed read gives no data and
 * `unavailable`, and arch_project_info still answers.
 *
 * Per environment (staging, production) the answer is
 *   {connected, events, game, origin_registered, state}
 *   connected          Portal's record of the LAST CONNECT (active and the last connect ended in
 *                      created, updated or unchanged). It is not a live check of the hub.
 *   events             always equal to `connected`: EVENTS has no switch of its own.
 *   game               the separate GAME switch the project owner or an admin turns on, reported as
 *                      stored, even when not connected.
 *   origin_registered  connected, and a browser origin was written to the hub entry.
 *   state              Portal's word for the last connect (created, updated, unchanged, or an error word).
 * With no row: state "not_connected" and everything false.
 * The hub app id, origin, publisher fingerprint and any key are never passed on.
 */
final class LiveEvents
{
    public const ENVIRONMENTS = ['staging', 'production'];

    /** Given once at the top level of arch_project_info, beside `live_events`. */
    public const NOTE = "EVENTS and GAME are two separate switches. EVENTS (small stored notifications) is on for every connected app. GAME (real-time play, never stored) is off until the project owner or an admin switches it on, per environment. 'events is on' does not mean GAME is on.";

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
        try {
            $stmt = $pdo->prepare('SELECT c.environment, c.connected, c.events_on, c.game, c.origin_registered, c.state FROM project_node_clients c JOIN projects p ON p.id = c.project_id WHERE p.slug = :slug');
            $stmt->execute(['slug' => $slug]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return self::unavailable();
        }

        return self::describe(\is_array($rows) ? $rows : []);
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
            $out[$env] = ['connected' => false, 'events' => false, 'game' => false, 'origin_registered' => false, 'state' => 'not_connected'];
        }
        foreach ($rows as $row) {
            $env = (string) ($row['environment'] ?? '');
            if (!\in_array($env, self::ENVIRONMENTS, true)) {
                continue;
            }
            $connected = (bool) ($row['connected'] ?? false);
            $out[$env] = [
                'connected' => $connected,
                'events' => $connected,
                'game' => (bool) ($row['game'] ?? false),
                'origin_registered' => (bool) ($row['origin_registered'] ?? false),
                'state' => \is_string($row['state'] ?? null) && '' !== $row['state'] ? $row['state'] : 'unknown',
            ];
        }

        return ['environments' => $out, 'unavailable' => false];
    }

    /**
     * @return array{environments: null, unavailable: true}
     */
    private static function unavailable(): array
    {
        return ['environments' => null, 'unavailable' => true];
    }
}
