<?php
// Offline proof of the code-lane line-range read and literal search
// (CodeRead). Throwaway temp tree, no network, no secrets.
//
//     php tests/unit/test-code-read.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/CodeRead.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/CodeRead.php')) {
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
require $ROOT.'/src/CodeRead.php';

use ArchMcp\ArchTools;
use ArchMcp\CodeRead;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "slice(): line ranges\n";
$text = "one\ntwo\nthree\nfour\nfive\n";
$r = CodeRead::slice($text, 2, 3);
check('middle range', true === $r['success'] && "two\nthree" === $r['content'] && 2 === $r['start'] && 3 === $r['end']);
check('total_lines ignores the trailing newline', 5 === $r['total_lines']);
check('more_after true when lines remain', true === $r['more_after']);
$r = CodeRead::slice($text, 4, 99);
check('end past the file is clamped', true === $r['success'] && "four\nfive" === $r['content'] && 5 === $r['end'] && false === $r['more_after']);
$r = CodeRead::slice($text, 6, 7);
check('start past the end refused with the line count', false === $r['success'] && 5 === $r['total_lines']);
$r = CodeRead::slice($text, 0, 3);
check('start 0 refused', false === $r['success']);
$r = CodeRead::slice($text, 3, 2);
check('end before start refused', false === $r['success']);
$r = CodeRead::slice("no newline at end", 1, 1);
check('file without trailing newline', true === $r['success'] && 'no newline at end' === $r['content'] && 1 === $r['total_lines']);
$r = CodeRead::slice('', 1, 1);
check('empty file: start 1 is past the end', false === $r['success'] && 0 === $r['total_lines']);
$big = implode("\n", range(1, 1000))."\n";
$r = CodeRead::slice($big, 1, 1000);
check('request over the cap is shortened and says so', true === $r['success'] && true === $r['capped'] && CodeRead::MAX_LINES === $r['end'] && true === $r['more_after']);
$r = CodeRead::slice("a\r\nb\r\n", 1, 2);
check('CRLF kept byte for byte', "a\r\nb\r" === $r['content']);
$r = CodeRead::slice($big, 998, 1000);
check('last lines of a long file', "998\n999\n1000" === $r['content'] && false === $r['more_after']);

echo "\nfindInText(): literal matching\n";
$h = CodeRead::findInText("alpha\nBeta\nalpha beta\n", 'beta', false);
check('case-sensitive finds only exact case', 1 === \count($h) && 3 === $h[0]['line']);
$h = CodeRead::findInText("alpha\nBeta\nalpha beta\n", 'beta', true);
check('ignore-case finds both', 2 === \count($h) && 2 === $h[0]['line'] && 3 === $h[1]['line']);
$h = CodeRead::findInText("a.c\nabc\n", 'a.c', false);
check('search text is literal, not a pattern', 1 === \count($h) && 1 === $h[0]['line']);
$h = CodeRead::findInText("x(\n", 'x(', false);
check('regex metacharacters are harmless', 1 === \count($h));
$h = CodeRead::findInText(str_repeat('z', 1000)."\n", 'z', false);
check('long lines are cut', 1 === \count($h) && mb_strlen($h[0]['text']) <= 301);
$h = CodeRead::findInText("line\r\n", 'line', false);
check('carriage return trimmed from shown text', 'line' === $h[0]['text']);

echo "\nrange() and search(): through the real code-lane checks\n";
$base = sys_get_temp_dir().'/archread'.getmypid();
mkdir("$base/src", 0775, true);
mkdir("$base/other", 0775, true);
file_put_contents("$base/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'notes.md']]]]));
file_put_contents("$base/src/a.php", "<?php\n\$alpha = 1;\n\$beta = 2;\nfunction needle() {}\n");
file_put_contents("$base/src/b.php", "<?php\nneedle();\nNEEDLE();\n");
file_put_contents("$base/src/bin.php", "<?php\n\0needle\n");
file_put_contents("$base/other/o.php", "<?php\nneedle();\n");
file_put_contents("$base/notes.md", "a needle in notes\n");
$tools = new ArchTools($logger, ['label' => 't', 'root' => $base, 'lanes' => ['code' => ''], 'session_tools' => true]);

$r = CodeRead::range($tools, 'src/a.php', 2, 3);
check('range reads lines 2-3', true === $r['success'] && "\$alpha = 1;\n\$beta = 2;" === $r['content'] && 'src/a.php' === $r['path']);
$r = CodeRead::range($tools, 'src/a.php');
check('default range starts at line 1', true === $r['success'] && 1 === $r['start'] && 4 === $r['end']);
$r = CodeRead::range($tools, 'src/a.php', 3);
check('end omitted: reads from start on', true === $r['success'] && 3 === $r['start'] && 4 === $r['end']);
$r = CodeRead::range($tools, 'other/o.php', 1, 2);
check('range outside the stage paths refused', false === $r['success']);
$r = CodeRead::range($tools, '../x', 1, 2);
check('range with traversal refused', false === $r['success']);
$r = CodeRead::range($tools, 'src/missing.php', 1, 2);
check('range of a missing file refused', false === $r['success'] && str_contains($r['error'], 'does not exist'));

$r = CodeRead::search($tools, 'needle');
check('search finds matches in stage paths only', true === $r['success'] && 3 === $r['match_count']);
$paths = array_unique(array_column($r['matches'], 'path'));
sort($paths);
check('...never in files outside the stage paths', ['notes.md', 'src/a.php', 'src/b.php'] === array_values($paths));
check('...binary files skipped and reported', isset($r['skipped']) && str_contains(implode(',', $r['skipped']), 'src/bin.php'));
check('match carries path, line, text', 4 === ($r['matches'][1]['line'] ?? 0) && 'function needle() {}' === ($r['matches'][1]['text'] ?? ''));
$r = CodeRead::search($tools, 'needle', 'src/b');
check('path prefix narrows the search', true === $r['success'] && 1 === $r['match_count'] && 'src/b.php' === $r['matches'][0]['path']);
$r = CodeRead::search($tools, 'needle', 'src/b', true);
check('ignore-case on a prefix', 2 === $r['match_count']);
$r = CodeRead::search($tools, 'needle', '', false, 2);
check('result cap truncates and says so', 2 === $r['match_count'] && true === $r['truncated'] && isset($r['note']));
$r = CodeRead::search($tools, 'nothing-like-this');
check('no match is success with zero matches', true === $r['success'] && 0 === $r['match_count'] && 0 < $r['files_searched']);
$r = CodeRead::search($tools, 'needle', 'nope/');
check('unknown prefix explained', true === $r['success'] && 0 === $r['files_searched'] && str_contains($r['note'], 'nope/'));
$r = CodeRead::search($tools, '');
check('empty search text refused', false === $r['success']);

$noLane = new ArchTools($logger, ['label' => 't', 'root' => $base, 'lanes' => ['metadata' => 'metadata'], 'session_tools' => true]);
$r = CodeRead::search($noLane, 'needle');
check('profile without a code lane: honest empty result', true === $r['success'] && [] === $r['matches'] && str_contains($r['note'], "no 'code' lane"));

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
