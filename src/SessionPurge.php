<?php

namespace ArchMcp;

/**
 * Deletes discarded session branches once they are past the grace window.
 * Each discard leaves a tag discard-marker/<branch>/<epoch>; a branch whose
 * tag is GRACE_DAYS old or older is removed with its tag. Runs after every
 * discard and is safe to run at any time.
 */
final class SessionPurge
{
    public const GRACE_DAYS = 7;
    public const PREFIX = 'discarded/';

    /**
     * @param callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $git
     *
     * @return list<string> one line per purged branch
     */
    public static function run(callable $git, ?int $now = null): array
    {
        $now ??= time();
        $lines = [];
        $tags = preg_split('/\s+/', trim($git(['tag', '--list', 'discard-marker/*'])['stdout'])) ?: [];
        foreach ($tags as $tag) {
            $cut = strrpos($tag, '/');
            if (false === $cut) {
                continue;
            }
            $epoch = substr($tag, $cut + 1);
            if (!ctype_digit($epoch)) {
                continue;
            }
            $branch = substr(substr($tag, 0, $cut), \strlen('discard-marker/'));
            if (($now - (int) $epoch) / 86400 >= self::GRACE_DAYS) {
                $git(['branch', '-D', $branch]);
                $git(['tag', '-d', $tag]);
                $lines[] = "Purged expired discarded branch '{$branch}' (past ".self::GRACE_DAYS."-day grace window).\n";
            }
        }

        return $lines;
    }
}
