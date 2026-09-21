<?php

/*
 * ARCH Agent-Connection MCP server — real, hosted implementation.
 *
 * Implements the tool definitions specified in agent-connection-layer.md /
 * Codegen CLI Design §8. Every tool below is a thin wrapper: it shells out
 * to arch.py exactly as a human would from the command line, and returns
 * its stdout/exit code. No git logic is duplicated here.
 *
 * DESIGN NOTE (Codegen CLI Design §7.3, REVISITED 2026-09-19): this
 * server's repo is a durable local clone (git-backed, on this host's
 * persistent disk, cloned from the shared remote) — NOT an ephemeral
 * agent sandbox. §7.3 originally kept pushing to the shared remote out
 * of arch_session_commit deliberately, citing §9 item 3's credential-
 * isolation rationale ("this server holds no agent-readable git
 * credential"). That premise no longer held even before today: since
 * archCoreGitPushOrigin() was added (Codegen CLI Design §8, gated by
 * per-profile push_allowed/push_branch), this server has run a fully
 * credentialed, agent-reachable `git push origin` for every profile
 * with push access — the credential-isolation boundary now lives at
 * the profile/token level (push_allowed opt-in, one push_branch,
 * fast-forward-only, no --force), not at "this file never pushes."
 * See claude/note-2026-09-19-push-on-commit.md for the full decision
 * record. Given that, arch_session_commit() now folds in the exact
 * same push (same branch, same credential, same fast-forward-only git
 * default) immediately after a successful local commit, for any
 * profile with push_allowed — see its docblock below. No new
 * credential surface is introduced by this; it wires an already-
 * built, already-gated, already-tested tool into the commit flow
 * instead of leaving it as a second manual step. Git Setup & Access
 * Policy §10's human-paced-deployment precedent still governs actual
 * promotion to production (a separate, review-gated pull performed by
 * a human via the ARCH-COLLAB Portal), which this change does not
 * touch.
 *
 * DEPLOYMENT NOTE (2026-09-19): landing this feature itself required a
 * one-time manual bootstrap. mcp.crockart.com.au serves this class from
 * arch-mcp-prod's own checkout (/home/crockart/arch-collab/mcp), not
 * this staging checkout -- so archSessionCommit()'s new push-on-commit
 * step could not push itself into production: the live server was still
 * running the pre-push version of this method, which had no push logic
 * to reach origin with in the first place. James pushed this staging
 * branch to origin and merged it into the prod checkout by hand, once,
 * from a terminal. Every push-on-commit call after that bootstrap is the
 * normal, fully agent-driven path this docblock describes.
 */

namespace ArchMcp;

use Psr\Log\LoggerInterface;

final class ArchTools
{
    private string $repoPath;
    private string $archPath;
    private string $sessionFile;
    private string $phpBinary;

    /** @var array<string, string> lane name => subdirectory under repoPath */
    private array $lanes;

    /** @var list<string>|null lowercase extensions writable by this token; null = unrestricted */
    private ?array $writeExtensions;

    /** Whether this profile may run archCoreGitPullFastForward(). Off by default. */
    private bool $pullAllowed;

    /** Branch this profile's gated pull fast-forwards to, e.g. "main". */
    private string $pullBranch;

    /** Whether this profile may run archCoreGitPushOrigin(). Off by default. */
    private bool $pushAllowed;

    /** Branch this profile's gated push pushes to, e.g. "main". */
    private string $pushBranch;

    /**
     * Whether this profile may run archCoreDbApplyMigrations(). Off by
     * default -- same opt-in pattern as pullAllowed/pushAllowed. Added
     * 2026-09-17 alongside the migration tooling itself; without this
     * check, any profile with a core lane could apply policy-eligible
     * migrations to its project's live database, with no per-project
     * opt-in at all. archCoreDbPendingMigrations() (read-only) is NOT
     * gated by this -- it carries no more risk than the other unconditional
     * core-lane read tools (status/diff/log/show/fetch).
     */
    private bool $dbApplyAllowed;

    /**
     * Whether this profile may run archBootstrapFillInceptionRow(). Off by
     * default -- same opt-in pattern as the other gated flags above.
     */
    private bool $bootstrapFillAllowed;

    private ?string $bootstrapFillPortalUrl;

