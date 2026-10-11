<?php
// Offline proof of the install plan (BootstrapPlan): first run, re-run with
// nothing changed, source updates, local edits, unrecorded files, orphans,
// unsupported types, and that nothing is ever overwritten silently.
// Pure function, no network, no files.
//
//     php tests/unit/test-bootstrap-plan.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/BootstrapPlan.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/BootstrapPlan.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/BootstrapPlan.php';

use ArchMcp\BootstrapPlan;
use ArchMcp\ManagedFile;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function action(array $plan, string $source): ?string {
    foreach ($plan['files'] as $f) {
        if ($f['source'] === $source) {
            return $f['action'];
        }
    }

    return null;
}
function installedSha(string $name, string $content): string {
    return ManagedFile::sha256((string) ManagedFile::withHeader($name, $content));
}
function record(string $name, string $content): array {
    return ['source_sha256' => ManagedFile::sha256($content), 'installed_sha256' => installedSha($name, $content)];
}

$A = ManagedFile::targetName('a.md');
$B = ManagedFile::targetName('b.json');
$C = ManagedFile::targetName('c.csv');
$src = ['a.md' => "# A\n", 'b.json' => '{"v":2}', 'c.csv' => 'x,y'];

echo "first run\n";
$p = BootstrapPlan::plan($src, [], []);
check('supported files are created', 'create' === action($p, 'a.md') && 'create' === action($p, 'b.json'));
check('a type with no safe header is not installed', 'unsupported' === action($p, 'c.csv') && ['c.csv'] === $p['unsupported']);
check('two writes, each with the managed header, each refusing to replace anything', 2 === \count($p['writes']) && str_contains($p['writes'][0]['content'], 'MANAGED FRAMEWORK FILE') && 'fail' === $p['writes'][0]['on_conflict'] && '' === $p['writes'][0]['expected_sha256']);
check('write targets carry the prefix', $A === $p['writes'][0]['target'] && $B === $p['writes'][1]['target']);
check('manifest records the two installed files, not the unsupported one', [$A, $B] === array_keys($p['manifest']) && !isset($p['manifest'][$C]));
check('manifest hashes are source hash and installed hash', record('a.md', "# A\n") === $p['manifest'][$A]);
check('nothing flagged as edited', [] === $p['edited'] && [] === $p['kept_edited'] && [] === $p['overwritten_unrecorded'] && [] === $p['orphaned']);
check('counts add up', ['create' => 2, 'unsupported' => 1] === $p['counts']);

echo "\nre-run, nothing changed\n";
$existing = [$A => installedSha('a.md', "# A\n"), $B => installedSha('b.json', '{"v":2}')];
$manifest = [$A => record('a.md', "# A\n"), $B => record('b.json', '{"v":2}')];
$p = BootstrapPlan::plan($src, $existing, $manifest);
check('all unchanged, no writes', 'unchanged' === action($p, 'a.md') && 'unchanged' === action($p, 'b.json') && [] === $p['writes']);
check('manifest is unchanged', $manifest === $p['manifest']);

echo "\nsource changed, file untouched since install\n";
$existing = [$A => installedSha('a.md', "# Old\n"), $B => installedSha('b.json', '{"v":2}')];
$manifest = [$A => record('a.md', "# Old\n"), $B => record('b.json', '{"v":2}')];
$p = BootstrapPlan::plan($src, $existing, $manifest);
check('updated', 'update' === action($p, 'a.md') && 'unchanged' === action($p, 'b.json'));
check('only the changed file is written, and only over the version it was installed as', 1 === \count($p['writes']) && 'overwrite' === $p['writes'][0]['on_conflict'] && $existing[$A] === $p['writes'][0]['expected_sha256']);
check('not reported as edited', [] === $p['edited']);
check('manifest moves to the new source', record('a.md', "# A\n") === $p['manifest'][$A]);

echo "\nedited locally, source changed\n";
$existing = [$A => ManagedFile::sha256('hacked'), $B => installedSha('b.json', '{"v":2}')];
$p = BootstrapPlan::plan($src, $existing, [$A => record('a.md', "# Old\n"), $B => record('b.json', '{"v":2}')]);
check('overwritten, and listed as edited first', 'overwrite_edited' === action($p, 'a.md') && [$A] === $p['edited']);
check('the write is pinned to the edited version, so it cannot hide a newer edit', 1 === \count($p['writes']) && ManagedFile::sha256('hacked') === $p['writes'][0]['expected_sha256']);

