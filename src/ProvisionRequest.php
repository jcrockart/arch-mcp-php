<?php

namespace ArchMcp;

require_once __DIR__.'/PortalCaller.php';
require_once __DIR__.'/PortalLinks.php';

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
    private const NEXT_FAILED = 'Provisioning stopped and an admin has been told. Tell the person and stop. Do not request this project again.';

    /**
     * @return array<string, mixed>
     */
    public static function request(PortalCaller $portal, string $slug, string $notes = '', array $issuers = []): array
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

        return self::withNext($reply, $cut, $issuers);
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(PortalCaller $portal, string $slug, array $issuers = []): array
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

        return self::withNext($reply, false, $issuers);
    }

    /**
     * What to do once provisioning has finished: recommend a Claude project named
     * exactly the slug (the Portal wizard's step 2), and send the person to Portal
     * for the setup steps and the starter text. The starter text is Portal's, so the
     * chat must not write it. The slug comes from Portal's own reply and is used
     * only if it has the shape of a slug, so nothing else can ride into this line.
     */
    private static function nextDone(array $reply, array $issuers): string
    {
        $valid = self::slugOrNull($reply['slug'] ?? null);
        $name = $valid ?? 'the project slug';
        // Links Portal supplied are used here only if they pass
        // PortalLinks::sameHostUrl; the project page falls back to the link built
        // from the trusted issuer. Portal's own reply keys are not changed.
        $setup = PortalLinks::sameHostUrl($issuers, $reply['setup_url'] ?? null);
        $project = PortalLinks::sameHostUrl($issuers, $reply['project_url'] ?? null)
            ?? (null === $valid ? null : PortalLinks::projectUrl($issuers, $valid));
        $steps = 'which gives the starter text to paste into the Claude project instructions. ';
        if (null !== $setup) {
            $where = 'Give them this link, which goes straight to the Claude project step of the Portal setup: '.$setup.(null === $project ? '' : ' (the project page is '.$project.')').'. Ask them to follow it, '.$steps;
        } elseif (null !== $project) {
            $where = 'Give them this link to the project page in Portal: '.$project.' and ask them to follow the setup there, '.$steps;
        } else {
            $where = 'Ask them to open the project page in Portal (portal.project_url in arch_project_info) and follow the setup there, '.$steps;
        }

        return 'Provisioning has finished. Recommend that the person creates a Claude project named exactly '.$name.' at claude.ai/projects, so their instructions and context stay with it between chats. '
            .$where
            .'Do not write the starter text yourself, and if the page does not show it, tell the person and stop. '
            .'Then, from inside that Claude project, run install_bootstrap for this slug. '
            .'They can keep working in this chat instead, but nothing will be saved for next time.';
    }

    /** The slug if it has the shape of one (from Portal's own reply), otherwise null. */
    private static function slugOrNull(mixed $slug): ?string
    {
        return \is_string($slug) && 1 === preg_match('/^[a-z0-9][a-z0-9-]{1,98}[a-z0-9]$/', $slug) ? $slug : null;
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
    private static function withNext(array $reply, bool $notesCut, array $issuers): array
    {
        if (true !== ($reply['success'] ?? false)) {
            return $reply;
        }
        if ($notesCut && !isset($reply['notes_truncated'])) {
            $reply['notes_truncated'] = true;
        }
        $next = match ($reply['status'] ?? null) {
            'provisioning' => self::NEXT_FOLLOW,
            'provisioned' => self::nextDone($reply, $issuers),
            'provisioning_failed' => self::NEXT_FAILED,
            default => null,
        };
        if (null !== $next && !isset($reply['next'])) {
            $reply['next'] = $next;
        }
        // Once provisioned, the project's own Portal page, so the chat can hand the
        // person a link. Our own key; Portal's keys are never touched.
        if ('provisioned' === ($reply['status'] ?? null) && !isset($reply['project_url'])) {
            $valid = self::slugOrNull($reply['slug'] ?? null);
            $url = null === $valid ? null : PortalLinks::projectUrl($issuers, $valid);
            if (null !== $url) {
                $reply['project_url'] = $url;
            }
        }

        return $reply;
    }
}
