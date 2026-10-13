<?php

namespace ArchMcp;

require_once __DIR__.'/PortalProjectResolver.php';

/**
 * The project's environment notes for arch_project_info (added 2026-10-13):
 * the free text Portal keeps in projects.environment_notes, saying what stack
 * and limits a project has (hosting, runtime, what is and is not available), so
 * a chat is told in the first call it makes rather than guessing.
 *
 * Read on its own, deliberately NOT through PortalProjectResolver's shared
 * profile query. That query runs for every tool call; if it selected a column
 * a Portal database did not have yet, every call on that address would fail
 * with "no such project". This read is used only by arch_project_info, after
 * membership and the binding check, and fails soft: a missing column, a failed
 * read or an unreadable value gives no text and `unavailable`, and
 * arch_project_info still answers.
 *
 * The text is whatever a person entered in Portal, so it is data, not
 * instruction. It is passed through unchanged apart from two safety limits:
 * control characters other than newline and tab are removed (and CRLF becomes
 * LF), and it is cut at 2000 bytes on a character boundary, with `truncated`
 * set. Empty or whitespace-only text counts as not set.
 */
final class ProjectNotes
{
    public const MAX_BYTES = 2000;

    /**
     * @param 'staging'|'production' $environment the Portal database this address talks to
     *
     * @return array{text: ?string, truncated: bool, unavailable: bool}
     */
    public static function fetch(string $slug, string $environment, \Psr\Log\LoggerInterface $logger): array
    {
        $pdo = PortalProjectResolver::connect($environment, $logger);
        if (null === $pdo) {
            return self::unavailable();
        }
        $notes = self::fetchWith($pdo, $slug);
        if ($notes['unavailable']) {
            $logger->warning('project_notes_unavailable', ['slug' => $slug, 'environment' => $environment]);
        }

        return $notes;
    }

    /**
     * @return array{text: ?string, truncated: bool, unavailable: bool}
     */
    public static function fetchWith(\PDO $pdo, string $slug): array
    {
        try {
            $stmt = $pdo->prepare('SELECT environment_notes FROM projects WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return self::unavailable();
        }
        if (false === $row) {
            return self::unavailable();
        }
        $value = $row['environment_notes'] ?? null;

        return self::clean(\is_string($value) ? $value : null);
    }

    /**
     * @return array{text: ?string, truncated: bool, unavailable: bool}
     */
    public static function clean(?string $text): array
    {
        if (null === $text) {
            return ['text' => null, 'truncated' => false, 'unavailable' => false];
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return self::unavailable();
        }
        $t = str_replace(["\r\n", "\r"], "\n", $text);
        $t = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t);
        $t = trim($t);
        if ('' === $t) {
            return ['text' => null, 'truncated' => false, 'unavailable' => false];
        }
        $truncated = false;
        if (\strlen($t) > self::MAX_BYTES) {
            $t = rtrim(mb_strcut($t, 0, self::MAX_BYTES, 'UTF-8'));
            $truncated = true;
        }

        return ['text' => $t, 'truncated' => $truncated, 'unavailable' => false];
    }

    /**
     * @return array{text: ?string, truncated: bool, unavailable: bool}
     */
    private static function unavailable(): array
    {
        return ['text' => null, 'truncated' => false, 'unavailable' => true];
    }
}
