<?php
// Offline proof of CommitOutput::compact(): a passing commit's output is
// shortened, a failing or unrecognised one is returned untouched.
//
//     php tests/unit/test-commit-output.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/CommitOutput.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/CommitOutput.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/CommitOutput.php';

use ArchMcp\CommitOutput;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$good = "No syntax errors detected in a.php\nSuite one\n  [PASS] a\n  [PASS] b\n\n2 passed, 0 failed\nSuite two\n  [PASS] c\n\n1 passed, 0 failed\n"
    ."Validating session 'x' (lane: code) before commit...\n\n-- gate: lint\n-- gate: test-one\n-- gate: test-two\n\nCOMMITTED. Session 'x' (lane: code) merged to main and retired.\n";
$bulk = '';
for ($i = 0; $i < 200; ++$i) {
    $bulk .= "  [PASS] filler check $i\n";
}
$good = str_replace("Suite two\n", "Suite two\n".$bulk, $good);
$out = CommitOutput::compact($good);
echo "compact(): passing commit\n";
check('no [PASS] lines remain', false === strpos($out, '[PASS]'));
check('suite names are gone', false === strpos($out, 'Suite one'));
check('check count is summed', str_contains($out, 'Checks: 3 passed, 0 failed.'));
check('Validating line kept', str_contains($out, "Validating session 'x' (lane: code) before commit..."));
check('gates joined on one line, in order', str_contains($out, 'Gates passed (3): lint, test-one, test-two'));
check('no separate -- gate: lines', false === strpos($out, '-- gate:'));
check('COMMITTED line kept', str_contains($out, "COMMITTED. Session 'x' (lane: code) merged to main and retired."));
check('much shorter than the input', \strlen($out) < \strlen($good) / 2);

echo "\ncompact(): left alone\n";
$failed = "Suite\n  [FAIL] boom\n\n0 passed, 1 failed\nValidating session 'x' (lane: code) before commit...\n\n-- gate: lint\n-- gate: test-one\nGATE FAILED\n";
check('failing gate output is unchanged', $failed === CommitOutput::compact($failed));
$noCommit = "Validating session 'x' (lane: code) before commit...\n-- gate: lint\nsomething else\n";
check('no COMMITTED line: unchanged', $noCommit === CommitOutput::compact($noCommit));
check('unrecognised text is unchanged', "hello\n" === CommitOutput::compact("hello\n"));
check('empty string is unchanged', '' === CommitOutput::compact(''));
$mixed = "1 passed, 0 failed\n  [FAIL] hidden\nValidating session 'x' (lane: code) before commit...\n\n-- gate: lint\n\nCOMMITTED. done\n";
check('a [FAIL] line before COMMITTED keeps the full text', $mixed === CommitOutput::compact($mixed));
$noCounts = "Validating session 'x' (lane: code) before commit...\n\n-- gate: lint\n\nCOMMITTED. done\n";
$o2 = CommitOutput::compact($noCounts);
check('no test counts: no Checks line, still compact', false === strpos($o2, 'Checks:') && str_contains($o2, 'Gates passed (1): lint'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
