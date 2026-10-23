<?php

namespace ArchMcp;

require_once __DIR__.'/PortalCaller.php';
require_once __DIR__.'/ClaudeProjectId.php';
require_once __DIR__.'/ManagedFile.php';
require_once __DIR__.'/BootstrapPlan.php';

/**
 * The install_bootstrap run (ARCH-63, decided 2026-10-11): copy the current
 * arch-bootstrap files into a project's assets as managed framework files,
 * then bind the caller's Claude project to the project.
 *
 * Two Portal callers do the work, both carrying the caller's own token, so
 * Portal checks who is calling on every step: $bootstrap talks to
 * api_bootstrap.php (read the source files, the install records, record an
 * install, register a binding) and $assets talks to api_assets.php (list and
 * write the project's own assets). Portal requires owner or contributor on the
 * project and a provisioned project; this class adds nothing to that and
 * assumes nothing beyond it.
 *
 * Order, chosen so that a run which fails part way leaves the project NOT
 * bootstrapped and a re-run is always safe:
 *   1. read every bootstrap file (and check each against the hash listed)
 *   2. read the project's assets and the stored install records
 *   3. plan (BootstrapPlan): which files to create, update or leave alone
 *   4. write the files that need writing
 *   5. record what was installed (only files known to match, see BootstrapPlan::toRecord)
 *   6. register the Claude project ID, but only if nothing above failed
 * A failed write is listed in the reply and the run goes on with the other
 * files; the binding is withheld until a re-run installs everything. Files
 * already installed are kept. Locally edited files are overwritten only when
 * the source changed, and are always named in the reply.
 *
 * Publishing: a newly created managed file is published as 'root' (written
 * into the project's folder, not web-served) so the schemas exist on disk in
 * the project. An update keeps whatever setting the file already has.
 *
 * Deliberately NOT done here: writing project.config.json or validating
 * metadata. Provisioning writes the default config and the bootstrap
 * interview fills it in through session tools; the validator is built into
 * this server and runs at commit.
 *
 * Does no I/O of its own beyond the two callers. Returns an array for the tool
 * reply: never file contents, never hashes.
 */
final class BootstrapInstall
{
    private const NOTE = 'Managed framework file, installed by install_bootstrap';

