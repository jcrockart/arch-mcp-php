<?php
// Offline proof of SessionLaneGuard and its use by the arch_session_* file
// tools: they refuse where they would put files in the wrong place, and are
// untouched in a metadata-lane session. Throwaway temp repos, no network.
//
//     php tests/unit/test-session-lane-guard.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/SessionLaneGuard.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/SessionLaneGuard.php')) {
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
require_once $ROOT.'/src/SessionLaneGuard.php';

use ArchMcp\ArchTools;
use ArchMcp\SessionLaneGuard;

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
    file_put_contents("$dir/metadata/project.config.json", "{}\n");
    file_put_contents("$dir/metadata/entities/note.json", "{\"entity\":\"Note\"}\n");

    return $dir;
}
function startSession(string $dir, string $lane): void {
    file_put_contents("$dir/.arch-session.json", json_encode(['lane' => $lane, 'branch' => 't', 'base_commit' => 'c', 'started_at' => '2026-10-16T00:00:00+00:00']));
}
function tools(string $dir): ArchTools {
    global $logger;

    return new ArchTools($logger, ['label' => 't', 'root' => $dir, 'lanes' => ['metadata' => 'metadata', 'core' => '', 'code' => ''], 'session_tools' => true]);
}
function strays(string $dir): array {
    // Anything the session tools could have put in the wrong place.
    return array_values(array_filter([
        is_file("$dir/metadata/metadata/proof.json") ? 'metadata/metadata/proof.json' : null,
        is_file("$dir/metadata/src/proof.php") ? 'metadata/src/proof.php' : null,
        is_file("$dir/metadata/entities/new.json") ? 'metadata/entities/new.json' : null,
    ]));
}

$base = sys_get_temp_dir().'/archlaneguard'.getmypid();

// --- one lane: the code lane stages metadata ---------------------------
echo "Code-lane session that stages metadata/ (one lane)\n";
$one = makeRepo("$base/one", ['src', 'metadata', 'arch-gate.json']);
startSession($one, 'code');
$t = tools($one);

$r = $t->archSessionWriteFile('metadata/proof.json', '{}');
check('write refused', false === $r['success']);
check('...and points to the code tools', str_contains($r['error'], 'arch_code_write_file') && str_contains($r['error'], 'repo-root'));
$r = $t->archSessionWriteFile('src/proof.php', '<?php');
check('write of an app path refused too', false === $r['success']);
check('...and nothing landed in the wrong place', [] === strays($one) && !is_file("$one/metadata/src/proof.php"));
$r = $t->archSessionReadFile('entities/note.json');
check('read refused, pointing to arch_code_read_file', false === $r['success'] && str_contains($r['error'], 'arch_code_read_file'));
$r = $t->archSessionListFiles();
check('list refused, pointing to arch_code_list_files', false === $r['success'] && str_contains($r['error'], 'arch_code_list_files'));
check('the code tools still do the job', true === $t->archCodeWriteFile('metadata/entities/new.json', '{}')['success'] && true === $t->archCodeReadFile('arch-gate.json')['success']);

// --- two lanes: the code lane does not stage metadata ------------------
echo "\nCode-lane session that does not stage metadata/ (two lanes)\n";
$two = makeRepo("$base/two", ['src', 'arch-gate.json']);
startSession($two, 'code');
$t2 = tools($two);

$r = $t2->archSessionWriteFile('entities/new.json', '{}');
check('write refused: it would never be committed', false === $r['success'] && str_contains($r['error'], 'never be committed'));
check('...and nothing was written', [] === strays($two));
$r = $t2->archSessionReadFile('entities/note.json');
check('read still allowed (the code tools cannot reach metadata/)', true === $r['success'] && str_contains($r['content'], 'Note'));
$r = $t2->archSessionListFiles();
check('list still allowed', true === $r['success'] && in_array('entities/note.json', $r['files'], true));

// --- no arch-gate.json at all ------------------------------------------
echo "\nCode-lane session with no arch-gate.json\n";
$none = makeRepo("$base/none", null);
startSession($none, 'code');
$t3 = tools($none);
check('write refused', false === $t3->archSessionWriteFile('entities/new.json', '{}')['success'] && [] === strays($none));
check('read allowed', true === $t3->archSessionReadFile('entities/note.json')['success']);

// --- metadata lane: untouched ------------------------------------------
echo "\nMetadata-lane session: unchanged\n";
foreach ([['one', $one, $t], ['two', $two, $t2]] as [$name, $dir, $tools]) {
    startSession($dir, 'metadata');
    $r = $tools->archSessionWriteFile('entities/new.json', '{}');
    check("$name: write works and lands in metadata/", true === $r['success'] && is_file("$dir/metadata/entities/new.json"));
    check("$name: read works", true === $tools->archSessionReadFile('entities/new.json')['success']);
    $l = $tools->archSessionListFiles();
    check("$name: list works", true === $l['success'] && in_array('entities/new.json', $l['files'], true));
    unlink("$dir/metadata/entities/new.json");
}
file_put_contents("$one/.arch-session.json", json_encode(['branch' => 't', 'base_commit' => 'c', 'started_at' => '2026-10-16T00:00:00+00:00']));
check('a session file with no lane counts as metadata (arch.py default)', true === $t->archSessionWriteFile('entities/new.json', '{}')['success']);
unlink("$one/metadata/entities/new.json");

// --- no session --------------------------------------------------------
echo "\nNo session\n";
unlink("$one/.arch-session.json");
check('read works as before', true === $t->archSessionReadFile('entities/note.json')['success']);
check('list works as before', true === $t->archSessionListFiles()['success']);
$r = $t->archSessionWriteFile('entities/new.json', '{}');
check('write still fails for want of a session', false === $r['success'] && str_contains($r['error'], 'no active session'));

// --- the guard on its own ----------------------------------------------
echo "\nSessionLaneGuard\n";
check('stagesMetadata: a folder inside metadata/ counts', (function () use ($base) {
    $d = makeRepo("$base/inner", ['src', 'metadata/entities']);
    return SessionLaneGuard::stagesMetadata($d, 'code');
})());
check('stagesMetadata: a similarly named folder does not', (function () use ($base) {
    $d = makeRepo("$base/similar", ['src', 'metadata-old']);
    return !SessionLaneGuard::stagesMetadata($d, 'code');
})());
check('stagesMetadata: unreadable arch-gate.json is false', (function () use ($base) {
    $d = makeRepo("$base/bad", null);
    file_put_contents("$d/arch-gate.json", '{not json');
    return !SessionLaneGuard::stagesMetadata($d, 'code');
})());
check('an unreadable session file refuses nothing', (function () use ($base) {
    $d = makeRepo("$base/unreadable", ['src']);
    file_put_contents("$d/.arch-session.json", '{not json');
    return null === SessionLaneGuard::refusal($d, SessionLaneGuard::WRITE);
})());

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