echo "\nedited locally, source unchanged\n";
$old = record('a.md', "# A\n");
$existing = [$A => ManagedFile::sha256('hacked'), $B => installedSha('b.json', '{"v":2}')];
$p = BootstrapPlan::plan($src, $existing, [$A => $old, $B => record('b.json', '{"v":2}')]);
check('not written, listed as edited', 'edited_kept' === action($p, 'a.md') && [] === $p['writes'] && [$A] === $p['kept_edited']);
check('record kept, so the edit is still seen next time', $old === $p['manifest'][$A]);

echo "\nno install record\n";
$p = BootstrapPlan::plan($src, [$A => ManagedFile::sha256('mystery')], []);
check('different content: overwritten and listed', 'overwrite_unrecorded' === action($p, 'a.md') && [$A] === $p['overwritten_unrecorded'] && ManagedFile::sha256('mystery') === $p['writes'][0]['expected_sha256']);
$p = BootstrapPlan::plan($src, [$A => installedSha('a.md', "# A\n")], []);
check('identical content: unchanged, record adopted', 'unchanged' === action($p, 'a.md') && record('a.md', "# A\n") === $p['manifest'][$A]);

echo "\nsource removed, unsupported with a record\n";
$gone = ManagedFile::targetName('gone.md');
$goneRec = record('gone.md', 'old');
$cRec = ['source_sha256' => 'x', 'installed_sha256' => 'y'];
$p = BootstrapPlan::plan($src, [], [$gone => $goneRec, $C => $cRec]);
check('orphan is listed, never written, and kept in the manifest', [$gone] === $p['orphaned'] && $goneRec === $p['manifest'][$gone]);
check('an unsupported file keeps whatever record it had', $cRec === $p['manifest'][$C]);

echo "\nsummary()\n";
$p = BootstrapPlan::plan($src, [$A => ManagedFile::sha256('hacked')], [$A => record('a.md', "# Old\n")]);
$s = BootstrapPlan::summary($p);
check('counts and the non-empty lists only', isset($s['counts'], $s['edited']) && !isset($s['orphaned']) && !isset($s['kept_edited']));
check('no contents and no hashes in the reply', !str_contains(json_encode($s), 'MANAGED') && !str_contains(json_encode($s), ManagedFile::sha256('hacked')));
check('empty source list: nothing to do', [] === BootstrapPlan::plan([], [], [])['writes']);

echo "\ntoRecord(): what is sent to Portal after the writes\n";
$p = BootstrapPlan::plan($src, [], []);
$rec = BootstrapPlan::toRecord($p, [], []);
check('first run: both installed files, with asset name and both hashes, not the unsupported one', 2 === \count($rec) && $A === $rec[0]['asset_name'] && $B === $rec[1]['asset_name'] && record('a.md', "# A\n") === array_slice($rec[0], 1, null, true));
$existing = [$A => installedSha('a.md', "# A\n"), $B => installedSha('b.json', '{"v":2}')];
$manifest = [$A => record('a.md', "# A\n"), $B => record('b.json', '{"v":2}')];
check('re-run, nothing changed, records match: nothing to send', [] === BootstrapPlan::toRecord(BootstrapPlan::plan($src, $existing, $manifest), [], $manifest));
check('identical content but no stored record: adopted', [$A] === array_column(BootstrapPlan::toRecord(BootstrapPlan::plan($src, $existing, [$B => $manifest[$B]]), [], [$B => $manifest[$B]]), 'asset_name'));
$pu = BootstrapPlan::plan($src, [$A => installedSha('a.md', "# Old\n"), $B => $existing[$B]], [$A => record('a.md', "# Old\n"), $B => $manifest[$B]]);
check('an updated file is sent, an unchanged one is not', [$A] === array_column(BootstrapPlan::toRecord($pu, [], [$A => record('a.md', "# Old\n"), $B => $manifest[$B]]), 'asset_name'));
check('a file whose write failed is never sent', [] === BootstrapPlan::toRecord($pu, [$A], [$A => record('a.md', "# Old\n"), $B => $manifest[$B]]));
$pk = BootstrapPlan::plan($src, [$A => ManagedFile::sha256('hacked'), $B => $existing[$B]], $manifest);
check('an edited file that was kept is never sent (Portal would refuse its hash)', 'edited_kept' === action($pk, 'a.md') && [] === BootstrapPlan::toRecord($pk, [], $manifest));
$pe = BootstrapPlan::plan($src, [$A => ManagedFile::sha256('hacked'), $B => $existing[$B]], [$A => record('a.md', "# Old\n"), $B => $manifest[$B]]);
check('an edited file that was overwritten is sent', [$A] === array_column(BootstrapPlan::toRecord($pe, [], [$A => record('a.md', "# Old\n"), $B => $manifest[$B]]), 'asset_name'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
