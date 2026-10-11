<?php

namespace ArchMcp;

require_once __DIR__.'/BindingGuard.php';
require_once __DIR__.'/ClaudeProjectId.php';

/**
 * Runs the Claude project binding check for one tool call: reads the registry
 * (only for an ordinary person, never for an exempt caller), asks BindingGuard,
 * writes the log line, and hands back either null (go ahead) or the message to
 * give the caller (blocked). ProjectsTools calls this once per tool call, after
 * membership has been resolved, so a non-member never learns anything about a
 * project's binding state.
 *
 * The log line is a warning, carries the slug, the Portal user, the outcome and
 * the mode, and says whether the call was blocked. A Claude project ID appears
 * in it only as its first 8 characters.
 */
final class BindingGate
{
    /**
     * @param callable(string): (array{registered: list<string>, grandfathered: bool}|null) $fetch the registry read for a slug
     *
     * @return string|null the error to return to the caller, or null to let the call proceed
     */
    public static function check(\Psr\Log\LoggerInterface $logger, string $mode, string $caller, string $portalUserId, string $slug, string $claudeProjectId, callable $fetch): ?string
    {
        $registry = BindingGuard::CALLER_USER === $caller ? $fetch($slug) : ['registered' => [], 'grandfathered' => false];
        $decision = BindingGuard::decide(
            $mode,
            $caller,
            $claudeProjectId,
            \is_array($registry) ? $registry['registered'] : null,
            \is_array($registry) && $registry['grandfathered'],
        );

        if ($decision['log']) {
            $id = ClaudeProjectId::normalize($claudeProjectId);
            $logger->warning('binding_check', [
                'slug' => $slug,
                'portal_user_id' => $portalUserId,
                'outcome' => $decision['outcome'],
                'mode' => $mode,
                'blocked' => !$decision['allow'],
                'claude_project' => null === $id ? null : substr($id, 0, 8),
            ]);
        }

        return $decision['allow'] ? null : $decision['error'];
    }
}
