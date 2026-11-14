<?php

namespace ArchMcp;

require_once __DIR__.'/PortalCaller.php';
require_once __DIR__.'/PortalLinks.php';
require_once __DIR__.'/CommitPin.php';

/**
 * The chat's promote call to Portal's api_promote.php (step 3 of the chat
 * promote-to-production job; contract: P6 in the working doc). Two Portal actions:
 * `status` (read-only: Portal runs its judging and changes nothing) and `promote`.
 *
 * PORTAL DECIDES. Who the caller is, the Owner role, the project's chat_promote_allowed
 * flag, the clean fast-forward, the migrations, arch-gate.json, the apply-before-pull
 * order and the audit log are all Portal's. This class only:
 *   - refuses a bad slug, a bad head or a bad request id before any call (the head must
 *     be the full 40-character commit id, the same rule as CommitPin, so a flag, a short
 *     id or a branch can never be sent as one);
 *   - makes exactly ONE Portal call per run and never retries;
 *   - hands Portal's reply back with its own keys untouched, adding a `next` line that
 *     tells the chat what to do, and removing a `promote_url` that is not a safe link
 *     (see PortalLinks::sameHostUrl);
 *   - for a call with no usable answer, says so in its own words. For a promote that is
 *     the one case where the outcome is unknown, so the chat is told it MAY have
 *     happened and not to promote again until it has checked.
 *
 * The caller's token is never seen here: the PortalCaller holds it. Pure apart from
 * the one Portal call.
 */
final class PromoteRequest
{
    public const HEAD_RULE = "expectedHead must be the full 40-character lowercase commit id of origin's head, as arch_prod_state shows it in origin_head (not a short id, a branch or a tag)";

    private const MAX_SLUG_BYTES = 200;
    private const MAX_CLAUDE_PROJECT_ID = 100;

    private const DO_NOT_WORK_AROUND = 'Do not retry, do not try another commit or another route, and do not run git pull or db_migrate_prod yourself; promoting is the person\'s call in Portal.';

    /**
     * @param bool                 $checkOnly true: Portal's read-only `status` action (changes nothing)
     * @param string               $requestId a UUID made once per ask; sent with a promote only
     * @param array<int, mixed>    $issuers   OAuthBearer::trustedIssuerUrlsForEnvironment($environment)
     *
     * @return array<string, mixed>
     */
    public static function run(PortalCaller $portal, string $slug, string $expectedHead, bool $checkOnly, string $requestId, string $claudeProjectId, array $issuers = []): array
    {
        $slug = self::cleanSlug($slug);
        if (null === $slug) {
            return ['success' => false, 'code' => 'invalid', 'error' => 'slug is required'];
        }
        if (!CommitPin::isValid($expectedHead)) {
            return ['success' => false, 'code' => 'invalid', 'error' => self::HEAD_RULE];
        }
        if (!$checkOnly && 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $requestId)) {
            return ['success' => false, 'code' => 'invalid', 'error' => 'a promote needs a request id that is a UUID'];
        }

        $fields = ['expectedHead' => $expectedHead];
        if (!$checkOnly) {
            $fields['requestId'] = $requestId;
        }
        $cp = self::cleanClaudeProjectId($claudeProjectId);
        if ('' !== $cp) {
            $fields['claudeProjectId'] = $cp;
        }

        $reply = $portal->call($checkOnly ? 'status' : 'promote', $slug, $fields);

        if (self::hasNoUsableAnswer($reply)) {
            if ($checkOnly) {
                return ['success' => false, 'code' => 'server', 'error' => 'Could not get an answer from Portal. This check changed nothing; try again in a little while.'];
            }

            return [
                'success' => false,
                'code' => 'server',
                'may_have_promoted' => true,
                'error' => 'Could not get a confirmed answer from Portal. The promote MAY have happened. Do not promote again. Call arch_promote with checkOnly true, and arch_prod_state, to see whether production now runs commit '.$expectedHead.'.',
            ];
        }

