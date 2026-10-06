<?php
// Offline proof of the multi-edit tool (CodeEditMany): all-or-nothing
// matching, several files, ordering, and the real code-lane write checks.
// Throwaway temp tree, no network, no secrets.
//
//     php tests/unit/test-code-edit-many.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/CodeEditMany.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/CodeEditMany.php')) {
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
require $ROOT.'/src/CodeEdit.php';
require $ROOT.'/src/CodeEditMany.php';

use ArchMcp\ArchTools;
use ArchMcp\CodeEditMany;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$base = sys_get_temp_dir().'/archmany'.getmypid();
mkdir("$base/src", 0775, true);
mkdir("$base/other", 0775, true);
file_put_contents("$base/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'notes.md']]]]));
$reset = static function () use ($base): void {
    file_put_contents("$base/src/a.php", "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");
    file_put_contents("$base/src/b.php", "<?php\n\$x = 1;\n");
    file_put_contents("$base/src/dup.php", "<?php\n\$d = 1;\n\$d = 1;\n");
    file_put_contents("$base/other/o.php", "<?php\n\$o = 1;\n");
    file_put_contents("$base/notes.md", "hello\n");
};
$session = static function (string $lane) use ($base): void {
    file_put_contents("$base/.arch-session.json", json_encode(['lane' => $lane, 'branch' => 't', 'base_commit' => 'c', 'started_at' => '2026-10-06T00:00:00+00:00']));
};
$tools = new ArchTools($logger, ['label' => 't', 'root' => $base, 'lanes' => ['code' => ''], 'session_tools' => true]);
$read = static fn (string $p): string => (string) file_get_contents("$base/$p");

echo "success paths\n";
$reset(); $session('code');
$r = CodeEditMany::run($tools, [
    ['path' => 'src/a.php', 'old' => '$a = 1;', 'new' => '$a = 10;'],
    ['path' => 'src/b.php', 'old' => '$x = 1;', 'new' => '$x = 2;'],
    ['path' => 'src/a.php', 'old' => '$c = 3;', 'new' => '$c = 30;'],
]);
check('three edits over two files succeed', true === $r['success'] && 3 === $r['edits_applied'] && 2 === \count($r['files']));
check('first file has both edits', "<?php\n\$a = 10;\n\$b = 2;\n\$c = 30;\n" === $read('src/a.php'));
check('second file edited', "<?php\n\$x = 2;\n" === $read('src/b.php'));
check('reply counts edits per file and first line', 2 === $r['files'][0]['edits'] && 2 === $r['files'][0]['first_line'] && 1 === $r['files'][1]['edits']);
check('reply never echoes content', !isset($r['content']) && !isset($r['files'][0]['content']));

$reset();
$r = CodeEditMany::run($tools, [
    ['path' => 'src/a.php', 'old' => '$a = 1;', 'new' => '$a = 5;'],
    ['path' => 'src/a.php', 'old' => '$a = 5;', 'new' => '$a = 6;'],
]);
check('a later edit can build on an earlier one', true === $r['success'] && str_contains($read('src/a.php'), '$a = 6;'));

$reset();
$r = CodeEditMany::runJson($tools, '[{"path":"notes.md","old":"hello","new":"goodbye"}]');
check('runJson accepts the JSON text', true === $r['success'] && "goodbye\n" === $read('notes.md'));

echo "\nall-or-nothing on matching\n";
$reset();
$r = CodeEditMany::run($tools, [
    ['path' => 'src/a.php', 'old' => '$a = 1;', 'new' => '$a = 10;'],
    ['path' => 'src/b.php', 'old' => '$nope', 'new' => 'x'],
]);
check('a failing edit refuses the whole call', false === $r['success'] && 2 === $r['failed_edit'] && 'src/b.php' === $r['path'] && [] === $r['written']);
check('...and the first file is untouched', str_contains($read('src/a.php'), '$a = 1;'));
$r = CodeEditMany::run($tools, [
    ['path' => 'src/b.php', 'old' => '$x = 1;', 'new' => '$x = 2;'],
    ['path' => 'src/dup.php', 'old' => '$d = 1;', 'new' => '$d = 2;'],
]);
check('an ambiguous edit refuses the whole call', false === $r['success'] && 2 === $r['failed_edit'] && 2 === ($r['matches'] ?? 0));
check('...nothing written', str_contains($read('src/b.php'), '$x = 1;') && "<?php\n\$d = 1;\n\$d = 1;\n" === $read('src/dup.php'));
$r = CodeEditMany::run($tools, [
    ['path' => 'src/a.php', 'old' => '$a = 1;', 'new' => '$a = 10;'],
    ['path' => 'src/a.php', 'old' => '$a = 1;', 'new' => '$a = 11;'],
]);
check('second edit must still match once after the first changed it', false === $r['success'] && 2 === $r['failed_edit'] && str_contains($read('src/a.php'), '$a = 1;'));
$r = CodeEditMany::run($tools, [['path' => 'src/missing.php', 'old' => 'a', 'new' => 'b']]);
check('missing file refused', false === $r['success'] && 1 === $r['failed_edit']);

echo "\ninput validation\n";
check('empty list refused', false === CodeEditMany::run($tools, [])['success']);
check('non-list refused', false === CodeEditMany::run($tools, ['path' => 'a'])['success']);
check('missing field refused', false === CodeEditMany::run($tools, [['path' => 'src/a.php', 'old' => 'a']])['success']);
check('non-string field refused', false === CodeEditMany::run($tools, [['path' => 'src/a.php', 'old' => 1, 'new' => 'b']])['success']);
check('empty path refused', false === CodeEditMany::run($tools, [['path' => '', 'old' => 'a', 'new' => 'b']])['success']);
check('too many edits refused', false === CodeEditMany::run($tools, array_fill(0, 51, ['path' => 'src/a.php', 'old' => 'a', 'new' => 'b']))['success']);
$reset();
$r = CodeEditMany::run($tools, [['path' => 'src/a.php', 'old' => "\$c = 3;\n", 'new' => '']]);
check('empty new (delete) is allowed', true === $r['success'] && !str_contains($read('src/a.php'), '$c'));
$r = CodeEditMany::runJson($tools, '{not json');
check('bad JSON refused with the reason', false === $r['success'] && str_contains($r['error'], 'not valid JSON'));
$r = CodeEditMany::runJson($tools, '"just a string"');
check('JSON that is not a list refused', false === $r['success']);

echo "\nreal code-lane write checks\n";
$reset();
$r = CodeEditMany::run($tools, [['path' => 'other/o.php', 'old' => '$o = 1;', 'new' => '$o = 2;']]);
check('path outside the stage paths refused', false === $r['success'] && str_contains($read('other/o.php'), '$o = 1;'));
$r = CodeEditMany::run($tools, [['path' => '../escape.php', 'old' => 'a', 'new' => 'b']]);
check('traversal refused', false === $r['success']);
$session('metadata');
$r = CodeEditMany::run($tools, [['path' => 'src/a.php', 'old' => '$a = 1;', 'new' => '$a = 9;']]);
check('session on another lane refused, nothing written', false === $r['success'] && [] === $r['written'] && str_contains($read('src/a.php'), '$a = 1;'));
unlink("$base/.arch-session.json");
$r = CodeEditMany::run($tools, [['path' => 'src/a.php', 'old' => '$a = 1;', 'new' => '$a = 9;']]);
check('no active session refused', false === $r['success'] && str_contains($r['error'], 'no active session'));

$session('code');
$restricted = new ArchTools($logger, ['label' => 't', 'root' => $base, 'lanes' => ['code' => ''], 'session_tools' => true, 'write_extensions' => ['md']]);
$reset();
$r = CodeEditMany::run($restricted, [
    ['path' => 'notes.md', 'old' => 'hello', 'new' => 'bye'],
    ['path' => 'src/a.php', 'old' => '$a = 1;', 'new' => '$a = 9;'],
]);
check('refusal part way: reports what was written', false === $r['success'] && 'src/a.php' === $r['path'] && 1 === \count($r['written']) && 'notes.md' === $r['written'][0]['path']);
check('...and says nothing was rolled back', str_contains($r['note'], 'not rolled back'));
check('...refused file untouched, written file changed', str_contains($read('src/a.php'), '$a = 1;') && "bye\n" === $read('notes.md'));

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
