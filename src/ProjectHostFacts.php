<?php

namespace ArchMcp;

require_once __DIR__.'/PortalProjectResolver.php';

/**
 * The project's host facts for arch_project_info (added 2026-10-29): what
 * Portal found out about the real host each environment runs on (PHP version
 * and SAPI, memory/upload/post/time limits, the extensions that matter,
 * open_basedir, whether public/ and the assets folder are writable, and the
 * NAMES of the config.php constants), collected by a short-lived probe that
 * Portal runs in the project's own web context and keeps in projects.host_facts.
 *
 * It is a separate field from `environment_notes` on purpose: environment_notes
 * is free text a person writes and is cut at 2000 bytes when read, whereas host
 * facts are machine-collected, replaced per environment on every refresh, and
 * must never be cut in the middle or merged into hand-written text.
 *
 * Stored JSON (written by Portal): {"staging": {...}, "production": {...}}, each
 * record having ok (bool), checked_at (YYYY-MM-DD), text (the compact block, or
 * "host facts: not collected (<reason>) <date>") and, when not ok, reason.
 * An environment Portal has not collected is simply absent. Only those four
 * fields are passed on; Portal's structured `facts` copy is not.
 *
 * Read on its own, like ProjectNotes and for the same reason: it is used only
 * by arch_project_info, after membership and the binding check, and fails soft.
 * A Portal database without the column, a failed read or unreadable JSON gives
 * no facts and `unavailable`, and arch_project_info still answers. Nothing here
 * is instruction: the text is data. Control characters other than newline are
 * removed and each block is cut at 1200 bytes on a character boundary.
 */
final class ProjectHostFacts
{
    public const MAX_TEXT_BYTES = 1200;
    public const MAX_REASON_CHARS = 80;
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
        $facts = self::fetchWith($pdo, $slug);
        if ($facts['unavailable']) {
            $logger->warning('project_host_facts_unavailable', ['slug' => $slug, 'environment' => $environment]);
        }

        return $facts;
    }

    /**
     * @return array{environments: ?array<string, array<string, mixed>>, unavailable: bool}
     */
    public static function fetchWith(\PDO $pdo, string $slug): array
    {
        try {
            $stmt = $pdo->prepare('SELECT host_facts FROM projects WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return self::unavailable();
        }
        if (false === $row) {
            return self::unavailable();
        }
        $value = $row['host_facts'] ?? null;

        return self::parse(\is_string($value) ? $value : null);
    }

    /**
     * @return array{environments: ?array<string, array<string, mixed>>, unavailable: bool}
     */
    public static function parse(?string $json): array
    {
        if (null === $json || '' === trim($json)) {
            return ['environments' => null, 'unavailable' => false];
        }
        $data = json_decode($json, true);
        if (!\is_array($data) || [] === $data) {
            return self::unavailable();
        }
        $out = [];
        foreach (self::ENVIRONMENTS as $env) {
            $record = self::record($data[$env] ?? null);
            if (null !== $record) {
                $out[$env] = $record;
            }
        }
        if ([] === $out) {
            return self::unavailable();
        }

        return ['environments' => $out, 'unavailable' => false];
    }

    /**
     * @return array<string, mixed>|null null if the record is not a well-formed one
     */
    private static function record(mixed $r): ?array
    {
        if (!\is_array($r) || !\is_bool($r['ok'] ?? null) || !\is_string($r['text'] ?? null) || !\is_string($r['checked_at'] ?? null)) {
            return null;
        }
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['checked_at'])) {
            return null;
        }
        $text = self::cleanText($r['text']);
        if (null === $text) {
            return null;
        }
        $out = ['ok' => $r['ok'], 'checked_at' => $r['checked_at'], 'text' => $text];
        if (!$r['ok']) {
            $reason = \is_string($r['reason'] ?? null) ? self::cleanReason($r['reason']) : '';
            $out['reason'] = '' === $reason ? 'unknown reason' : $reason;
        }

        return $out;
    }

    private static function cleanText(string $text): ?string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return null;
        }
        $t = str_replace(["\r\n", "\r"], "\n", $text);
        $t = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t);
        $t = trim($t);
        if ('' === $t) {
            return null;
        }
        if (\strlen($t) > self::MAX_TEXT_BYTES) {
            $t = rtrim(mb_strcut($t, 0, self::MAX_TEXT_BYTES, 'UTF-8'));
        }

        return $t;
    }

    private static function cleanReason(string $reason): string
    {
        if (!mb_check_encoding($reason, 'UTF-8')) {
            return '';
        }
        $r = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $reason)));

        return mb_substr($r, 0, self::MAX_REASON_CHARS);
    }

    /**
     * @return array{environments: null, unavailable: true}
     */
    private static function unavailable(): array
    {
        return ['environments' => null, 'unavailable' => true];
    }
}
