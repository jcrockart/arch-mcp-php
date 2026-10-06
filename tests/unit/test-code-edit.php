<?php
// Offline proof of the exact-match code-lane edit (CodeEdit::apply and run).
// Throwaway temp repo, no network, no secrets.
//
//     php tests/unit/test-code-edit.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/CodeEdit.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/CodeEdit.php')) {
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

use ArchMcp\ArchTools;
use ArchMcp\CodeEdit;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "apply(): matching rules\n";
$r = CodeEdit::apply("alpha\nbeta\ngamma\n", 'beta', 'BETA');
check('unique match replaced', true === $r['success'] && "alpha\nBETA\ngamma\n" === $r['content']);
check('reports the line (2)', 2 === $r['line']);
$r = CodeEdit::apply("alpha\nbeta\n", 'zeta', 'x');
check('no match refused', false === $r['success'] && 0 === $r['matches']);
$r = CodeEdit::apply("x = 1;\nx = 1;\n", 'x = 1;', 'x = 2;');
check('two matches refused', false === $r['success'] && 2 === $r['matches']);
$r = CodeEdit::apply('aaa', 'aa', 'b');
check('overlapping matches count as two', false === $r['success'] && 2 === $r['matches']);
$r = CodeEdit::apply('abc', '', 'x');
check('empty old refused', false === $r['success']);
$r = CodeEdit::apply('abc', 'b', 'b');
check('identical old/new refused', false === $r['success']);
$r = CodeEdit::apply("line1\r\nline2\r\n", "line1\nline2", 'x');
check('line endings must match exactly (CRLF)', false === $r['success']);
$r = CodeEdit::apply("a\nb\nc\n", "a\nb", "A\nB\nB2");
check('multi-line replace', true === $r['success'] && "A\nB\nB2\nc\n" === $r['content']);
$r = CodeEdit::apply('price: 5', '5', '$1 \\0 \\1');
check('replacement text is literal, not a pattern', true === $r['success'] && 'price: $1 \\0 \\1' === $r['content']);
$r = CodeEdit::apply('keep DROP me', ' DROP me', '');
check('empty new deletes the text', true === $r['success'] && 'keep' === $r['content']);
$r = CodeEdit::apply("caf\u{e9} \u{2014} ok", "\u{2014}", '-');
check('multibyte text is matched byte for byte', true === $r['success'] && "caf\u{e9} - ok" === $r['content']);
$r = CodeEdit::apply('ab', 'a', 'aa');
check('new containing old does not loop', true === $r['success'] && 'aab' === $r['content']);
$r = CodeEdit::apply("first\nsecond", 'first', 'x');
check('line 1 reported for the first line', 1 === $r['line']);
$r = CodeEdit::apply(str_repeat('z', 5000), 'z', 'y');
check('very many matches: refused and capped', false === $r['success'] && 1000 === $r['matches'] && str_contains($r['error'], '1000+'));

echo "\nrun(): through the real code-lane checks\n";
$base = sys_get_temp_dir().'/archedit'.getmypid();
mkdir("$base/src", 0775, true);
mkdir("$base/other", 0775, true);
file_put_contents("$base/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'notes.md']]]]));
file_put_contents("$base/src/a.php", "<?php\n\$a = 1;\n\$b = 2;\n");
file_put_contents("$base/src/dup.php", "<?php\n\$x = 1;\n\$x = 1;\n");
file_put_contents("$base/other/o.php", "<?php\n\$o = 1;\n");
file_put_contents("$base/notes.md", "hello\n");
$session = static function (string $lane) use ($base): void {
    file_put_contents("$base/.arch-session.json", json_encode(['lane' => $lane, 'branch' => 't', 'base_commit' => 'c', 'started_at' => '2026-10-06T00:00:00+00:00']));
};
$tools = new ArchTools($logger, ['label' => 't', 'root' => $base, 'lanes' => ['code' => ''], 'session_tools' => true]);

$session('code');
$r = CodeEdit::run($tools, 'src/a.php', '$b = 2;', '$b = 3;');
check('edit succeeds', true === $r['success'] && 1 === $r['replaced'] && 3 === $r['line']);
check('file changed on disk', "<?php\n\$a = 1;\n\$b = 3;\n" === file_get_contents("$base/src/a.php"));
check('reply does not echo file content', !isset($r['content']));
$before = file_get_contents("$base/src/dup.php");
$r = CodeEdit::run($tools, 'src/dup.php', '$x = 1;', '$x = 2;');
check('ambiguous edit refused', false === $r['success'] && 2 === $r['matches']);
check('ambiguous edit left the file untouched', $before === file_get_contents("$base/src/dup.php"));
$r = CodeEdit::run($tools, 'src/a.php', '$nope', 'x');
check('not-found edit refused', false === $r['success']);
$r = CodeEdit::run($tools, 'src/missing.php', 'a', 'b');
check('missing file refused', false === $r['success'] && str_contains($r['error'], 'does not exist'));
$r = CodeEdit::run($tools, 'other/o.php', '$o = 1;', '$o = 2;');
check('path outside the stage paths refused', false === $r['success']);
check('...and untouched', "<?php\n\$o = 1;\n" === file_get_contents("$base/other/o.php"));
$r = CodeEdit::run($tools, '../escape.php', 'a', 'b');
check('traversal refused', false === $r['success']);

$session('metadata');
$r = CodeEdit::run($tools, 'src/a.php', '$a = 1;', '$a = 9;');
check('session on another lane refused', false === $r['success']);
check('...and untouched', str_contains(file_get_contents("$base/src/a.php"), '$a = 1;'));
unlink("$base/.arch-session.json");
$r = CodeEdit::run($tools, 'src/a.php', '$a = 1;', '$a = 9;');
check('no active session refused', false === $r['success'] && str_contains($r['error'], 'no active session'));
check('...and untouched', str_contains(file_get_contents("$base/src/a.php"), '$a = 1;'));

$session('code');
$restricted = new ArchTools($logger, ['label' => 't', 'root' => $base, 'lanes' => ['code' => ''], 'session_tools' => true, 'write_extensions' => ['md']]);
$r = CodeEdit::run($restricted, 'src/a.php', '$a = 1;', '$a = 9;');
check('write-extension allowlist still applies', false === $r['success'] && str_contains($r['error'], 'not permitted'));
$r = CodeEdit::run($restricted, 'notes.md', 'hello', 'goodbye');
check('...and an allowed type still edits', true === $r['success'] && "goodbye\n" === file_get_contents("$base/notes.md"));

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
