<?php
// Offline proof that ONE code-lane session can carry metadata/ files as well
// as app code, when a project's arch-gate.json lists "metadata" in
// code.stage.paths (the one-lane setup), and that a project still on two
// lanes behaves exactly as before. Throwaway temp repos, no network, no
// secrets.
//
//     php tests/unit/test-one-lane.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ArchTools.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ArchTools.php')) {
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
require_once $ROOT.'/src/CodeEdit.php';
require_once $ROOT.'/src/SessionDiscard.php'; // ArchTools.php already loads it

use ArchMcp\ArchTools;
use ArchMcp\CodeEdit;
use ArchMcp\SessionDiscard;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

/** A temp checkout whose arch-gate.json stages the given paths for the code lane. */
function makeRepo(string $dir, array $stagePaths): string {
    mkdir("$dir/src", 0775, true);
    mkdir("$dir/metadata/entities", 0775, true);
    file_put_contents("$dir/arch-gate.json", json_encode(['code' => ['gate' => [], 'stage' => ['label' => 'code', 'paths' => $stagePaths]]]));
    file_put_contents("$dir/src/a.php", "<?php\n\$a = 1;\n");
    file_put_contents("$dir/metadata/project.config.json", "{}\n");

    return $dir;
}
function startSession(string $dir, string $lane): void {
    file_put_contents("$dir/.arch-session.json", json_encode(['lane' => $lane, 'branch' => 't', 'base_commit' => 'c', 'started_at' => '2026-10-16T00:00:00+00:00']));
}
function profile(string $dir, ?array $writeExt = null): array {
    return ['label' => 't', 'root' => $dir, 'lanes' => ['metadata' => 'metadata', 'core' => '', 'code' => ''], 'session_tools' => true, 'write_extensions' => $writeExt];
}

$base = sys_get_temp_dir().'/archonelane'.getmypid();

// --- one lane: "metadata" is in the code lane's stage paths ------------
echo "One lane (code.stage.paths includes metadata)\n";
$one = makeRepo("$base/one", ['src', 'metadata', 'arch-gate.json']);
$tools = new ArchTools($logger, profile($one));
startSession($one, 'code');

$note = json_encode(['entity' => 'Note', 'attributes' => ['Title' => 'String']]);
$r = $tools->archCodeWriteFile('metadata/entities/note.json', $note);
check('code write reaches metadata/', true === $r['success'] && is_file("$one/metadata/entities/note.json"));
$r = $tools->archCodeReadFile('metadata/entities/note.json');
check('code read reaches metadata/', true === $r['success'] && $note === $r['content']);
$files = $tools->archCodeListFiles()['files'];
check('list shows metadata and app files together', in_array('metadata/entities/note.json', $files, true) && in_array('metadata/project.config.json', $files, true) && in_array('src/a.php', $files, true));
$r = CodeEdit::run($tools, 'metadata/project.config.json', '{}', '{"a":1}');
check('exact-match edit works on a metadata file', true === $r['success'] && "{\"a\":1}\n" === file_get_contents("$one/metadata/project.config.json"));
$r = $tools->archCodeWriteFile('src/b.php', "<?php\n");
check('app code still writes in the same session', true === $r['success']);
$r = $tools->archCodeWriteFile('arch-gate.json', file_get_contents("$one/arch-gate.json"));
check('arch-gate.json (in its own stage paths) is writable', true === $r['success']);
$r = $tools->archCodeWriteFile('metadata/.hidden.json', '{}');
check('dot-component rule still applies under metadata/', false === $r['success']);
$r = $tools->archCodeWriteFile('metadata/../.git/config', '[core]');
check('traversal still refused', false === $r['success']);
$r = $tools->archCodeWriteFile('VERSION.json', '{}');
check('a path outside the stage paths is still refused', false === $r['success'] && !is_file("$one/VERSION.json"));
check('commit-time staging includes metadata', in_array('metadata', SessionDiscard::stagePaths($one, 'code'), true) && in_array('src', SessionDiscard::stagePaths($one, 'code'), true));

startSession($one, 'metadata');
$r = $tools->archCodeWriteFile('metadata/entities/other.json', $note);
check('a metadata-lane session still cannot use the code tools', false === $r['success'] && !is_file("$one/metadata/entities/other.json"));

startSession($one, 'code');
$restricted = new ArchTools($logger, profile($one, ['php']));
$r = $restricted->archCodeWriteFile('metadata/entities/third.json', $note);
check('write-extension allowlist still applies to metadata files', false === $r['success'] && str_contains($r['error'], 'not permitted'));

// --- two lanes: stage paths do not include metadata --------------------
echo "\nTwo lanes (code.stage.paths without metadata): behaviour unchanged\n";
$two = makeRepo("$base/two", ['src', 'arch-gate.json']);
$tools2 = new ArchTools($logger, profile($two));
startSession($two, 'code');

$r = $tools2->archCodeWriteFile('metadata/entities/note.json', $note);
check('code write to metadata/ refused', false === $r['success'] && !is_file("$two/metadata/entities/note.json"));
$r = $tools2->archCodeReadFile('metadata/project.config.json');
check('code read of metadata/ refused', false === $r['success']);
$files2 = $tools2->archCodeListFiles()['files'];
check('list does not show metadata', [] === array_filter($files2, static fn (string $f): bool => str_starts_with($f, 'metadata/')));
check('commit-time staging for code does not include metadata', !in_array('metadata', SessionDiscard::stagePaths($two, 'code'), true));
check('metadata lane with no config still defaults to metadata/', ['metadata'] === SessionDiscard::stagePaths($two, 'metadata'));

startSession($two, 'metadata');
$r = $tools2->archSessionWriteFile('entities/note.json', $note);
check('metadata session still writes through the session tools', true === $r['success'] && is_file("$two/metadata/entities/note.json"));
$r = $tools2->archCodeWriteFile('src/b.php', "<?php\n");
check('metadata session still refused by the code tools', false === $r['success']);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