    /**
     * @param array{label: string, root: string, lanes: array<string, string>, session_tools: bool}|null $profile
     *        Resolved token profile (ArchProfiles::resolve). Null is
     *        accepted only so existing unit tests that construct this
     *        class directly keep working; in that case it falls back to
     *        the historical single-repo behaviour. public/index.php
     *        always passes a real profile and refuses the request when
     *        it cannot resolve one, so null never occurs in production.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        ?array $profile = null,
    ) {
        $this->repoPath = $profile['root'] ?? ArchConfig::REPO_PATH;
        $this->lanes = $profile['lanes'] ?? ['metadata' => 'metadata', 'site' => 'site'];
        $this->writeExtensions = $profile['write_extensions'] ?? null;
        $this->pullAllowed = $profile['pull_allowed'] ?? false;
        $this->pullBranch = $profile['pull_branch'] ?? 'main';
        $this->pushAllowed = $profile['push_allowed'] ?? false;
        $this->pushBranch = $profile['push_branch'] ?? 'main';
        $this->dbApplyAllowed = $profile['db_apply_allowed'] ?? false;
        $this->bootstrapFillAllowed = $profile['bootstrap_fill_allowed'] ?? false;
        $this->bootstrapFillPortalUrl = is_string($profile['bootstrap_fill_portal_url'] ?? null) ? $profile['bootstrap_fill_portal_url'] : null;
        $this->archPath = $this->repoPath.'/arch.py';
        $this->sessionFile = $this->repoPath.'/.arch-session.json';
        $this->phpBinary = \PHP_BINARY;
    }

    /**
     * Start a new ARCH development session (a git branch off main).
     *
     * Origination is unrestricted within a session — no per-value approval
     * gate (22-ARCH-GOVERNANCE §22.7). Call this before generating or
     * editing any metadata.
     *
     * @param string $name optional session name; auto-generated (timestamp-based) if omitted
     * @param string $lane optional lane name (default 'metadata'); must be one
     * the caller's profile grants in arch-mcp-secrets/profiles.json, or the
     * session refuses to start
     *
     * @return array<string, mixed> exit_code, stdout, stderr from arch.py
     */
    public function archSessionStart(string $name = '', string $lane = 'metadata'): array
    {
        if (!isset($this->lanes[$lane])) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => "this token has no '{$lane}' lane"];
        }

        $args = ['session', 'start'];
        if ('' !== $name) {
            $args[] = $name;
        }
        $args[] = "--lane={$lane}";

        return $this->runArch($args);
    }

    /**
     * Run disposable, fuzzy-state codegen against the current session's
     * in-progress metadata.
     *
     * No determinism guarantee, never blocks on validation errors
     * (warn-only). Never a substitute for arch_session_commit — preview
     * output is never eligible for canonical/downstream use.
     *
     * @return array<string, mixed> exit_code, stdout, stderr from arch.py
     */
    public function archCodegenPreview(): array
    {
        return $this->runArch(['codegen', 'preview']);
    }

    /**
     * Attempt to commit the active session.
     *
     * Runs full canonical validation first (the one hard, non-optional
     * gate — constitution rule 5). On pass: atomic merge to main, version
     * bump, canonical codegen runs, session retires. On fail: commit is
     * aborted, branch left open and unchanged, main untouched — fix the
     * errors and call this again, or call arch_session_discard.
     *
     * Always merges to this host's own local `main` first, exactly as
     * before. REVISITED 2026-09-19 (see this file's header note and
     * claude/note-2026-09-19-push-on-commit.md): if the resolved profile
     * has push_allowed, a successful local commit is now immediately
     * followed by the same push archCoreGitPushOrigin() performs — a
     * plain `git push origin <push_branch>`, fast-forward-only by git's
     * own default, never --force. This reuses that method's exact git
     * invocation and credential rather than adding a new one.
     *
     * The commit above is the durable, safety-gated step (full
     * validation, atomic merge); the push is comparatively low-risk
     * (fast-forward-only, single fixed branch) and is never allowed to
     * retroactively fail the commit. So: a push failure (no push_allowed,
     * network error, non-fast-forward, no remote configured, etc.) is
     * reported back in the 'push' key alongside the normal commit result
     * fields, and never changes exit_code or otherwise makes an
     * already-successful local commit look like it failed. Callers that
     * care whether the push landed should check the 'push' key
     * ('pushed' => true/false) rather than exit_code alone. A profile
     * with push_allowed = false (the default) gets no 'push' key at all
     * — behaviour is identical to before this change.
     *
     * @param string $bump one of "major", "minor", "patch" (default "patch"), per 22.5 Semantic Versioning Policy
     *
     * @return array<string, mixed> exit_code, stdout, stderr from arch.py, plus an optional 'push' key (see above)
     */
    public function archSessionCommit(string $bump = 'patch'): array
    {
        if (!\in_array($bump, ['major', 'minor', 'patch'], true)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => "invalid bump '{$bump}'"];
        }

        $result = $this->runArch(['session', 'commit', "--{$bump}"]);

        if (0 !== $result['exit_code'] || !$this->pushAllowed) {
            return $result;
        }

        // Commit is already durable locally at this point regardless of
        // what follows — nothing below may change $result['exit_code'].
        $headResult = $this->execGit(['rev-parse', 'HEAD'], $this->repoPath);
        if (0 !== $headResult['exit_code']) {
            $result['push'] = [
                'pushed' => false,
                'branch' => $this->pushBranch,
                'stderr' => 'commit succeeded but could not resolve new HEAD to push: '.$headResult['stderr'],
            ];

            return $result;
        }
        $head = trim($headResult['stdout']);

        $push = $this->execGit(['push', 'origin', $this->pushBranch], $this->repoPath);
        $result['push'] = [
            'pushed' => 0 === $push['exit_code'],
            'branch' => $this->pushBranch,
            'head' => $head,
            'exit_code' => $push['exit_code'],
            'stdout' => $push['stdout'],
            'stderr' => $push['stderr'],
        ];

        $this->logger->info('git push (session commit, auto).', [
            'branch' => $this->pushBranch,
            'head' => $head,
            'exit_code' => $push['exit_code'],
        ]);

        return $result;
    }

    /**
     * Discard the active session.
     *
     * Soft-delete: branch renamed under discarded/ prefix with a 7-day
     * grace window (CLI Design §7.2, PROPOSED — not yet confirmed by
     * James). No trace on main either way. Confirm with the user before
     * calling this if the session contains work they have not reviewed.
     *
     * @return array<string, mixed> exit_code, stdout, stderr from arch.py
     */
    public function archSessionDiscard(): array
    {
        return $this->runArch(['session', 'discard']);
    }

    /**
     * Report whether a session is currently active, its branch name, and
     * how long it has been open.
     *
     * Read-only — always safe to call without side effects.
     *
     * NOTE: arch.py itself has no 'status' subcommand (the same gap found
     * while building the sandbox prototype of this server) — this reads
     * .arch-session.json directly instead of shelling out, which is the
     * one deliberate exception to "no logic of its own" in this file,
     * since there is no CLI command yet to wrap.
     *
     * @return array<string, mixed>
     */
    public function archSessionStatus(): array
    {
        if (!is_file($this->sessionFile)) {
            return ['active' => false];
        }

        $session = json_decode((string) file_get_contents($this->sessionFile), true, flags: \JSON_THROW_ON_ERROR);
        $started = new \DateTimeImmutable($session['started_at']);
        $ageSeconds = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $started->getTimestamp();

        return [
            'active' => true,
            'branch' => $session['branch'],
            'base_commit' => $session['base_commit'],
            'started_at' => $session['started_at'],
            'age_seconds' => $ageSeconds,
            'recovered' => $session['recovered'] ?? false,
        ];
    }

    /**
     * Write (create or overwrite) a file inside the active session's
     * metadata tree.
     *
     * This is the missing piece that lets an agent actually do session
     * work (the free-form Preview-mode editing described in §6/§22.7)
     * through the MCP interface itself, rather than needing direct
     * filesystem/terminal access to this host. Origination stays
     * unrestricted per §22.7 — the only gate is still arch_session_commit.
     *
     * Requires an active session: with no session checked out, the
     * working tree is main's, and writing there directly would bypass
     * the whole session-as-branch model. Path is restricted to the
     * metadata/ subtree (the same 5 categories validate.py checks) so
     * this can never be used to touch arch.py, .git, or anything outside
     * the metadata this tool is meant for.
     *
     * @param string $path    relative path under metadata/, e.g. "entities/customer.json"
     * @param string $content full file content to write
     *
     * @return array<string, mixed>
     */
    public function archSessionWriteFile(string $path, string $content): array
    {
        if (!is_file($this->sessionFile)) {
            return ['success' => false, 'error' => 'no active session — call arch_session_start first'];
        }

        if (!$this->extensionAllowed($path)) {
            return ['success' => false, 'error' => "file type not permitted for this token: '{$path}'"];
        }

        $resolved = $this->resolveMetadataPath($path);
        if (null === $resolved) {
            return ['success' => false, 'error' => "invalid path '{$path}' — must stay inside metadata/"];
        }

        $dir = \dirname($resolved);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => "could not create directory for '{$path}'"];
        }

        if (false === file_put_contents($resolved, $content)) {
            return ['success' => false, 'error' => "failed to write '{$path}'"];
        }

        $this->logger->info('Session file written.', ['path' => $path, 'bytes' => \strlen($content)]);

        return ['success' => true, 'path' => $path, 'bytes' => \strlen($content)];
    }

    /**
     * Read a file's current content from the active session's working
     * tree (or from main, if no session is active — reading is safe
     * either way; only writes require an active session).
     *
     * @param string $path relative path under metadata/
     *
     * @return array<string, mixed>
     */
    public function archSessionReadFile(string $path): array
    {
        $resolved = $this->resolveMetadataPath($path);
        if (null === $resolved) {
            return ['success' => false, 'error' => "invalid path '{$path}' — must stay inside metadata/"];
        }

        if (!is_file($resolved)) {
            return ['success' => false, 'error' => "'{$path}' does not exist"];
        }

        $content = file_get_contents($resolved);
        if (false === $content) {
            return ['success' => false, 'error' => "failed to read '{$path}'"];
        }

        return ['success' => true, 'path' => $path, 'content' => $content];
    }

    /**
     * List every file currently in the metadata tree (all 5 categories:
     * project.config.json, entities/, domain/, workflows/, integrations/),
     * so an agent can see current state without guessing paths.
     *
     * @return array<string, mixed>
     */
    public function archSessionListFiles(): array
    {
        // Was: $this->repoPath.'/metadata' — a hardcoded subdirectory that
        // ignored the token's lane map completely. Two bugs in one: a
        // profile whose 'metadata' lane is the root itself listed a
        // nonexistent subdirectory and returned an empty list while
        // reporting success, and a token never granted this lane would
        // still have had a directory listed for it. Fixed 2026-09-01.
        $root = $this->laneRoot('metadata');
        if (null === $root || !is_dir($root)) {
            return ['success' => true, 'files' => []];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/');
            // Same dot-component rule the read/write paths enforce. Without
            // this the listing still enumerated every .git/ internal by
            // name — blocked from being READ, but fully disclosed, which
            // is both a leak and an unusable wall of output when a lane is
            // rooted at a checkout.
            if ($this->hasDotComponent($rel)) {
                continue;
            }
            $files[] = $rel;
        }
        sort($files);

        return ['success' => true, 'files' => $files];
    }

    /**
     * Resolves a caller-supplied relative path to an absolute path inside
     * metadata/, refusing anything that would escape it (../, symlink
     * tricks, absolute paths). Returns null on any violation.
     */
    private function resolveMetadataPath(string $relativePath): ?string
    {
        return $this->resolveScopedPath($relativePath, 'metadata');
    }

    /**
     * Report the active session's lane, or '' if none is active.
     * Mirrors arch.py's own default of 'metadata' for a session file
     * with no explicit lane recorded.
     */
    private function activeSessionLane(): string
    {
        if (!is_file($this->sessionFile)) {
            return '';
        }
        $session = json_decode((string) file_get_contents($this->sessionFile), true, flags: \JSON_THROW_ON_ERROR);

        return $session['lane'] ?? 'metadata';
    }

    /**
     * Added 2026-09-16 — exposes arch.py's `code` lane (built and proven
     * on core and mcp, 2026-09-07, see claude/proposal-code-lane-gate-
     * design.md) over MCP, so landing a real code-lane commit no longer
     * requires driving a terminal by hand. Mirrors archSessionWriteFile's
     * exact shape, with one extra check that doesn't exist anywhere else
     * in this file: confirming the active session's lane actually IS
     * 'code', not just that some session is active (see
     * claude/proposal-arch-mcp-code-lane.md).
     *
     * @param string $path    relative path inside this token's code lane
     *                        (see resolveCodePath — NOT a fixed
     *                        subdirectory like metadata/site/assets)
     * @param string $content full file content to write
     *
     * @return array<string, mixed>
     */
    public function archCodeWriteFile(string $path, string $content): array
    {
        if (!is_file($this->sessionFile)) {
            return ['success' => false, 'error' => 'no active session — call arch_session_start with lane=code first'];
        }
        if ('code' !== $this->activeSessionLane()) {
            return ['success' => false, 'error' => 'active session is not on the code lane'];
        }
        if (!$this->extensionAllowed($path)) {
            return ['success' => false, 'error' => "file type not permitted for this token: '{$path}'"];
        }

        $resolved = $this->resolveCodePath($path);
        if (null === $resolved) {
            return ['success' => false, 'error' => "invalid path '{$path}' — must stay inside this token's code lane"];
        }

        $dir = \dirname($resolved);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => "could not create directory for '{$path}'"];
        }

        if (false === file_put_contents($resolved, $content)) {
            return ['success' => false, 'error' => "failed to write '{$path}'"];
        }

        $this->logger->info('Code-lane file written.', ['path' => $path, 'bytes' => \strlen($content)]);

        return ['success' => true, 'path' => $path, 'bytes' => \strlen($content)];
    }

    /**
     * Added 2026-09-16 alongside archCodeWriteFile — see that method's
     * docblock. Read works with no active session, same as
     * archSessionReadFile/archSiteReadFile ("reading is safe either way;
     * only writes require an active session").
     *
     * @param string $path relative path inside this token's code lane
     *
     * @return array<string, mixed>
     */
    public function archCodeReadFile(string $path): array
    {
        $resolved = $this->resolveCodePath($path);
        if (null === $resolved) {
            return ['success' => false, 'error' => "invalid path '{$path}' — must stay inside this token's code lane"];
        }

        if (!is_file($resolved)) {
            return ['success' => false, 'error' => "'{$path}' does not exist"];
        }

        $content = file_get_contents($resolved);
        if (false === $content) {
            return ['success' => false, 'error' => "failed to read '{$path}'"];
        }

        return ['success' => true, 'path' => $path, 'content' => $content];
    }

    /**
     * Added 2026-09-16 alongside archCodeWriteFile — lists every file
     * currently reachable through this token's code lane, i.e. every
     * file under any of arch-gate.json's code.stage.paths entries (a
     * flat subdirectory root like metadata/site/assets doesn't fit —
     * see resolveCodePath).
     *
     * @return array<string, mixed>
     */
    public function archCodeListFiles(): array
    {
        if (!isset($this->lanes['code'])) {
            return ['success' => true, 'files' => []];
        }

        $gatePath = $this->repoPath.'/arch-gate.json';
        if (!is_file($gatePath)) {
            return ['success' => true, 'files' => []];
        }
        $gateConfig = json_decode((string) file_get_contents($gatePath), true);
        $stagePaths = $gateConfig['code']['stage']['paths'] ?? [];
        if (!\is_array($stagePaths)) {
            return ['success' => true, 'files' => []];
        }

        $files = [];
        foreach ($stagePaths as $stagePath) {
            if (!\is_string($stagePath)) {
                continue;
            }
            $trimmed = trim($stagePath, '/');
            $abs = $this->repoPath.'/'.$trimmed;
            if (is_file($abs)) {
                $files[] = $trimmed;
                continue;
            }
            if (!is_dir($abs)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $rel = ltrim(str_replace($this->repoPath, '', $fileInfo->getPathname()), '/');
                if ($this->hasDotComponent($rel)) {
                    continue;
                }
                $files[] = $rel;
            }
        }
        sort($files);

        return ['success' => true, 'files' => $files];
    }

    /**
     * Resolves a caller-supplied relative path to an absolute path inside
     * this token's code lane, refusing anything that would escape it.
     *
     * Unlike resolveScopedPath (metadata/site/assets), a code lane has no
     * single subdirectory root — core's own stage paths are
     * ["arch.py", "validate.py", "arch-gate.json"] (repo-root files, no
     * shared parent) and mcp's are four top-level entries. So this reads
     * the SAME arch-gate.json that already governs what a real commit
     * stages — fresh, no caching, matching how tokens.json/profiles.json
     * are already read on every request — and accepts a path only if it
     * falls under one of those entries. The MCP write-scope and the
     * actual commit-time staging scope are therefore structurally the
     * same list, read from the same file: there is no separate copy of
     * "what's in scope" to let drift out of sync with what a session
     * commit will actually stage. See claude/proposal-arch-mcp-code-
     * lane.md §3.
     */
    private function resolveCodePath(string $relativePath): ?string
    {
        if ('' === $relativePath || str_contains($relativePath, "\0")) {
            return null;
        }
        if ($this->hasDotComponent($relativePath)) {
            return null;
        }
        if (!isset($this->lanes['code'])) {
            return null;
        }

        $gatePath = $this->repoPath.'/arch-gate.json';
        if (!is_file($gatePath)) {
            return null;
        }
        $gateConfig = json_decode((string) file_get_contents($gatePath), true);
        $stagePaths = $gateConfig['code']['stage']['paths'] ?? null;
        if (!\is_array($stagePaths)) {
            return null;
        }

        $normalized = ltrim($relativePath, '/');
        $allowed = false;
        foreach ($stagePaths as $stagePath) {
            if (!\is_string($stagePath)) {
                continue;
            }
            $stagePath = trim($stagePath, '/');
            if ($normalized === $stagePath || str_starts_with($normalized, $stagePath.'/')) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return null;
        }

        $candidate = $this->repoPath.'/'.$normalized;

        $dir = \dirname($candidate);
        $existingAncestor = $dir;
        while (!is_dir($existingAncestor)) {
            $parent = \dirname($existingAncestor);
            if ($parent === $existingAncestor) {
                return null;
            }
            $existingAncestor = $parent;
        }

        $realAncestor = realpath($existingAncestor);
        $realRoot = realpath($this->repoPath) ?: $this->repoPath;
        if (false === $realAncestor) {
            return null;
        }
        if ($realAncestor !== $realRoot && !str_starts_with($realAncestor, $realRoot.'/')) {
            return null;
        }

        return $candidate;
    }

    /**
     * Added 2026-08-31, deployed with James live at the terminal after
     * being drafted and locally tested overnight — see Codegen CLI Design
     * §8 for why it wasn't shipped unattended.
     *
     * Write (create or overwrite) a file inside site/, a new sibling
     * directory to metadata/ at the repo root — deliberately NOT the same
     * tree the five session/metadata tools operate on, and deliberately
     * NOT gated by an active session. Per the three-stage model James
     * proposed 2026-08-30 (development push -> git commit -> git
     * publish), this is stage one only: a "development push," reversible,
     * no validation semantics, nothing this touches is metadata that
     * validate.py or arch_session_commit ever look at. Committing what
     * lands here to git, and any further "publish to a live docroot"
     * step, both stay explicit human-gated actions — this tool performs
     * neither.
     *
     * Same path-traversal discipline as archSessionWriteFile, just rooted
     * at site/ instead of metadata/ — see resolveScopedPath.
     *
     * @param string $path    relative path under site/, e.g. "index.html"
     * @param string $content full file content to write
     *
     * @return array<string, mixed>
     */
    public function archSiteWriteFile(string $path, string $content): array
    {
        if (!$this->extensionAllowed($path)) {
            return ['success' => false, 'error' => "file type not permitted for this token: '{$path}'"];
        }

        $resolved = $this->resolveSitePath($path);
        if (null === $resolved) {
            return ['success' => false, 'error' => "invalid path '{$path}' — must stay inside site/"];
        }

        $dir = \dirname($resolved);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => "could not create directory for '{$path}'"];
        }

        if (false === file_put_contents($resolved, $content)) {
            return ['success' => false, 'error' => "failed to write '{$path}'"];
        }

        $this->logger->info('Site file written.', ['path' => $path, 'bytes' => \strlen($content)]);

        return ['success' => true, 'path' => $path, 'bytes' => \strlen($content)];
    }

    /**
     * Added 2026-08-31 alongside archSiteWriteFile — see that method's
     * docblock.
     *
     * Read a file's current content from site/. Always safe, no session
     * required, mirrors archSessionReadFile.
     *
     * @param string $path relative path under site/
     *
     * @return array<string, mixed>
     */
    public function archSiteReadFile(string $path): array
    {
        $resolved = $this->resolveSitePath($path);
        if (null === $resolved) {
            return ['success' => false, 'error' => "invalid path '{$path}' — must stay inside site/"];
        }

        if (!is_file($resolved)) {
            return ['success' => false, 'error' => "'{$path}' does not exist"];
        }

        $content = file_get_contents($resolved);
        if (false === $content) {
            return ['success' => false, 'error' => "failed to read '{$path}'"];
        }

        return ['success' => true, 'path' => $path, 'content' => $content];
    }

    /**
     * Added 2026-08-31 alongside archSiteWriteFile — see that method's
     * docblock.
     *
     * List every file currently in site/, mirrors archSessionListFiles.
     *
     * @return array<string, mixed>
     */
    public function archSiteListFiles(): array
    {
        // Was: $this->repoPath.'/site' — a hardcoded subdirectory that
        // ignored the token's lane map completely. Two bugs in one: a
        // profile whose 'site' lane is the root itself listed a
        // nonexistent subdirectory and returned an empty list while
        // reporting success, and a token never granted this lane would
        // still have had a directory listed for it. Fixed 2026-09-01.
        $root = $this->laneRoot('site');
        if (null === $root || !is_dir($root)) {
            return ['success' => true, 'files' => []];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/');
            // Same dot-component rule the read/write paths enforce. Without
            // this the listing still enumerated every .git/ internal by
            // name — blocked from being READ, but fully disclosed, which
            // is both a leak and an unusable wall of output when a lane is
            // rooted at a checkout.
            if ($this->hasDotComponent($rel)) {
                continue;
            }
            $files[] = $rel;
        }
        sort($files);

        return ['success' => true, 'files' => $files];
    }

    /**
     * Resolves a caller-supplied relative path to an absolute path inside
     * site/, refusing anything that would escape it. Same logic as
     * resolveMetadataPath, rooted at site/ instead of metadata/.
     */
    private function resolveSitePath(string $relativePath): ?string
    {
        return $this->resolveScopedPath($relativePath, 'site');
    }

    /**
     * Added for the AI-context "assets" lane — see
     * claude/proposal-arch-mcp-assets-lane.md. A new sibling directory to
     * metadata/ and site/ at the repo root, holding curated material
     * meant to be loaded into an AI session's own project context /
     * knowledge base — not the project's governed runtime metadata, and
     * not its served website output. Deliberately not session-gated,
     * same reasoning as site/: disposable, regenerable content that
     * validate.py and arch_session_commit never look at.
     *
     * Same path-traversal discipline as archSessionWriteFile/
     * archSiteWriteFile, just rooted at assets/ instead — see
     * resolveScopedPath. Write access is governed by the same
     * $writeExtensions allowlist every other write method here already
     * uses — a profile provisioned read-only (write_extensions: [], via
     * mint-tokens.py --read-only) can still call this, it is just always
     * refused, the same way a read-only site lane already behaves.
     *
     * @param string $path    relative path under assets/, e.g. "ARCH-CONSTITUTION.md"
     * @param string $content full file content to write
     *
     * @return array<string, mixed>
     */
    public function archAssetsWriteFile(string $path, string $content): array
    {
        if (!$this->extensionAllowed($path)) {
            return ['success' => false, 'error' => "file type not permitted for this token: '{$path}'"];
        }

        $resolved = $this->resolveAssetsPath($path);
        if (null === $resolved) {
            return ['success' => false, 'error' => "invalid path '{$path}' — must stay inside assets/"];
        }

        $dir = \dirname($resolved);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'error' => "could not create directory for '{$path}'"];
        }

        if (false === file_put_contents($resolved, $content)) {
            return ['success' => false, 'error' => "failed to write '{$path}'"];
        }

        $this->logger->info('Assets file written.', ['path' => $path, 'bytes' => \strlen($content)]);

        return ['success' => true, 'path' => $path, 'bytes' => \strlen($content)];
    }

    /**
     * Read a file's current content from assets/. Always safe, no
     * session required, mirrors archSiteReadFile.
     *
     * @param string $path relative path under assets/
     *
     * @return array<string, mixed>
     */
    public function archAssetsReadFile(string $path): array
    {
        $resolved = $this->resolveAssetsPath($path);
        if (null === $resolved) {
            return ['success' => false, 'error' => "invalid path '{$path}' — must stay inside assets/"];
        }

        if (!is_file($resolved)) {
            return ['success' => false, 'error' => "'{$path}' does not exist"];
        }

        $content = file_get_contents($resolved);
        if (false === $content) {
            return ['success' => false, 'error' => "failed to read '{$path}'"];
        }

        return ['success' => true, 'path' => $path, 'content' => $content];
    }

    /**
     * List every file currently in assets/, mirrors archSiteListFiles.
     *
     * @return array<string, mixed>
     */
    public function archAssetsListFiles(): array
    {
        $root = $this->laneRoot('assets');
        if (null === $root || !is_dir($root)) {
            return ['success' => true, 'files' => []];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/');
            if ($this->hasDotComponent($rel)) {
                continue;
            }
            $files[] = $rel;
        }
        sort($files);

        return ['success' => true, 'files' => $files];
    }

    /**
     * Resolves a caller-supplied relative path to an absolute path inside
     * assets/, refusing anything that would escape it. Same logic as
     * resolveSitePath/resolveMetadataPath, rooted at assets/ instead.
     */
    private function resolveAssetsPath(string $relativePath): ?string
    {
        return $this->resolveScopedPath($relativePath, 'assets');
    }

    /**
     * Extension allowlist for writes.
     *
     * A site lane points at a directory Apache serves with PHP enabled.
     * Writing shell.php there is remote code execution reachable at
     * https://<domain>/<path>/shell.php — no traversal, no escape, just
     * a filename. That risk existed before per-token scoping; it becomes
     * material the moment a token is handed to someone else, so the
     * allowlist is defined per profile and defaults to unrestricted only
     * for profiles that do not set one (i.e. James's own).
     */
    private function extensionAllowed(string $relativePath): bool
    {
        if (null === $this->writeExtensions) {
            return true;
        }
        $ext = strtolower(pathinfo($relativePath, \PATHINFO_EXTENSION));

        return '' !== $ext && \in_array($ext, $this->writeExtensions, true);
    }

    /**
     * Absolute filesystem root for a lane this token was granted, or null.
     * Single source of truth for "where does this lane live" — every
     * caller must go through here, including the list methods, which
     * previously derived it themselves and got it wrong.
     */
    private function laneRoot(string $lane): ?string
    {
        if (!isset($this->lanes[$lane])) {
            return null;
        }
        $subdir = $this->lanes[$lane];

        return '' === $subdir ? $this->repoPath : $this->repoPath.'/'.$subdir;
    }

    /**
     * Reject any path with a dot-prefixed component.
     *
     * Needed because a lane can now be rooted at a directory that holds
     * more than site content. rcp-dev's lane is a git CHECKOUT ROOT, so
     * .git/ sits inside the lane rather than above it — reachable as the
     * plain relative path ".git/config", no traversal required. Reading
     * it leaks the remote; writing .git/hooks/post-merge would execute
     * on the next deployment pull. Neither is a traversal escape, which
     * is why the ../ tests all passed while this was wide open.
     *
     * Also catches .htaccess, .env, .htpasswd. Known casualty:
     * .well-known/ for ACME — handle that by hand if it is ever needed.
     */
    private function hasDotComponent(string $relativePath): bool
    {
        foreach (explode('/', str_replace('\\', '/', $relativePath)) as $part) {
            if ('' !== $part && '.' === $part[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shared implementation behind resolveMetadataPath, resolveSitePath,
     * and resolveAssetsPath: resolves a caller-supplied relative path to
     * an absolute path inside repoPath/$subdir, refusing anything that
     * would escape it (../, symlink tricks, absolute paths, null bytes).
     * Returns null on any violation. This is an allowlist of named
     * directories (metadata/, site/, assets/), not a blocklist of the
     * repo root — arch.py, validate.py, and .git remain unreachable by
     * construction regardless of how many scoped lanes get added here in
     * future. (Doc comment corrected 2026-09-19 — resolveAssetsPath was
     * added 2026-09-13 without this comment being updated to match. Then
     * misplaced entirely during that same fix, landing above
     * extensionAllowed() instead of here — corrected again same day.)
     */
    private function resolveScopedPath(string $relativePath, string $lane): ?string
    {
        if ('' === $relativePath || str_contains($relativePath, "\0")) {
            return null;
        }

        if ($this->hasDotComponent($relativePath)) {
            return null;
        }

        // Lane allowlist, per token. A token whose profile does not grant
        // this lane gets null here even if the tool was somehow invoked
        // by name — filtering the advertised tool list in index.php is an
        // ergonomic nicety, THIS is the actual control.
        $root = $this->laneRoot($lane);
        if (null === $root) {
            return null;
        }

        // $root itself is built from trusted, hardcoded values only
        // ($repoPath, $subdir) — never from $relativePath — so creating
        // it here if missing is safe. Needed because realpath() can't
        // resolve a directory that doesn't exist yet, which metadata/
        // never hit (bootstrapped into the repo from day one) but a new
        // scoped root like site/ will on its very first write.
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }

        $candidate = $root.'/'.ltrim($relativePath, '/');

        // Resolve the deepest existing ancestor to catch ../ traversal
        // even when the target file itself doesn't exist yet (realpath()
        // only works on paths that already exist).
        $dir = \dirname($candidate);
        $existingAncestor = $dir;
        while (!is_dir($existingAncestor)) {
            $parent = \dirname($existingAncestor);
            if ($parent === $existingAncestor) {
                return null;
            }
            $existingAncestor = $parent;
        }

        $realAncestor = realpath($existingAncestor);
        $realRoot = realpath($root) ?: $root;

        // Boundary-aware containment check. The previous version used a
        // bare strncmp() prefix test, which also accepted any SIBLING
        // whose name merely started with the root's name — with a single
        // hardcoded repo that needed a directory called e.g. "site-old"
        // to exploit, but once roots are per-token it means a token
        // rooted at .../rcp would equally accept .../rcp-backup. Fixed
        // 2026-09-01 as part of this change.
        if (false === $realAncestor) {
            return null;
        }
        if ($realAncestor !== $realRoot && !str_starts_with($realAncestor, $realRoot.'/')) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runArch(array $args): array
    {
        $command = array_merge([$this->phpPickPython(), $this->archPath], $args);

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $this->repoPath);

        if (!\is_resource($process)) {
            $this->logger->error('Failed to spawn arch.py process.', ['command' => $command]);

            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'failed to spawn arch.py'];
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->logger->info('arch.py invoked.', ['args' => $args, 'exit_code' => $exitCode]);

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * arch.py is plain-stdlib Python (argparse, json, subprocess,
     * pathlib) with no third-party dependencies, so any reasonably
     * current python3 on this host works — this just avoids hardcoding
     * a path that may differ across hosts.
     */
    private function phpPickPython(): string
    {
        foreach (['python3', 'python'] as $candidate) {
            $resolved = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));
            if ('' !== $resolved) {
                return $resolved;
            }
        }

        // Fall back to a bare name and let the OS PATH resolve it; a
        // clearer error will surface from proc_open if nothing exists.
        return 'python3';
    }

    private const ALLOWED_GIT_SUBCOMMANDS = ['status', 'diff', 'log', 'show', 'fetch'];

    /**
     * A caller-supplied ref or path that starts with '-' would be parsed by
     * git as a flag rather than a value — e.g. "--output=/tmp/x" turns a
     * read-only `git diff` into a write to an arbitrary path. Refusing
     * anything flag-shaped closes that off without needing to enumerate
     * every dangerous flag individually.
     */
    private function looksLikeFlag(string $value): bool
    {
        return '' !== $value && str_starts_with($value, '-');
    }

    /**
     * Spawns `git <args>` rooted at $root (defaulting to this profile's
     * core lane), with no allowlist check at all. Only called from two
     * places: runGit() (which checks ALLOWED_GIT_SUBCOMMANDS first, for
     * every caller-reachable read-only tool) and
     * archCoreGitPullFastForward() (whose own args are 100% hardcoded, so
     * there is nothing for a caller to inject regardless of this helper
     * skipping the allowlist).
     *
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function execGit(array $args, ?string $root = null): array
    {
        $root ??= $this->laneRoot('core');
        if (null === $root) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'this token has no core lane'];
        }
        $command = array_merge(['git'], $args);
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, $root);
        if (!\is_resource($process)) {
            $this->logger->error('Failed to spawn git process.', ['command' => $command]);
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'failed to spawn git'];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runGit(array $args): array
    {
        $subcommand = $args[0] ?? '';
        if (!\in_array($subcommand, self::ALLOWED_GIT_SUBCOMMANDS, true)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => "git subcommand '{$subcommand}' not permitted for this tool"];
        }
        $result = $this->execGit($args);
        $this->logger->info('git invoked (core lane, read-only).', ['args' => $args, 'exit_code' => $result['exit_code']]);
        return $result;
    }

    /**
     * Report the core lane's working-tree status (uncommitted changes).
     *
     * Read-only — status/diff/log/show/fetch are the only git subcommands
     * this tool will ever run through this dispatch; see runGit()'s
     * allowlist. Never touches history or the working tree.
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function archCoreGitStatus(): array
    {
        return $this->runGit(['status', '--short']);
    }

    /**
     * Diff the core lane's working tree against a ref (default HEAD).
     *
     * @param string $ref  ref to diff against, e.g. "HEAD" or "origin/main"
     * @param string $path optional path to scope the diff to, relative to the core lane root
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function archCoreGitDiff(string $ref = 'HEAD', string $path = ''): array
    {
        if ($this->looksLikeFlag($ref) || $this->looksLikeFlag($path)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'ref/path must not look like a flag'];
        }
        $args = ['diff', $ref];
        if ('' !== $path) {
            $args[] = '--';
            $args[] = $path;
        }
        return $this->runGit($args);
    }

    /**
     * Show recent commit history for the core lane.
     *
     * @param int    $count number of commits to show
     * @param string $path  optional path to scope the log to
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function archCoreGitLog(int $count = 10, string $path = ''): array
    {
        if ($this->looksLikeFlag($path)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'path must not look like a flag'];
        }
        $args = ['log', '--oneline', '-'.max(1, $count)];
        if ('' !== $path) {
            $args[] = '--';
            $args[] = $path;
        }
        return $this->runGit($args);
    }

    /**
     * Show a file's content as of a given ref, without touching the working
     * tree — e.g. compare what's on disk in staging against origin/main.
     *
     * @param string $ref  a commit, branch, or tag
     * @param string $path path relative to the core lane root
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function archCoreGitShow(string $ref, string $path): array
    {
        if ($this->looksLikeFlag($ref) || $this->looksLikeFlag($path)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'ref/path must not look like a flag'];
        }
        return $this->runGit(['show', "{$ref}:{$path}"]);
    }

    /**
     * Report the core lane's remote-tracking state without touching the
     * working tree, index, or any local branch -- `git fetch` only ever
     * downloads objects and updates refs/remotes/origin/*. Available to
     * any core-lane profile, no separate opt-in, since this is exactly as
     * read-only as status/diff/log/show above.
     *
     * No caller-supplied arguments: always `git fetch origin`. Combined
     * with archCoreGitDiff('origin/<branch>'), this is the whole "preview
     * what would land" mechanism -- no write-capable code involved.
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function archCoreGitFetch(): array
    {
        return $this->runGit(['fetch', 'origin']);
    }

    /**
     * The one write-capable git operation this server exposes: fast-forward
     * this profile's checkout to the tip of origin/<pull_branch>.
     *
     * Deliberately narrower than every read-only tool above:
     *  - Refused outright unless this profile was provisioned with
     *    pull_allowed=true (mint-tokens.py --allow-pull) -- opt-in per
     *    profile, nothing gains this silently.
     *  - Takes NO caller-supplied arguments at all -- not the branch, not
     *    a ref, nothing. The only variable is which profile is calling,
     *    and that profile's pull_branch was fixed at provisioning time by
     *    whoever ran mint-tokens.py, never by the live MCP request. This
     *    removes the argument-injection class of risk this file's other
     *    git methods guard against via looksLikeFlag() -- there is simply
     *    nothing here for a caller to inject into.
     *  - Refuses to run at all if the working tree isn't clean
     *    (`git status --porcelain` non-empty) -- a production checkout
     *    should never have local drift, and this never guesses past one.
     *  - `merge --ff-only`, never plain `pull` or a real merge -- fast-
     *    forward or nothing. If origin and this checkout have diverged,
     *    the merge fails and returns a non-zero exit code; nothing is left
     *    partially applied, no conflict markers are ever written.
     *  - Reachable only through this dedicated method, via execGit()
     *    directly -- NOT through runGit()'s allowlist-checked dispatch, so
     *    there remains no way to get an arbitrary git subcommand executed
     *    through the generic, caller-args-accepted path. That invariant
     *    (status/diff/log/show/fetch are the only subcommands runGit()
     *    will ever run) is unchanged by this method's existence.
     *
     * @return array{exit_code: int, stdout: string, stderr: string, before?: string, after?: string, pulled?: bool}
     */
    public function archCoreGitPullFastForward(): array
    {
        if (!$this->pullAllowed) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'this token is not permitted to pull'];
        }

        $root = $this->laneRoot('core');
        if (null === $root) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'this token has no core lane'];
        }

        $status = $this->execGit(['status', '--porcelain'], $root);
        if (0 !== $status['exit_code']) {
            return $status;
        }
        if ('' !== trim($status['stdout'])) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'working tree is not clean -- refusing to pull'];
        }

        $fetch = $this->execGit(['fetch', 'origin'], $root);
        if (0 !== $fetch['exit_code']) {
            return $fetch;
        }

        $before = trim($this->execGit(['rev-parse', 'HEAD'], $root)['stdout']);
        $merge = $this->execGit(['merge', '--ff-only', 'origin/'.$this->pullBranch], $root);
        $after = trim($this->execGit(['rev-parse', 'HEAD'], $root)['stdout']);

        $merge['before'] = $before;
        $merge['after'] = $after;
        $merge['pulled'] = 0 === $merge['exit_code'] && $before !== $after;

        $this->logger->info('git pull --ff-only (core lane, gated).', [
            'branch' => $this->pullBranch,
            'before' => $before,
            'after' => $after,
            'exit_code' => $merge['exit_code'],
        ]);

        return $merge;
    }

    /**
     * The other write-capable git operation this server can expose: push
     * this profile's checkout to origin/<push_branch>. Deliberately the
     * most restricted method in this file.
     *
     * Since 2026-09-19, a push_allowed profile no longer needs to call
     * this directly for the common case -- archSessionCommit() now runs
     * this exact push automatically right after a successful commit (see
     * its own docblock and claude/note-2026-09-19-push-on-commit.md).
     * This method still exists standalone for a manual push against an
     * already-committed HEAD (e.g. retrying a push that failed for a
     * reason unrelated to the commit, such as a transient network error)
     * without re-running session commit.
     *
     * Preconditions, all enforced before any git process spawns:
     *  - Refused outright unless this profile was provisioned with
     *    push_allowed=true (mint-tokens.py --allow-push) -- off by
     *    default, opt-in per project, same as pull_allowed.
     *  - Refused if this host has no push-capable credential for this
     *    checkout's origin -- this method never supplies one itself (no
     *    deploy key is created, stored, or read anywhere in this file);
     *    if none is configured, `git push` fails on its own with a
     *    normal authentication error, surfaced as this call's stderr.
     *  - Requires the caller to name the commit it believes is current
     *    HEAD ($expectedHead) and refuses unless it matches exactly.
     *    This is the "diff-then-confirm" contract the SOW asked for,
     *    made mechanical rather than a documentation-only convention:
     *    the caller must first call a read-only tool (status/log/show)
     *    to learn the real current HEAD -- there is no way to satisfy
     *    this check without having looked. If local state moved between
     *    that check and this call (another push, a new local commit),
     *    the mismatch refuses the push rather than pushing something
     *    the caller never actually saw.
     *
     * Otherwise narrower than every read-only tool in the same ways
     * archCoreGitPullFastForward() is narrower than status/diff/log/show:
     *  - Takes no caller-supplied branch or ref -- push_branch was fixed
     *    at provisioning time, never supplied by the live MCP request.
     *  - Never `--force` / `--force-with-lease`, never any history
     *    rewrite. A plain `git push origin <push_branch>` is REJECTED BY
     *    GIT ITSELF (and by GitHub, remote-side) if it is not a
     *    fast-forward of the remote branch -- this method adds no logic
     *    of its own to enforce that; it relies on git's own default
     *    behaviour exactly the way archCoreGitPullFastForward() relies on
     *    `merge --ff-only` rather than reimplementing a safety check.
     *  - Reachable only through this dedicated method, via execGit()
     *    directly -- not through runGit()'s allowlist-checked dispatch.
     *    `push` is not, and must never become, a member of
     *    ALLOWED_GIT_SUBCOMMANDS.
     *
     * @param string $expectedHead the commit hash the caller believes is
     *                             current HEAD, obtained from a prior
     *                             read-only call (e.g. arch_core_git_log)
     *
     * @return array{exit_code: int, stdout: string, stderr: string, branch?: string, head?: string, pushed?: bool}
     */
    public function archCoreGitPushOrigin(string $expectedHead): array
    {
        if (!$this->pushAllowed) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'this token is not permitted to push'];
        }

        // Full or abbreviated hex only -- never a symbolic ref like
        // "HEAD" or a branch name. The whole point of this parameter is
        // to pin an exact commit the caller already observed; accepting
        // anything resolvable would let a caller "confirm" without
        // having actually looked.
        if ('' === $expectedHead || 1 !== preg_match('/^[0-9a-fA-F]{7,40}$/', $expectedHead)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'expected_head must be a full or abbreviated commit hash'];
        }

        $root = $this->laneRoot('core');
        if (null === $root) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'this token has no core lane'];
        }

        $headResult = $this->execGit(['rev-parse', 'HEAD'], $root);
        if (0 !== $headResult['exit_code']) {
            return $headResult;
        }
        $head = trim($headResult['stdout']);

        $resolvedExpected = trim($this->execGit(['rev-parse', '--verify', '--quiet', $expectedHead], $root)['stdout']);
        if ('' === $resolvedExpected || $resolvedExpected !== $head) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'expected_head does not match current HEAD ('.$head.') -- refusing to push; '
                    .'local state may have changed since you checked, re-run a preview and try again',
            ];
        }

        $push = $this->execGit(['push', 'origin', $this->pushBranch], $root);
        $push['branch'] = $this->pushBranch;
        $push['head'] = $head;
        $push['pushed'] = 0 === $push['exit_code'];

        $this->logger->info('git push (core lane, gated).', [
            'branch' => $this->pushBranch,
            'head' => $head,
            'exit_code' => $push['exit_code'],
        ]);

        return $push;
    }

    /**
     * Added 2026-09-17 -- schema/data migration tooling, extending this
     * server's git-pull-style "safe by construction" gating to database
     * schema changes. See claude/note-2026-09-17-db-migration-gap-and-
     * proposal.md for the incident and design that motivated this.
     *
     * Read-only: compares the migration files declared under this
     * profile's core-lane root (db/migrations/*.sql) against the
     * schema_migrations table in that project's OWN database (read via
     * its own config.php -- this tool never receives or stores a
     * database credential itself; it reads whatever the target project
     * already keeps for its own use, the same trust boundary
     * archCoreGitPullFastForward draws around this host's git
     * credential).
     *
     * @return array<string, mixed>
     */
    public function archCoreDbPendingMigrations(): array
    {
        $root = $this->laneRoot('core');
        if (null === $root) {
            return ['success' => false, 'error' => 'this token has no core lane'];
        }

        $declared = $this->listDeclaredMigrations($root);

        $pdo = $this->connectProjectDb($root);
        if (!$pdo instanceof \PDO) {
            return ['success' => false, 'error' => "could not connect to this project's own database (no config.php / DB_* constants found)"];
        }

        $applied = [];
        try {
            $stmt = $pdo->query('SELECT id FROM schema_migrations');
            $applied = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        } catch (\PDOException $e) {
            // schema_migrations doesn't exist yet -- every declared migration is pending until
            // the one-time bootstrap (db/schema_migrations_bootstrap.sql) has been run by hand.
            $applied = [];
        }

        $pending = [];
        foreach ($declared as $migration) {
            if (!\in_array($migration['id'], $applied, true)) {
                $pending[] = $migration;
            }
        }

        return [
            'success' => true,
            'pending' => $pending,
            'applied_count' => \count($applied),
            'declared_count' => \count($declared),
        ];
    }

    /**
     * Gated apply. Refuses anything outside what this project's own
     * db-policy.json currently lists as auto-apply-eligible for that
     * migration's declared tier -- same "policy is data, not code" shape
     * as this class's other gates (pull_allowed/pull_branch,
     * push_allowed/push_branch all come from the profile, never from a
     * caller argument). schema-destructive is never eligible, full stop,
     * regardless of policy content -- see db/migrations/README.md.
     *
     * Applies every currently-pending, policy-eligible migration in
     * declared order, each in its own transaction (commits and records
     * itself before the next runs), so a failure partway through leaves
     * earlier migrations applied and recorded -- matching how they'd
     * have landed if run by hand one at a time. Anything not eligible is
     * reported, not applied, and still requires a human via phpMyAdmin,
     * same as today.
     *
     * Takes no caller-supplied arguments -- same shape as
     * archCoreGitPullFastForward()/archCoreGitPushOrigin(): the only
     * variable is which profile is calling and what that project's own
     * declared migrations + policy file say, never a request parameter.
     *
     * @return array<string, mixed>
     */
    public function archCoreDbApplyMigrations(): array
    {
        if (!$this->dbApplyAllowed) {
            return ['success' => false, 'error' => 'this token is not permitted to apply DB migrations'];
        }

        $root = $this->laneRoot('core');
        if (null === $root) {
            return ['success' => false, 'error' => 'this token has no core lane'];
        }

        $pendingResult = $this->archCoreDbPendingMigrations();
        if (!($pendingResult['success'] ?? false)) {
            return $pendingResult;
        }

        $policy = $this->readDbPolicy($root);
        $autoTiers = $policy['auto_apply_tiers'] ?? [];

        $pdo = $this->connectProjectDb($root);
        if (!$pdo instanceof \PDO) {
            return ['success' => false, 'error' => "could not connect to this project's own database"];
        }

        $applied = [];
        $skipped = [];
        foreach ($pendingResult['pending'] as $migration) {
            $type = $migration['type'] ?? '';
            $dataClass = $migration['data-class'] ?? null;
            $eligible = 'schema-destructive' !== $type && \in_array($type, $autoTiers, true);

            // Transactional data is never auto-apply-eligible, regardless of
            // what db-policy.json says -- same "hardcoded, not policy-driven"
            // treatment as schema-destructive above. Per the design
            // conversation (2026-09-17): reference/configuration data may be
            // safe to seed automatically under policy, but transactional
            // (and audit) data is categorically out of scope for this tool.
            if ($eligible && 'transactional' === $dataClass) {
                $eligible = false;
                $migration['refused_reason'] = "data-class 'transactional' is never auto-apply-eligible -- needs human review, regardless of policy";
            }

            // Declared-tag-doesn't-match-body guard -- a refusal check only,
            // never a grant. A schema-additive file containing an obvious
            // destructive statement is refused even if the tier is policy-
            // allowed; nothing here can turn a non-eligible tier eligible.
            if ($eligible && $this->looksDestructive($migration['sql'])) {
                $eligible = false;
                $migration['refused_reason'] = "declared as {$type} but SQL body looks destructive -- refusing, needs human review";
            }

            if (!$eligible) {
                $migration['refused_reason'] ??= "tier '{$type}' not in this project's db-policy.json auto_apply_tiers";
                $skipped[] = $migration;
                continue;
            }

            try {
                $pdo->beginTransaction();
                $pdo->exec($migration['sql']);
                $stmt = $pdo->prepare(
                    'INSERT INTO schema_migrations (id, type, data_class, description, applied_at, applied_by)
                     VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)'
                );
                $stmt->execute([
                    $migration['id'],
                    $type,
                    $migration['data-class'] ?? null,
                    $migration['description'] ?? null,
                    'agent:archCoreDbApplyMigrations',
                ]);
                // MySQL implicitly commits any open transaction the moment a DDL
                // statement (ALTER TABLE, CREATE TABLE, ...) runs inside it -- this
                // is server behavior, not something PDO or this code controls. A
                // schema-additive migration's own ALTER/CREATE statement above
                // therefore already ended this transaction, and -- since the
                // connection falls back to autocommit once that implicit COMMIT
                // fires -- the schema_migrations INSERT just above already landed
                // on its own too. Only call commit() if PDO still thinks a
                // transaction is open (true for a data-only migration with no DDL
                // in it; false for anything schema-additive), so this never calls
                // commit() against a transaction MySQL already closed out from
                // under us.
                //
                // FIX 2026-09-21: without this check, any plain schema-additive
                // ALTER TABLE reported "There is no active transaction" here even
                // though both the ALTER and the tracking INSERT had already landed
                // correctly -- a false failure, not a real one. Reproduced live
                // against both arch-portal-staging and arch-portal (production):
                // the tool returned {"success": false, "error": "... There is no
                // active transaction", "applied_before_failure": []} for
                // 2026-09-20f-users-auth-model-add-hide on both, yet
                // arch_core_db_pending_migrations immediately afterward showed the
                // migration already recorded as applied on both -- the ALTER and
                // the INSERT had both gone through; only this redundant commit()
                // call was throwing. See claude/note-2026-09-20-db-migration-
                // status-check.md and claude/note-2026-09-21-db-apply-transaction-
                // false-failure-fix.md.
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                $applied[] = $migration['id'];
                $this->logger->info('DB migration applied (gated).', ['id' => $migration['id'], 'type' => $type]);
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                return [
                    'success' => false,
                    'error' => "migration '{$migration['id']}' failed: ".$e->getMessage(),
                    'applied_before_failure' => $applied,
                ];
            }
        }

        $this->logger->info('DB migration batch complete (gated).', [
            'applied' => $applied,
            'skipped_count' => \count($skipped),
        ]);

        return ['success' => true, 'applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Fill in a Portal project request from a completed bootstrap interview.
     *
     * Calls out to arch-portal's own inception-fill endpoint over HTTPS,
     * carrying the slug + one-time token the requester was handed on
     * Portal's confirmation screen (see claude/proposal-portal-first-
     * project-inception.md) -- this tool's own gate (bootstrapFillAllowed)
     * only decides whether THIS PROFILE may attempt the call at all; the
     * slug+token pair is what Portal itself checks before touching
     * anything, so a caller with this tool but a wrong/reused/unknown
     * token still can't write to any row. Portal, not this server, remains
     * the sole writer of its own `projects` table -- this method never
     * touches a database directly, matching the existing division of
     * responsibility between the two apps (see ArchMcpClient.php on
     * Portal's side, which calls INTO this server the other direction;
     * this is the same shape, reversed).
     *
     * @param string $slug       the project slug the requester was given
     * @param string $token      the one-time inception token shown on Portal's
     *                            confirmation screen alongside that slug
     * @param string $configJson the project.config.json draft this session
     *                            produced (or would have printed as text) --
     *                            stored verbatim on the project's request for
     *                            a human to review before approving, not
     *                            parsed or trusted as structured data by
     *                            either this method or Portal's endpoint
     * @param string $gitRemote  optional -- git.remote from the same draft,
     *                            if known; the one field Portal maps onto a
     *                            real column (projects.git_repo_url) rather
     *                            than just storing as text, since it's
     *                            unambiguous
     *
     * @return array<string, mixed>
     */
    public function archBootstrapFillInceptionRow(string $slug, string $token, string $configJson, string $gitRemote = ''): array
    {
        if (!$this->bootstrapFillAllowed) {
            return ['success' => false, 'error' => 'this token is not permitted to fill in Portal project requests'];
        }

        if (null === $this->bootstrapFillPortalUrl) {
            return ['success' => false, 'error' => 'this token has no Portal endpoint configured — profile misconfiguration, not a caller error'];
        }

        if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9-]{1,98}[a-z0-9]$/', $slug)) {
            return ['success' => false, 'error' => 'slug must be short, kebab-case (letters, digits, hyphens)'];
        }
        if ('' === $token || 1 !== preg_match('/^[0-9a-f]{16,128}$/', $token)) {
            return ['success' => false, 'error' => 'token looks malformed -- expected the hex string shown on the Portal confirmation screen'];
        }
        if (\strlen($configJson) > 20000) {
            return ['success' => false, 'error' => 'config draft is too long (max 20000 characters)'];
        }

        $payload = json_encode([
            'slug' => $slug,
            'token' => $token,
            'config' => $configJson,
            'git_remote' => '' !== $gitRemote ? $gitRemote : null,
        ]);

        $ch = curl_init($this->bootstrapFillPortalUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (0 !== $errno) {
            $this->logger->error('Bootstrap inception-fill call failed.', ['errno' => $errno, 'error' => $err]);
            return ['success' => false, 'error' => 'could not reach Portal: '.$err];
        }

        $decoded = \is_string($raw) ? json_decode($raw, true) : null;
        if (!\is_array($decoded)) {
            $this->logger->error('Bootstrap inception-fill returned non-JSON.', ['status' => $status]);
            return ['success' => false, 'error' => "Portal returned an unexpected response (HTTP {$status})"];
        }

        $this->logger->info('Bootstrap inception-fill call complete.', ['slug' => $slug, 'status' => $status, 'success' => $decoded['success'] ?? false]);

        return $decoded;
    }

    /**
     * A refusal check only -- see archCoreDbApplyMigrations(). Never
     * used to grant anything; a false negative here just means the
     * policy/tier check is the only thing standing between a migration
     * and auto-apply, same as before this guard existed.
     */
    private function looksDestructive(string $sql): bool
    {
        return 1 === preg_match(
            '/\b(DROP\s+TABLE|DROP\s+COLUMN|TRUNCATE|DELETE\s+FROM|RENAME\s+(TABLE|COLUMN))\b/i',
            $sql
        );
    }

    /**
     * Reads and parses every db/migrations/*.sql file under this
     * profile's core lane root, in filename order (files are numbered
     * NNNN_ prefix precisely so this ordering is stable and explicit --
     * see db/migrations/README.md).
     *
     * @return list<array<string, mixed>>
     */
    private function listDeclaredMigrations(string $root): array
    {
        $dir = $root.'/db/migrations';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir.'/*.sql') ?: [];
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (false === $content) {
                continue;
            }
            $parsed = $this->parseMigrationFile($content);
            if (null !== $parsed) {
                $migrations[] = $parsed;
            }
        }

        return $migrations;
    }

    /**
     * Parses a migration file's `-- key: value` header block (id, type,
     * data-class, description) plus its SQL body. Returns null for a
     * file with no valid `-- id:`/`-- type:` header -- such a file is
     * silently skipped by listDeclaredMigrations() rather than treated
     * as a migration with no identity, since there is nothing safe to do
     * with an unidentified SQL blob.
     *
     * @return array<string, mixed>|null
     */
    private function parseMigrationFile(string $content): ?array
    {
        $id = null;
        $type = null;
        $dataClass = null;
        $descriptionParts = [];
        $sqlLines = [];
        $inHeader = true;

        foreach (explode("\n", $content) as $line) {
            if ($inHeader) {
                if (1 === preg_match('/^--\s*id:\s*(.+)$/', $line, $m)) {
                    $id = trim($m[1]);
                    continue;
                }
                if (1 === preg_match('/^--\s*type:\s*(.+)$/', $line, $m)) {
                    $type = trim($m[1]);
                    continue;
                }
                if (1 === preg_match('/^--\s*data-class:\s*(.+)$/', $line, $m)) {
                    $dataClass = trim($m[1]);
                    continue;
                }
                if (1 === preg_match('/^--\s*description:\s*(.+)$/', $line, $m)) {
                    $descriptionParts[] = trim($m[1]);
                    continue;
                }
                // Indented continuation of the description field (this
                // project's existing header-comment convention -- see
                // db/migrations/0001 onward).
                if (!empty($descriptionParts) && 1 === preg_match('/^--\s{2,}(.+)$/', $line, $m)) {
                    $descriptionParts[] = trim($m[1]);
                    continue;
                }
                if ('' === trim($line) || 1 === preg_match('/^--/', $line)) {
                    continue;
                }
                $inHeader = false;
            }
            $sqlLines[] = $line;
        }

        if (null === $id || null === $type) {
            return null;
        }

        return [
            'id' => $id,
            'type' => $type,
            'data-class' => $dataClass,
            'description' => implode(' ', $descriptionParts),
            'sql' => trim(implode("\n", $sqlLines)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readDbPolicy(string $root): array
    {
        $path = $root.'/db/db-policy.json';
        if (!is_file($path)) {
            return ['auto_apply_tiers' => []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : ['auto_apply_tiers' => []];
    }

    /**
     * Connects to the TARGET PROJECT's own database, using ITS OWN
     * config.php -- never a credential this server holds itself. Same
     * trust boundary as this file's git methods: this tool has no
     * agent-readable database credential of its own, it only reads
     * whatever the project being operated on already keeps for its own
     * use (config.php, out of git, same convention every project here
     * already uses for its own web requests -- see e.g. arch-portal's
     * src/db.php, which this mirrors rather than calls directly, since
     * not every project in this ecosystem necessarily structures its own
     * db.php the same way).
     *
     * Added 2026-09-19 -- multi-database support (see
     * claude/proposal-multi-database-support.md in the ARCH-COLLAB Claude
     * Project). A project's own config.php may now declare
     * `DB_ENGINE` ('mysql', the implicit default if unset, or 'sqlite').
     * A 'sqlite' project defines `DB_PATH` (an absolute path to its
     * SQLite file) instead of DB_HOST/DB_NAME/DB_USER/DB_PASS -- no
     * profiles.json change needed, since this method already only ever
     * reads the TARGET PROJECT's own config, never this server's own
     * secrets. The "already loaded" guard below checks both DB_ENGINE
     * and DB_HOST (whichever a given project's config.php actually
     * defines) so config.php is never require_once'd twice in one
     * process regardless of which engine flavour it is.
     */
    private function connectProjectDb(string $root): ?\PDO
    {
        $configPath = $root.'/config.php';
        if (!is_file($configPath)) {
            return null;
        }
        if (!\defined('DB_ENGINE') && !\defined('DB_HOST')) {
            require_once $configPath;
        }

        $engine = \defined('DB_ENGINE') ? \DB_ENGINE : 'mysql';

        if ('sqlite' === $engine) {
            if (!\defined('DB_PATH')) {
                return null;
            }

            try {
                return new \PDO(
                    \sprintf('sqlite:%s', \DB_PATH),
                    null,
                    null,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
            } catch (\PDOException $e) {
                $this->logger->error('DB connection failed for migration tooling.', ['error' => $e->getMessage(), 'engine' => 'sqlite']);

                return null;
            }
        }

        if (!\defined('DB_HOST') || !\defined('DB_NAME') || !\defined('DB_USER') || !\defined('DB_PASS')) {
            return null;
        }

        try {
            return new \PDO(
                \sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', \DB_HOST, \DB_NAME),
                \DB_USER,
                \DB_PASS,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->logger->error('DB connection failed for migration tooling.', ['error' => $e->getMessage(), 'engine' => 'mysql']);

            return null;
        }
    }
}