    /**
     * @return array<string, mixed>
     */
    public static function run(PortalCaller $assets, PortalCaller $bootstrap, string $slug, string $claudeProjectId, string $claudeProjectName): array
    {
        $id = ClaudeProjectId::normalize($claudeProjectId);
        if (null === $id) {
            return self::fail('invalid', 'claudeProjectId must be the GUID from the Claude project URL (https://claude.ai/project/<guid>), or that URL itself.');
        }
        $label = ClaudeProjectId::label($claudeProjectName);

        // 1. The bootstrap files.
        $listed = $bootstrap->call('bootstrap_list', $slug);
        if (true !== ($listed['success'] ?? false)) {
            return self::portalFail($listed, 'could not list the bootstrap files');
        }
        $sources = [];
        foreach ((array) ($listed['files'] ?? []) as $file) {
            $name = \is_array($file) && \is_string($file['name'] ?? null) ? $file['name'] : '';
            if ('' === $name) {
                continue;
            }
            $read = $bootstrap->call('bootstrap_read', $slug, ['name' => $name]);
            if (true !== ($read['success'] ?? false) || !\is_string($read['content'] ?? null)) {
                return self::portalFail($read, "could not read bootstrap file '{$name}'");
            }
            $listedSha = \is_string($file['sha256'] ?? null) ? strtolower($file['sha256']) : '';
            if ('' !== $listedSha && !hash_equals($listedSha, ManagedFile::sha256($read['content']))) {
                return self::fail('changed', "bootstrap file '{$name}' changed while it was being read; run install_bootstrap again");
            }
            $sources[$name] = $read['content'];
        }
        if ([] === $sources) {
            return self::fail('source_missing', 'there are no bootstrap files to install');
        }

        // 2. The project's assets and the install records Portal holds.
        $listing = $assets->call('list', $slug);
        if (true !== ($listing['success'] ?? false)) {
            return self::portalFail($listing, "could not read this project's assets");
        }
        $currentByLower = [];
        foreach ((array) ($listing['details'] ?? []) as $d) {
            if (\is_array($d) && \is_string($d['name'] ?? null) && \is_string($d['sha256'] ?? null)) {
                $currentByLower[strtolower($d['name'])] = strtolower($d['sha256']);
            }
        }
        $recordsReply = $bootstrap->call('install_records', $slug);
        if (true !== ($recordsReply['success'] ?? false)) {
            return self::portalFail($recordsReply, 'could not read the install records');
        }

        // Portal matches asset names without regard to case, so do the same here.
        $targets = [];
        foreach (array_keys($sources) as $name) {
            $target = ManagedFile::targetName((string) $name);
            $targets[strtolower($target)] = $target;
        }
        $existing = [];
        foreach ($targets as $lower => $target) {
            if (isset($currentByLower[$lower])) {
                $existing[$target] = $currentByLower[$lower];
            }
        }
        $manifest = [];
        foreach ((array) ($recordsReply['files'] ?? []) as $r) {
            if (\is_array($r) && \is_string($r['asset_name'] ?? null) && \is_string($r['source_sha256'] ?? null) && \is_string($r['installed_sha256'] ?? null)) {
                $key = $targets[strtolower($r['asset_name'])] ?? $r['asset_name'];
                $manifest[$key] = ['source_sha256' => strtolower($r['source_sha256']), 'installed_sha256' => strtolower($r['installed_sha256'])];
            }
        }

        // 3 and 4. Plan, then write what needs writing.
        $plan = BootstrapPlan::plan($sources, $existing, $manifest);
        $written = 0;
        $failures = [];
        foreach ($plan['writes'] as $w) {
            $reply = $assets->call('write', $slug, [
                'name' => $w['target'],
                'content' => $w['content'],
                'on_conflict' => $w['on_conflict'],
                'expected_sha256' => $w['expected_sha256'],
                'notes' => self::NOTE,
                'publish' => 'create' === $w['action'] ? 'root' : '',
            ]);
            if (true === ($reply['success'] ?? false) && ManagedFile::sha256($w['content']) === ($reply['sha256'] ?? null)) {
                ++$written;
                continue;
            }
            if (true === ($reply['success'] ?? false)) {
                $reply = ['code' => 'mismatch', 'error' => 'Portal stored different content than was sent'];
            }
            $failures[] = ['file' => $w['target'], 'code' => (string) ($reply['code'] ?? 'server'), 'error' => self::short((string) ($reply['error'] ?? 'the write failed'))];
        }

        // 5. Record what was installed.
        $toRecord = BootstrapPlan::toRecord($plan, array_column($failures, 'file'), $manifest);
        if ([] !== $toRecord) {
            $recorded = $bootstrap->call('record_install', $slug, ['files' => $toRecord]);
            if (true !== ($recorded['success'] ?? false)) {
                $failures[] = ['file' => '(install record)', 'code' => (string) ($recorded['code'] ?? 'server'), 'error' => self::short((string) ($recorded['error'] ?? 'the install could not be recorded'))];
            }
        }

        $out = ['success' => true, 'slug' => $slug, 'written' => $written, 'files' => BootstrapPlan::summary($plan)];
        if ([] !== $failures) {
            $out['success'] = false;
            $out['failures'] = $failures;
            $out['error'] = 'Some files could not be installed, so this project is not marked as bootstrapped yet. Fix the cause and run install_bootstrap again; files already installed are kept.';

            return $out;
        }

        // 6. Bind the Claude project, last.
        $reg = $bootstrap->call('register_binding', $slug, ['claude_project_id' => $id, 'name_label' => '' === $label ? null : $label]);
        if (true !== ($reg['success'] ?? false)) {
            $out['success'] = false;
            $out['code'] = (string) ($reg['code'] ?? 'server');
            $out['error'] = 'The files were installed, but this Claude project could not be registered: '.self::short((string) ($reg['error'] ?? 'unexpected reply'));

            return $out;
        }
        $out['binding'] = ['claude_project_id' => $id, 'created' => (bool) ($reg['created'] ?? false), 'rebound' => (bool) ($reg['rebound'] ?? false)];

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fail(string $code, string $error): array
    {
        return ['success' => false, 'code' => $code, 'error' => $error];
    }

    /**
     * @param array<string, mixed> $reply a failed Portal reply
     *
     * @return array<string, mixed>
     */
    private static function portalFail(array $reply, string $what): array
    {
        return self::fail((string) ($reply['code'] ?? 'server'), $what.': '.self::short((string) ($reply['error'] ?? 'unexpected reply from Portal')));
    }

    private static function short(string $text): string
    {
        return mb_substr($text, 0, 300);
    }
}
