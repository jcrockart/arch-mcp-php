<?php
// Offline proof of arch_code_read_many (CodeReadMany): path-list parsing, per-file
// errors, and the file-count and byte limits. Throwaway temp tree, no network.
//
//     php tests/unit/test-code-read-many.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/CodeReadMany.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/CodeReadMany.php')) {
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
require $ROOT.'/src/CodeReadMany.php';

use ArchMcp\ArchTools;
use ArchMcp\CodeReadMany;

$logger = new class implements Psr\Log\LoggerInterface { public function __call($m, $a) {} };

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "parsePaths()\n";
check('JSON list', ['src/a.php', 'src/b.php'] === CodeReadMany::parsePaths('["src/a.php","src/b.php"]')['paths']);
check('JSON list with whitespace', ['a', 'b'] === CodeReadMany::parsePaths(" [ \"a\" ,\n \"b\" ] ")['paths']);
check('comma-separated', ['a', 'b'] === CodeReadMany::parsePaths('a, b')['paths']);
check('one per line', ['a', 'b'] === CodeReadMany::parsePaths("a\nb\r\n")['paths']);
check('quotes stripped without brackets', ['a', 'b'] === CodeReadMany::parsePaths('"a", \'b\'')['paths']);
check('duplicates collapse, order kept', ['b', 'a'] === CodeReadMany::parsePaths('b,a,b')['paths']);
$r = CodeReadMany::parsePaths('');
check('empty refused with the format', isset($r['error']) && str_contains($r['error'], 'JSON list') && [] === $r['paths']);
$r = CodeReadMany::parsePaths('[]');
check('empty list refused', isset($r['error']));
$r = CodeReadMany::parsePaths('["a",');
check('broken JSON refused with the format', isset($r['error']) && str_contains($r['error'], 'JSON list'));
$r = CodeReadMany::parsePaths('["a", 5]');
check('non-string entry refused', isset($r['error']));

echo "\nrun(): through the real code-lane checks\n";
$base = sys_get_temp_dir().'/archreadmany'.getmypid();
mkdir("$base/src/many", 0775, true);
mkdir("$base/other", 0775, true);
file_put_contents("$base/arch-gate.json", json_encode(['code' => ['stage' => ['paths' => ['src', 'notes.md']]]]));
file_put_contents("$base/src/a.php", "<?php\n\$a = 1;\n");
file_put_contents("$base/src/b.php", "<?php\n\$b = 2;\n");
file_put_contents("$base/src/bin.php", "<?php\n\xff\xfe\n");
file_put_contents("$base/other/o.php", "<?php\n\$o = 1;\n");
file_put_contents("$base/notes.md", "caf\u{e9} notes\n");
$tools = new ArchTools($logger, ['label' => 't', 'root' => $base, 'lanes' => ['code' => ''], 'session_tools' => true]);

$r = CodeReadMany::run($tools, '["src/a.php","src/b.php"]');
check('two files read, in the order asked', true === $r['success'] && 2 === $r['files_read'] && ['src/a.php', 'src/b.php'] === array_column($r['files'], 'path'));
check('content comes back byte for byte', "<?php\n\$a = 1;\n" === $r['files'][0]['content'] && "<?php\n\$b = 2;\n" === $r['files'][1]['content']);
check('bytes and total_bytes reported', 14 === $r['files'][0]['bytes'] && 28 === $r['total_bytes']);
check('not truncated, no note, no not_read', false === $r['truncated'] && !isset($r['note']) && !isset($r['not_read']));
check('works with no session (read only)', !is_file("$base/.arch-session.json"));
$r = CodeReadMany::run($tools, 'notes.md');
check('multibyte text read, single path as plain text', true === $r['success'] && "caf\u{e9} notes\n" === $r['files'][0]['content']);

$r = CodeReadMany::run($tools, '["src/a.php","src/missing.php","other/o.php","../escape.php","src/bin.php","src/b.php"]');
check('the call succeeds even though some paths fail', true === $r['success'] && 2 === $r['files_read'] && 6 === \count($r['files']));
$byPath = [];
foreach ($r['files'] as $f) {
    $byPath[$f['path']] = $f;
}
check('good files are read around the bad ones', true === $byPath['src/a.php']['success'] && true === $byPath['src/b.php']['success']);
check('missing file: per-file error', false === $byPath['src/missing.php']['success'] && str_contains($byPath['src/missing.php']['error'], 'does not exist'));
check('outside the stage paths: per-file error, no content', false === $byPath['other/o.php']['success'] && !isset($byPath['other/o.php']['content']));
check('traversal: per-file error', false === $byPath['../escape.php']['success']);
check('invalid UTF-8: per-file error, no content', false === $byPath['src/bin.php']['success'] && str_contains($byPath['src/bin.php']['error'], 'UTF-8') && !isset($byPath['src/bin.php']['content']));
check('the reply is valid JSON', false !== json_encode($r));
$r = CodeReadMany::run($tools, '["a",');
check('bad paths argument: a clear error', false === $r['success'] && str_contains($r['error'], 'JSON list'));

echo "\nrun(): the file-count limit\n";
$names = [];
for ($i = 0; $i < CodeReadMany::MAX_FILES + 5; ++$i) {
    file_put_contents("$base/src/many/f$i.txt", "file $i\n");
    $names[] = "src/many/f$i.txt";
}
$r = CodeReadMany::run($tools, json_encode($names));
check('only MAX_FILES are read', true === $r['success'] && CodeReadMany::MAX_FILES === $r['files_read'] && CodeReadMany::MAX_FILES === \count($r['files']));
check('the rest are listed under not_read, in order', array_slice($names, CodeReadMany::MAX_FILES) === $r['not_read']);
check('truncated is true and a note says what to do', true === $r['truncated'] && isset($r['note']) && str_contains($r['note'], 'not_read'));

echo "\nrun(): the byte limit\n";
file_put_contents("$base/src/big1.txt", str_repeat('x', 250000));
file_put_contents("$base/src/big2.txt", str_repeat('y', 250000));
file_put_contents("$base/src/small.txt", 'small');
$r = CodeReadMany::run($tools, '["src/big1.txt","src/big2.txt","src/small.txt"]');
check('first big file read', true === $r['files'][0]['success'] && 250000 === $r['files'][0]['bytes']);
check('second big file not returned, says why and what to do', false === $r['files'][1]['success'] && 250000 === $r['files'][1]['bytes'] && str_contains($r['files'][1]['error'], 'arch_code_read_file'));
check('a later small file still fits', true === $r['files'][2]['success'] && 'small' === $r['files'][2]['content']);
check('truncated is true; total counts only what was returned', true === $r['truncated'] && 250005 === $r['total_bytes'] && 2 === $r['files_read']);
check('the reply stays under the limit', $r['total_bytes'] <= CodeReadMany::MAX_TOTAL_BYTES);

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
