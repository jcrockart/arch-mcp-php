<?php

namespace ArchMcp;

use Psr\Log\LoggerInterface;

/**
 * Tool implementations for the /projects address (Slice 2 of
 * claude/proposal-arch-mcp-oauth-projects-connector.md).
 *
 * Every method here mirrors one of ArchTools.php's own tool methods,
 * with a required `slug` parameter added in front. ArchTools.php itself
 * is UNCHANGED by this slice — deliberately: it is the security-
 * reviewed boundary for every existing token-per-profile connector
 * (/project/<name>, /group/<name>/<sub>), and forking or editing it to
 * grow a second, per-call-profile mode would have widened the diff on
 * exactly the file everyone with the most reason to be careful here
 * already trusts. Instead, each method below resolves a fresh profile
 * for the given slug via PortalProjectResolver, builds a throwaway
 * `ArchTools` instance from it (cheap — the constructor just copies
 * scalars out of an array), and delegates. One ArchTools instance per
 * TOOL CALL here, versus one per HTTP REQUEST on every other address —
 * that's the entire shape of the change this slice needed.
 *
 * IMPORTANT ASYMMETRY, found while building this (not a retrofit): on
 * every other address, public/index.php only ADVERTISES a tool (adds it
 * to the MCP tool list) when the resolved profile grants it — lane
 * membership, pull_allowed, push_allowed, db_apply_allowed all also have
 * a genuine runtime backstop inside ArchTools.php itself, so an unlisted
 * tool invoked directly by name still fails closed. `session_tools` is
 * the ONE exception: grep ArchTools.php and it is never read there at
 * all — today, the advertised-list filter in index.php IS the entire
 * enforcement for that flag. /projects has no meaningful advertised-list
 * filter to begin with (eligibility depends on which slug a given call
 * names, decided per call, not per HTTP request — see below), so if this
 * class didn't add its own check, a project with session_tools=false
 * would be fully able to drive its session/codegen tools through this
 * address even though the same profile can't reach them through
 * /project/<name>. requireSessionTools() below is that missing runtime
 * backstop, added here specifically because /projects is the first
 * place this gap would actually be reachable.
 *
 * WHY EVERY TOOL IS UNCONDITIONALLY REGISTERED for this address (see
 * public/index.php's /projects branch): the two-layer model elsewhere in
 * this codebase — "the advertised list is a filter, the runtime check is
 * the backstop, both required" — assumes the profile, and therefore what
 * to advertise, is known at request start. For /projects it isn't: the
 * bearer token authenticates a PORTAL USER, and which project (and thus
 * which lanes/flags) a call concerns is only known once that call names
 * a slug. So for this one address the runtime check carries the full
 * weight alone, for every flag — which is exactly why
 * requireSessionTools() below exists rather than being skipped as
 * redundant.
 */
