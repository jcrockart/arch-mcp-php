<?php

namespace ArchMcp;

require_once __DIR__.'/ManagedFile.php';

/**
 * Decides, file by file, what install_bootstrap should do on a first run or
 * a re-run (ARCH-63, decided 2026-10-11). Never clobbers silently: every
 * write over something the person may have touched is listed for the reply.
 *
 * Inputs:
 *   $sources  arch-bootstrap file name => its current content
 *   $existing managed name in the target project => sha256 of what is there now
 *             (from the project's asset list)
 *   $manifest managed name => {source_sha256, installed_sha256}: what the last
 *             install recorded, source hash and hash of the file as installed
 *
 * Per file, `action` is one of:
 *   create               nothing there yet: write it
 *   unchanged            what is there already equals what would be written: skip
 *   update               untouched since the last install, source changed: write
 *   overwrite_edited     edited locally AND the source changed: write, and list it as edited
 *   edited_kept          edited locally but the source did not change: NOT written, listed as edited
 *   overwrite_unrecorded there with no install record and different content: write, list it
 *   unsupported          no safe header for this type: not installed, listed
 *
 * `manifest` in the result is the record to store after the writes succeed.
 * For edited_kept it keeps the old record, so the edit is still seen next time.
 * Managed files in the manifest whose source no longer exists are listed as
 * `orphaned` and left alone (nothing here deletes).
 *
 * Pure function: no I/O. The caller does the reads and writes.
 */
final class BootstrapPlan
{
    /**
     * @param array<string, string>                                                    $sources
     * @param array<string, string>                                                    $existing
     * @param array<string, array{source_sha256: string, installed_sha256: string}>    $manifest
     *
     * @return array{
     *   files: list<array<string, mixed>>,
     *   writes: list<array<string, mixed>>,
     *   edited: list<string>,
     *   kept_edited: list<string>,
     *   overwritten_unrecorded: list<string>,
     *   unsupported: list<string>,
     *   orphaned: list<string>,
     *   counts: array<string, int>,
     *   manifest: array<string, array{source_sha256: string, installed_sha256: string}>
     * }
     */
    public static function plan(array $sources, array $existing, array $manifest): array
    {
        $files = [];
        $writes = [];
        $edited = [];
        $keptEdited = [];
        $unrecorded = [];
        $unsupported = [];
        $counts = [];
        $newManifest = [];
        $seen = [];

        foreach ($sources as $name => $content) {
            $name = (string) $name;
            $target = ManagedFile::targetName($name);
            $seen[$target] = true;
            $installed = ManagedFile::withHeader($name, (string) $content);

            if (null === $installed) {
                $unsupported[] = $name;
                $files[] = ['source' => $name, 'target' => $target, 'action' => 'unsupported'];
                $counts['unsupported'] = ($counts['unsupported'] ?? 0) + 1;
                if (isset($manifest[$target])) {
                    $newManifest[$target] = $manifest[$target];
                }
                continue;
            }

            $sourceSha = ManagedFile::sha256((string) $content);
            $installedSha = ManagedFile::sha256($installed);
            $current = $existing[$target] ?? null;
            $record = $manifest[$target] ?? null;
            $write = false;
            $onConflict = 'overwrite';

            if (null === $current) {
                $action = 'create';
                $write = true;
                $onConflict = 'fail';
            } elseif ($current === $installedSha) {
                $action = 'unchanged';
            } elseif (null === $record) {
                $action = 'overwrite_unrecorded';
                $write = true;
                $unrecorded[] = $target;
            } elseif ($current === $record['installed_sha256']) {
                $action = 'update';
                $write = true;
            } elseif ($record['source_sha256'] === $sourceSha) {
                $action = 'edited_kept';
                $keptEdited[] = $target;
            } else {
                $action = 'overwrite_edited';
                $write = true;
                $edited[] = $target;
            }

            $counts[$action] = ($counts[$action] ?? 0) + 1;
            $files[] = ['source' => $name, 'target' => $target, 'action' => $action, 'source_sha256' => $sourceSha, 'installed_sha256' => $installedSha];

            if ($write) {
                $writes[] = [
                    'source' => $name,
                    'target' => $target,
                    'action' => $action,
                    'content' => $installed,
                    'on_conflict' => $onConflict,
                    'expected_sha256' => 'create' === $action ? '' : (string) $current,
                ];
            }

            if ('edited_kept' === $action && null !== $record) {
                $newManifest[$target] = $record;
            } else {
                $newManifest[$target] = ['source_sha256' => $sourceSha, 'installed_sha256' => $installedSha];
            }
        }

        $orphaned = [];
        foreach (array_keys($manifest) as $target) {
            if (!isset($seen[$target])) {
                $orphaned[] = (string) $target;
                $newManifest[(string) $target] = $manifest[$target];
            }
        }

        return [
            'files' => $files,
            'writes' => $writes,
            'edited' => $edited,
            'kept_edited' => $keptEdited,
            'overwritten_unrecorded' => $unrecorded,
            'unsupported' => $unsupported,
            'orphaned' => $orphaned,
            'counts' => $counts,
            'manifest' => $newManifest,
        ];
    }

    /**
     * The install records to send to Portal after the writes. Portal checks
     * each record's installed hash against the file's real current hash, so
     * only files whose content is known to be what was installed are listed:
     * written files, and files already identical to the new content when the
     * stored record is missing or different. Never listed: edited_kept (the
     * file is not what was installed), unsupported, and any file whose write
     * failed.
     *
     * @param array<string, mixed>                                                  $plan          the result of plan()
     * @param list<string>                                                          $failedTargets managed names whose write failed
     * @param array<string, array{source_sha256: string, installed_sha256: string}> $oldManifest   the records Portal held before this run
     *
     * @return list<array{asset_name: string, source_sha256: string, installed_sha256: string}>
     */
    public static function toRecord(array $plan, array $failedTargets, array $oldManifest): array
    {
        $out = [];
        foreach ($plan['files'] as $f) {
            $action = $f['action'];
            if ('unsupported' === $action || 'edited_kept' === $action || \in_array($f['target'], $failedTargets, true)) {
                continue;
            }
            $entry = ['source_sha256' => $f['source_sha256'], 'installed_sha256' => $f['installed_sha256']];
            if ('unchanged' === $action && ($oldManifest[$f['target']] ?? null) === $entry) {
                continue;
            }
            $out[] = ['asset_name' => $f['target']] + $entry;
        }

        return $out;
    }

    /**
     * The part of a plan that goes in the tool reply: counts and the lists a
     * person needs to see. No file contents and no hashes.
     *
     * @param array<string, mixed> $plan the result of plan()
     *
     * @return array<string, mixed>
     */
    public static function summary(array $plan): array
    {
        $out = ['counts' => $plan['counts']];
        foreach (['edited', 'kept_edited', 'overwritten_unrecorded', 'unsupported', 'orphaned'] as $key) {
            if ([] !== $plan[$key]) {
                $out[$key] = $plan[$key];
            }
        }

        return $out;
    }
}
