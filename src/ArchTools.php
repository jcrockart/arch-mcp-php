<?php

/*
 * ARCH Agent-Connection MCP server — real, hosted implementation.
 *
 * Implements the tool definitions specified in agent-connection-layer.md /
 * Codegen CLI Design §8. Every tool below is a thin wrapper: it shells out
 * to arch.py exactly as a human would from the command line, and returns
 * its stdout/exit code. No git logic is duplicated here.
 *
 * DESIGN NOTE (Codegen CLI Design §7.3): this server's repo is a durable
 * local clone (git-backed, on this host's persistent disk, cloned from
 * the shared remote) — NOT an ephemeral agent sandbox. Per §7.3's
 * published resolution, that means arch_session_commit's local merge is
 * already durable on its own; pushing to the shared remote deliberately
 * stays a separate, human-paced action (mirroring the precedent Git
 * Setup & Access Policy §10 sets for deployment triggers), not something
 * this tool set performs automatically. If that's ever revisited, it
 * must not be added casually — see §9 item 3's credential-isolation
 * rationale first.
 *
 * This server holds no agent-readable git credential: it doesn't need
 * one for anything in this file. If a human later configures this host
 * to also push, that credential lives in this host's own environment
 * (e.g. its own deploy key), never passed through or exposed by any
 * tool call here.
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
     *
     * @return array<string, mixed> exit_code, stdout, stderr from arch.py
     */
    public function archSessionStart(string $name = ''): array
    {
        $args = ['session', 'start'];
        if ('' !== $name) {
            $args[] = $name;
        }

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
     * Merges to this host's own local `main` only — see this file's
     * header note on why pushing to a shared remote is deliberately not
     * part of this tool (Codegen CLI Design §7.3).
     *
     * @param string $bump one of "major", "minor", "patch" (default "patch"), per 22.5 Semantic Versioning Policy
     *
     * @return array<string, mixed> exit_code, stdout, stderr from arch.py
     */
    public function archSessionCommit(string $bump = 'patch'): array
    {
        if (!\in_array($bump, ['major', 'minor', 'patch'], true)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => "invalid bump '{$bump}'"];
        }

        return $this->runArch(['session', 'commit', "--{$bump}"]);
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
     * Shared implementation behind resolveMetadataPath and
     * resolveSitePath: resolves a caller-supplied relative path to an
     * absolute path inside repoPath/$subdir, refusing anything that would
     * escape it (../, symlink tricks, absolute paths, null bytes).
     * Returns null on any violation. This is an allowlist of named
     * directories (metadata/, site/), not a blocklist of the repo root —
     * arch.py, validate.py, and .git remain unreachable by construction
     * regardless of how many scoped lanes get added here in future.
     */
    /**
     * Absolute filesystem root for a lane this token was granted, or null.
     * Single source of truth for "where does this lane live" — every
     * caller must go through here, including the list methods, which
     * previously derived it themselves and got it wrong.
     */
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
}
