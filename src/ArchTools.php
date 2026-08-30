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

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->repoPath = ArchConfig::REPO_PATH;
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
        $root = $this->repoPath.'/metadata';
        if (!is_dir($root)) {
            return ['success' => true, 'files' => []];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $files[] = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/');
            }
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
        if ('' === $relativePath || str_contains($relativePath, "\0")) {
            return null;
        }

        $root = $this->repoPath.'/metadata';
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
        if (false === $realAncestor || 0 !== strncmp($realAncestor, $realRoot, \strlen($realRoot))) {
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
