<?php

namespace ArchMcp;

require_once __DIR__.'/PortalCaller.php';

/**
 * The two chat provisioning calls to Portal's api_provision.php (ARCH-74, Portal
 * API v0.5.0, answered 2026-10-30): `request` creates an auto-provisioned
 * project owned by the caller, `status` follows it.
 *
 * Portal does all the checking (who the caller is, slots, eligibility, whether a
 * project is the caller's own). This class only:
 *   - refuses an empty or absurd slug before any call (everything else about the
 *     slug, its length and characters and the reserved names, is Portal's call
 *     and its `invalid` text comes back unchanged);
 *   - trims `notes` and cuts it at Portal's own limit, saying so when it did;
 *   - makes exactly ONE Portal call per run and never retries;
 *   - hands Portal's reply back unchanged, so codes and error text reach the
 *     chat as Portal wrote them, adding only a `next` line on success;
 *   - for a call that got no usable answer from Portal (transport failure, bad
 *     reply, a failure without a code), says so in its own words. For a request
 *     that is the one case where the outcome is unknown: it MAY have started and
 *     taken a slot, so the chat is told not to ask again and to read status.
 *
 * The token is never seen here: the PortalCaller holds it. `notes` is not logged
 * by the caller either; it is stored by Portal as the project's notes and used
 * to make the project's icon.
 *
 * Pure apart from the one Portal call.
 */
final class ProvisionRequest
{
    /** Portal cuts notes at 2000 characters without an error; so do we, and say so. */
    public const MAX_NOTES_CHARS = 2000;

    private const MAX_SLUG_BYTES = 200;

    private const SLUG_RULE = 'slug is required: 3 to 100 characters, lowercase letters, digits and hyphens, starting and ending with a letter or digit';

    private const NEXT_FOLLOW = 'Follow progress with arch_provision_status for this slug, no more often than every 15 to 30 seconds; it can take a few minutes. Do not request this project again.';
    private const NEXT_DONE = 'Provisioning has finished. Next: install_bootstrap for this slug (see that tool), then arch_bootstrap_read.';
    private const NEXT_FAILED = 'Provisioning stopped and an admin has been told. Tell the person and stop. Do not request this project again.';

    /**
     * @return array<string, mixed>
     */
    public static function request(PortalCaller $portal, string $slug, string $notes = ''): array
    {
        $slug = self::cleanSlug($slug);
        if (null === $slug) {
            return ['success' => false, 'code' => 'invalid', 'error' => self::SLUG_RULE];
        }
        [$notes, $cut] = self::cleanNotes($notes);
        $fields = '' === $notes ? [] : ['notes' => $notes];

        $reply = $portal->call('request', $slug, $fields);
        if (self::hasNoUsableAnswer($reply)) {
            return [
                'success' => false,
                'code' => 'server',
                'may_have_started' => true,
                'error' => 'Could not get a confirmed answer from Portal. The request MAY have started, and a slot may have been taken. Do not request again: call arch_provision_status with this slug to see.',
            ];
        }

        return self::withNext($reply, $cut);
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(PortalCaller $portal, string $slug): array
    {
        $slug = self::cleanSlug($slug);
        if (null === $slug) {
            return ['success' => false, 'code' => 'invalid', 'error' => self::SLUG_RULE];
        }

        $reply = $portal->call('status', $slug, []);
        if (self::hasNoUsableAnswer($reply)) {
            return [
                'success' => false,
                'code' => 'server',
                'error' => 'Could not get an answer from Portal. This call changed nothing; check again in a little while.',
            ];
        }

        return self::withNext($reply, false);
    }

    private static function cleanSlug(string $slug): ?string
    {
        $slug = trim($slug);
        if ('' === $slug || \strlen($slug) > self::MAX_SLUG_BYTES || 1 === preg_match('/[\x00-\x1F\x7F]/', $slug)) {
            return null;
        }

        return $slug;
    }

    /**
     * @return array{0: string, 1: bool} the notes to send, and whether they were cut
     */
    private static function cleanNotes(string $notes): array
    {
        $notes = trim(str_replace("\0", '', mb_scrub($notes, 'UTF-8')));
        if (mb_strlen($notes, 'UTF-8') > self::MAX_NOTES_CHARS) {
            return [rtrim(mb_substr($notes, 0, self::MAX_NOTES_CHARS, 'UTF-8')), true];
        }

        return [$notes, false];
    }

    /**
     * arch-mcp's own transport and bad-reply failures use code `server`, which
     * Portal's list does not (its own server error is `error`). A failure with no
     * code at all is treated the same way, because it cannot be read safely.
     *
     * @param array<string, mixed> $reply
     */
    private static function hasNoUsableAnswer(array $reply): bool
    {
        if (true === ($reply['success'] ?? false)) {
            return false;
        }
        $code = $reply['code'] ?? null;

        return !\is_string($code) || '' === $code || 'server' === $code;
    }

    /**
     * Portal's reply as it came, plus a `next` line on success (and
     * `notes_truncated` when the notes were cut). Portal's own keys are never
     * changed or removed. A failure comes back exactly as Portal sent it.
     *
     * @param array<string, mixed> $reply
     *
     * @return array<string, mixed>
     */
    private static function withNext(array $reply, bool $notesCut): array
    {
        if (true !== ($reply['success'] ?? false)) {
            return $reply;
        }
        if ($notesCut && !isset($reply['notes_truncated'])) {
            $reply['notes_truncated'] = true;
        }
        $next = match ($reply['status'] ?? null) {
            'provisioning' => self::NEXT_FOLLOW,
            'provisioned' => self::NEXT_DONE,
            'provisioning_failed' => self::NEXT_FAILED,
            default => null,
        };
        if (null !== $next && !isset($reply['next'])) {
            $reply['next'] = $next;
        }

        return $reply;
    }
}