final class ProjectsTools
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $portalUserId,
    ) {
    }

    /**
     * Resolve a slug to a live ArchTools instance, or a process-shaped
     * (exit_code/stdout/stderr) error for tools whose ArchTools
     * counterpart returns that shape.
     *
     * @return ArchTools|array{exit_code: int, stdout: string, stderr: string}
     */
    private function forSlugProcess(string $slug): ArchTools|array
    {
        $profile = PortalProjectResolver::resolve($this->portalUserId, $slug, $this->logger);
        if (null === $profile) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => "no such project '{$slug}', or not accessible to this account"];
        }

        return new ArchTools($this->logger, $profile);
    }

    /**
     * Same resolution, for tools whose ArchTools counterpart returns the
     * other shape family (success/error).
     *
     * @return ArchTools|array{success: false, error: string}
     */
    private function forSlugFile(string $slug): ArchTools|array
    {
        $profile = PortalProjectResolver::resolve($this->portalUserId, $slug, $this->logger);
        if (null === $profile) {
            return ['success' => false, 'error' => "no such project '{$slug}', or not accessible to this account"];
        }

        return new ArchTools($this->logger, $profile);
    }

    /**
     * The missing runtime backstop for session_tools — see this class's
     * own top docblock. Re-resolves nothing; just re-checks the flag on
     * an already-resolved profile before a session/codegen method runs.
     * Only ArchTools instances built by THIS class ever need this check
     * (ArchTools itself has no session_tools property to check).
     */
    private function requireSessionTools(string $slug, ArchTools $tools): ?array
    {
        $profile = PortalProjectResolver::resolve($this->portalUserId, $slug, $this->logger);
        if (null === $profile || !$profile['session_tools']) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => "this project's profile does not grant session tools"];
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Session / codegen — gated by session_tools, checked explicitly.
    // -----------------------------------------------------------------

    public function archSessionStart(string $slug, string $name = '', string $lane = 'metadata'): array
    {
        $t = $this->forSlugProcess($slug);
        if (\is_array($t)) {
            return $t;
        }
        if (null !== ($err = $this->requireSessionTools($slug, $t))) {
            return $err;
        }

        return $t->archSessionStart($name, $lane);
    }

    public function archCodegenPreview(string $slug): array
    {
        $t = $this->forSlugProcess($slug);
        if (\is_array($t)) {
            return $t;
        }
        if (null !== ($err = $this->requireSessionTools($slug, $t))) {
            return $err;
        }

        return $t->archCodegenPreview();
    }

    public function archSessionCommit(string $slug, string $bump = 'patch'): array
    {
        $t = $this->forSlugProcess($slug);
        if (\is_array($t)) {
            return $t;
        }
        if (null !== ($err = $this->requireSessionTools($slug, $t))) {
            return $err;
        }

        return $t->archSessionCommit($bump);
    }

    public function archSessionDiscard(string $slug): array
    {
        $t = $this->forSlugProcess($slug);
        if (\is_array($t)) {
            return $t;
        }
        if (null !== ($err = $this->requireSessionTools($slug, $t))) {
            return $err;
        }

        return $t->archSessionDiscard();
    }

    public function archSessionStatus(string $slug): array
    {
        $t = $this->forSlugProcess($slug);
        if (\is_array($t)) {
            return $t;
        }
        if (null !== ($err = $this->requireSessionTools($slug, $t))) {
            return $err;
        }

        return $t->archSessionStatus();
    }

    public function archSessionWriteFile(string $slug, string $path, string $content): array
    {
        $t = $this->forSlugFile($slug);
        if (\is_array($t)) {
            return $t;
        }
        if (null !== ($err = $this->requireSessionTools($slug, $t))) {
            return ['success' => false, 'error' => $err['stderr']];
        }

        return $t->archSessionWriteFile($path, $content);
    }

    public function archSessionReadFile(string $slug, string $path): array
    {
        $t = $this->forSlugFile($slug);
        if (\is_array($t)) {
            return $t;
        }
        if (null !== ($err = $this->requireSessionTools($slug, $t))) {
            return ['success' => false, 'error' => $err['stderr']];
        }

        return $t->archSessionReadFile($path);
    }

    public function archSessionListFiles(string $slug): array
    {
        $t = $this->forSlugFile($slug);
        if (\is_array($t)) {
            return $t;
        }
        if (null !== ($err = $this->requireSessionTools($slug, $t))) {
            return ['success' => false, 'error' => $err['stderr']];
        }

        return $t->archSessionListFiles();
    }

    // -----------------------------------------------------------------
    // Code lane — session-gated inside ArchTools itself already
    // (archCodeWriteFile checks for an active session on the code
    // lane); lane membership is ArchTools' own backstop too. No extra
    // gate needed here beyond profile resolution.
    // -----------------------------------------------------------------

    public function archCodeWriteFile(string $slug, string $path, string $content): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archCodeWriteFile($path, $content);
    }

    public function archCodeReadFile(string $slug, string $path): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archCodeReadFile($path);
    }

    public function archCodeListFiles(string $slug): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archCodeListFiles();
    }

    // -----------------------------------------------------------------
    // Site lane — not session-gated, matching ArchTools' own design.
    // -----------------------------------------------------------------

    public function archSiteWriteFile(string $slug, string $path, string $content): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archSiteWriteFile($path, $content);
    }

    public function archSiteReadFile(string $slug, string $path): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archSiteReadFile($path);
    }

    public function archSiteListFiles(string $slug): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archSiteListFiles();
    }

    // -----------------------------------------------------------------
    // Assets lane — not session-gated, matching ArchTools' own design.
    // -----------------------------------------------------------------

    public function archAssetsWriteFile(string $slug, string $path, string $content): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archAssetsWriteFile($path, $content);
    }

    public function archAssetsReadFile(string $slug, string $path): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archAssetsReadFile($path);
    }

    public function archAssetsListFiles(string $slug): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archAssetsListFiles();
    }

    // -----------------------------------------------------------------
    // Core git — read-only subcommands are unconditional (same as
    // ArchTools/index.php for any core-lane profile); pull/push are
    // each gated inside ArchTools itself via pull_allowed/push_allowed
    // — no extra check needed here.
    // -----------------------------------------------------------------

    public function archCoreGitStatus(string $slug): array
    {
        $t = $this->forSlugProcess($slug);

        return \is_array($t) ? $t : $t->archCoreGitStatus();
    }

    public function archCoreGitDiff(string $slug, string $ref = 'HEAD', string $path = ''): array
    {
        $t = $this->forSlugProcess($slug);

        return \is_array($t) ? $t : $t->archCoreGitDiff($ref, $path);
    }

    public function archCoreGitLog(string $slug, int $count = 10, string $path = ''): array
    {
        $t = $this->forSlugProcess($slug);

        return \is_array($t) ? $t : $t->archCoreGitLog($count, $path);
    }

    public function archCoreGitShow(string $slug, string $ref, string $path): array
    {
        $t = $this->forSlugProcess($slug);

        return \is_array($t) ? $t : $t->archCoreGitShow($ref, $path);
    }

    public function archCoreGitFetch(string $slug): array
    {
        $t = $this->forSlugProcess($slug);

        return \is_array($t) ? $t : $t->archCoreGitFetch();
    }

    public function archCoreGitPullFastForward(string $slug): array
    {
        $t = $this->forSlugProcess($slug);

        return \is_array($t) ? $t : $t->archCoreGitPullFastForward();
    }

    public function archCoreGitPushOrigin(string $slug, string $expectedHead): array
    {
        $t = $this->forSlugProcess($slug);

        return \is_array($t) ? $t : $t->archCoreGitPushOrigin($expectedHead);
    }

    // -----------------------------------------------------------------
    // DB migrations — pending is read-only/unconditional; apply is
    // gated inside ArchTools itself via db_apply_allowed.
    // -----------------------------------------------------------------

    public function archCoreDbPendingMigrations(string $slug): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archCoreDbPendingMigrations();
    }

    public function archCoreDbApplyMigrations(string $slug): array
    {
        $t = $this->forSlugFile($slug);

        return \is_array($t) ? $t : $t->archCoreDbApplyMigrations();
    }

    // NOTE: archBootstrapFillInceptionRow is deliberately NOT exposed
    // here. bootstrap_fill_allowed/bootstrap_fill_portal_url aren't
    // columns on project_profiles (see db/schema.sql) — that capability
    // stays profiles.json-only, out of scope for this slice. Revisit if
    // /projects ever needs it.
}
