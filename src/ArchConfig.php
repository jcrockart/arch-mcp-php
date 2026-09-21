<?php

namespace ArchMcp;

/**
 * Deployment-specific configuration. REPO_PATH is the one value that
 * must be edited per host - it should point at a durable, git-backed
 * clone of the shared ARCH-COLLAB reference-implementation repo living
 * on this host's own persistent disk (not inside any agent's sandbox).
 */
final class ArchConfig
{
    public const REPO_PATH = '/home/crockart/arch-collab-core';

    /**
     * Where archBootstrapFillInceptionRow() (ArchTools.php) posts a
     * completed bootstrap interview back to arch-portal — see
     * claude/proposal-portal-first-project-inception.md (ARCH-COLLAB
     * Claude Project). Portal, not this server, is the sole writer of
     * its own `projects` table; this is a plain HTTPS call to Portal's
     * own endpoint, the same shape as ArchMcpClient.php on Portal's side
     * calling into this server, just reversed.
     *
     * EDIT THIS on deployment, same as REPO_PATH — this value is
     * environment-specific (staging host posts to staging Portal, prod
     * host posts to production Portal) and is not derived from anything
     * else in this file. Currently set to staging while this capability
     * is being built and proven there; must be changed to
     * https://portal.crockart.com.au/api_inception_fill.php before (or
     * as part of) promoting this to production — the gated
     * bootstrap_fill_allowed flag being off by default everywhere is
     * the actual safety net if that step is missed, not this comment.
     */
    public const PORTAL_INCEPTION_FILL_URL = 'https://staging-portal.crockart.com.au/api_inception_fill.php';
}