        // A link goes to a person, so a Portal-supplied one must pass the same-host check.
        $link = null;
        if (\array_key_exists('promote_url', $reply)) {
            $link = PortalLinks::sameHostUrl($issuers, $reply['promote_url']);
            if (null === $link) {
                unset($reply['promote_url']);
            } else {
                $reply['promote_url'] = $link;
            }
        }

        $result = $reply['result'] ?? null;
        if (!\is_string($result)) {
            // A failure Portal gave a code for but no decided result (not signed in, not found,
            // not an Owner): exactly as Portal sent it.
            return $reply;
        }

        if ('promoted' !== $result && null === $link) {
            $project = PortalLinks::projectUrl($issuers, $slug);
            if (null !== $project && !isset($reply['project_url'])) {
                $reply['project_url'] = $project;
            }
        }
        $reply['next'] = self::nextLine($reply, $result, $checkOnly, $expectedHead, $link ?? (\is_string($reply['project_url'] ?? null) ? $reply['project_url'] : null));

        return $reply;
    }

    /** A random version 4 UUID, made once per chat ask. */
    public static function newRequestId(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12);
    }

    /**
     * @param array<string, mixed> $reply
     */
    private static function nextLine(array $reply, string $result, bool $checkOnly, string $head, ?string $link): string
    {
        if ($checkOnly && true === ($reply['would_promote'] ?? null)) {
            return 'This was only a check: nothing was changed. Portal would promote this head. Ask the person for an explicit yes to promote this exact commit, then call arch_promote again without checkOnly.';
        }
        $where = null !== $link
            ? 'this link: '.$link
            : 'the project page link in portal.project_url from arch_project_info';

        if ('promoted' === $result && !$checkOnly) {
            $dep = \is_array($reply['deployed'] ?? null) ? $reply['deployed'] : [];
            $commit = \is_string($dep['commit'] ?? null) && '' !== $dep['commit'] ? $dep['commit'] : $head;
            $version = \is_string($dep['version'] ?? null) && '' !== $dep['version'] ? ' (version '.$dep['version'].')' : '';

            return 'Production now runs commit '.$commit.$version.'. Tell the person what was put live. Nothing else is needed.';
        }
        if ('already_promoted' === $result) {
            return 'Production already runs this commit, so nothing was changed. Tell the person.';
        }
        if ('failed' === $result) {
            return 'Portal could not finish this promotion and production may be partly changed: see migrations and state in this reply. Do not try to fix it from chat. Show the person Portal\'s message and '.$where.'; an Owner resolves it in Portal. '.self::DO_NOT_WORK_AROUND;
        }
        if ('refused' === $result) {
            return 'Portal did not promote and nothing was changed. Show the person Portal\'s message and '.$where.'. '.self::DO_NOT_WORK_AROUND;
        }

        return 'Portal returned result '.preg_replace('/[^A-Za-z0-9_-]/', '', $result).'. Show the person Portal\'s message and '.$where.'. '.self::DO_NOT_WORK_AROUND;
    }

    private static function cleanSlug(string $slug): ?string
    {
        $slug = trim($slug);
        if ('' === $slug || \strlen($slug) > self::MAX_SLUG_BYTES || 1 === preg_match('/[\x00-\x1F\x7F]/', $slug)) {
            return null;
        }

        return $slug;
    }

    private static function cleanClaudeProjectId(string $id): string
    {
        $id = (string) preg_replace('/[\x00-\x1F\x7F]/', '', trim($id));

        return mb_substr($id, 0, self::MAX_CLAUDE_PROJECT_ID, 'UTF-8');
    }

    /**
     * arch-mcp's own transport and bad-reply failures use code `server`, which Portal's
     * list does not. A reply with no decided result and no code at all cannot be read
     * safely either.
     *
     * @param array<string, mixed> $reply
     */
    private static function hasNoUsableAnswer(array $reply): bool
    {
        if (true === ($reply['success'] ?? false) || \is_string($reply['result'] ?? null)) {
            return false;
        }
        $code = $reply['code'] ?? null;

        return !\is_string($code) || '' === $code || 'server' === $code;
    }
}
