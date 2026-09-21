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
}
