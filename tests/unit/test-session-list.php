<?php
// Offline proof of SessionList: a one-lane code session's arch_session_list_files
// is answered with the code lane's listing (repo-root paths), and every other
// case is left exactly as it was. Throwaway temp repos, no network.
//
//     php tests/unit/test-session-list.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/SessionList.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/SessionList.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
if (is_file($ROOT.'/src/ArchConfig.php')) {
    require $ROOT.'/src/ArchConfig.php';
} elseif (!class_exists('ArchMcp\\ArchConfig')) {
    eval('namespace ArchMcp; final class ArchConfig { public const REPO_PATH = "/nonexistent"; }');
}
require $ROOT.'/src/ArchTools.php';
require_once $ROOT.'/src/SessionList.php';

use ArchMcp\ArchTools;
use ArchMcp\SessionList;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

/** A temp checkout; $stagePaths null means no arch-gate.json at all. */
function makeRepo(string $dir, ?array $stagePaths): string {
    mkdir("$dir/src", 0775, true);
    mkdir("$dir/metadata/entities", 0775, true);
    if (null !== $stagePaths) {
        file_put_contents("$dir/arch-gate.json", json_encode(['code' => ['gate' => [], 'stage' => ['label' => 'code', 'paths' => $stagePaths]]]));
    }
    file_put_contents("$dir/src/app.php", "<?php\n");
    file_put_contents("$dir/metadata/project.config.json", "{}\n");
    file_put_contents("$dir/metadata/entities/note.json", "{\"entity\":\"Note\"}\n");

    return $dir;
}
function startSession(string $dir, string $lane): void {
    file_put_contents("$dir/.arch-session.json", json_encode(['lane' => $lane, 'branch' => 't', 'base_commit' => 'c', 'started_at' => '2026-10-19T00:00:00+00:00']));
}
function tools(string $dir, array $lanes = ['metadata' => 'metadata', 'core' => '', 'code' => '']): ArchTools {
    global $logger;

    return new ArchTools($logger, ['label' => 't', 'root' => $dir, 'lanes' => $lanes, 'session_tools' => true]);
}

$base = sys_get_temp_dir().'/archsessionlist'.getmypid();

// --- one lane: the code lane stages metadata ---------------------------
echo "Code-lane session that stages metadata/ (one lane)\n";
$one = makeRepo("$base/one", ['src', 'metadata', 'arch-gate.json']);
startSession($one, 'code');
$t = tools($one);

$r = SessionList::viaCodeLane($t, $one);
check('answered, not passed through', null !== $r && true === $r['success']);
check('lists repo-root paths for metadata/ and code', null !== $r && in_array('metadata/entities/note.json', $r['files'], true) && in_array('src/app.php', $r['files'], true) && in_array('arch-gate.json', $r['files'], true));
check('no metadata-relative paths', null !== $r && !in_array('entities/note.json', $r['files'], true));
check('same files as arch_code_list_files', null !== $r && $r['files'] === $t->archCodeListFiles()['files']);
check('says the paths are repo-root', null !== $r && 'repo-root' === $r['paths']);
check('the note points to the code tools', null !== $r && str_contains($r['lane_note'], 'arch_code_list_files') && str_contains($r['lane_note'], 'arch_code_read_file'));

$r = $t->archSessionListFiles();
check('arch_session_list_files itself now succeeds with the same listing', true === $r['success'] && in_array('metadata/entities/note.json', $r['files'], true) && 'repo-root' === $r['paths']);

// --- everything else is untouched --------------------------------------
echo "\nCases that are left alone\n";
startSession($one, 'metadata');
check('metadata-lane session: null', null === SessionList::viaCodeLane($t, $one));
$r = $t->archSessionListFiles();
check('...and the metadata listing keeps relative paths', true === $r['success'] && in_array('entities/note.json', $r['files'], true) && !isset($r['paths']));

unlink("$one/.arch-session.json");
check('no session: null', null === SessionList::viaCodeLane($t, $one));
check('...and the metadata listing works as before', in_array('entities/note.json', $t->archSessionListFiles()['files'], true));

$two = makeRepo("$base/two", ['src', 'arch-gate.json']);
startSession($two, 'code');
$t2 = tools($two);
check('code session that does not stage metadata/: null', null === SessionList::viaCodeLane($t2, $two));
$r = $t2->archSessionListFiles();
check('...and it still lists metadata/ with relative paths', true === $r['success'] && in_array('entities/note.json', $r['files'], true));

$none = makeRepo("$base/none", null);
startSession($none, 'code');
check('code session with no arch-gate.json: null', null === SessionList::viaCodeLane(tools($none), $none));

$core = makeRepo("$base/core", ['src']);
file_put_contents("$core/arch-gate.json", json_encode([
    'code' => ['gate' => [], 'stage' => ['label' => 'code', 'paths' => ['src']]],
    'core' => ['gate' => [], 'stage' => ['label' => 'core', 'paths' => ['metadata']]],
]));
startSession($core, 'core');
$tc = tools($core);
check('another lane that stages metadata/: not delegated', null === SessionList::viaCodeLane($tc, $core));
$r = $tc->archSessionListFiles();
check('...it is still refused, pointing to arch_code_list_files', false === $r['success'] && str_contains($r['error'], 'arch_code_list_files'));

// --- a profile with no code lane ---------------------------------------
echo "\nOne-lane session, profile without a code lane\n";
$nolane = makeRepo("$base/nolane", ['src', 'metadata', 'arch-gate.json']);
startSession($nolane, 'code');
$r = SessionList::viaCodeLane(tools($nolane, ['metadata' => 'metadata']), $nolane);
check('still a success with an empty list', null !== $r && true === $r['success'] && [] === $r['files']);
check('...keeps the reason it is empty', null !== $r && str_contains((string) ($r['note'] ?? ''), "no 'code' lane"));
check('...and still carries the lane note', null !== $r && isset($r['lane_note']) && 'repo-root' === $r['paths']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
