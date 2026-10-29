<?php
// Offline proof of the forgiving confirmDestructive argument (ConfirmIds): parsing
// and the one-line refusal. The database flow that uses it is in
// tests/unit/test-confirm-destructive.php.
//
//     php tests/unit/test-confirm-ids.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ConfirmIds.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ConfirmIds.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require_once $ROOT.'/src/ConfirmIds.php';

use ArchMcp\ConfirmIds;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "parse(): comma-separated\n";
check('plain list', ['a', 'b'] === ConfirmIds::parse('a,b')['ids']);
check('whitespace around ids tolerated', ['a', 'b'] === ConfirmIds::parse('  a ,  b  ')['ids']);
check('empty items and a trailing comma dropped', ['a', 'b'] === ConfirmIds::parse('a,,b,')['ids']);
check('newline-separated works too', ['a', 'b'] === ConfirmIds::parse("a\nb\n")['ids']);
check('one id', ['a'] === ConfirmIds::parse('a')['ids']);
check('duplicates collapse', ['a'] === ConfirmIds::parse('a, a')['ids']);
check('quotes around ids without brackets are stripped', ['a', 'b'] === ConfirmIds::parse('"a", \'b\'')['ids']);
check('hyphens, underscores and dots in ids are kept', ['2026-10-08_add.x'] === ConfirmIds::parse('2026-10-08_add.x')['ids']);
check('empty string: no ids, no error', [] === ConfirmIds::parse('')['ids'] && !isset(ConfirmIds::parse('')['error']));
check('blank string: no ids, no error', [] === ConfirmIds::parse("  \n ")['ids'] && !isset(ConfirmIds::parse("  \n ")['error']));

echo "\nparse(): JSON list\n";
check('JSON list', ['a', 'b'] === ConfirmIds::parse('["a","b"]')['ids']);
check('JSON list with whitespace everywhere', ['a', 'b'] === ConfirmIds::parse(" [ \"a\" ,\n \" b \" ] ")['ids']);
check('JSON list of numbers read as ids', ['1', '2'] === ConfirmIds::parse('[1,2]')['ids']);
check('empty JSON list: no ids, no error', [] === ConfirmIds::parse('[]')['ids'] && !isset(ConfirmIds::parse('[]')['error']));
$r = ConfirmIds::parse('["a","b"');
check('broken JSON: error naming both formats', [] === $r['ids'] && isset($r['error']) && str_contains($r['error'], 'comma-separated') && str_contains($r['error'], 'JSON list'));
$r = ConfirmIds::parse('[["a"]]');
check('nested list refused with the format', isset($r['error']) && str_contains($r['error'], 'JSON list'));
$r = ConfirmIds::parse('[{"id":"a"}]');
check('list of objects refused', isset($r['error']));

echo "\ndestructiveIds() and refusal()\n";
$selected = [
    ['id' => 'a', 'type' => 'schema-additive', 'sql' => 'CREATE TABLE a (id INTEGER);'],
    ['id' => 'c', 'type' => 'schema-destructive', 'sql' => 'DROP TABLE a;'],
    ['id' => 'd', 'type' => 'schema-destructive', 'sql' => 'DROP TABLE b;'],
];
check('destructive ids picked out in order', ['c', 'd'] === ConfirmIds::destructiveIds($selected));
check('none destructive: empty', [] === ConfirmIds::destructiveIds([$selected[0]]));

$unconfirmed = [
    ['id' => 'c', 'reason' => 'declared schema-destructive', 'sql' => 'DROP TABLE a;'],
    ['id' => 'd', 'reason' => 'declared schema-destructive', 'sql' => 'DROP TABLE b;'],
];
$m = ConfirmIds::refusal($unconfirmed, $selected, []);
check('nothing passed: says what to pass, on one line', str_contains($m, 'confirmDestructive="c,d"') && !str_contains($m, "\n"));
check('...and how many', str_contains($m, '2 destructive migration(s)'));
check('...and says nothing was applied', str_starts_with($m, 'nothing was applied'));

$m = ConfirmIds::refusal($unconfirmed, $selected, ['x']);
check('unknown id named', str_contains($m, 'no destructive migration has the id(s) x'));
check('still-unconfirmed ids listed', str_contains($m, 'still unconfirmed: c, d'));
check('accepted ids listed', str_contains($m, 'ids that would be accepted: c,d'));
check('names the formats', str_contains($m, 'comma-separated') && str_contains($m, 'JSON list'));

$m = ConfirmIds::refusal([$unconfirmed[1]], $selected, ['c']);
check('partly confirmed: no "unknown id" complaint', !str_contains($m, 'no destructive migration has'));
check('...names the one still missing', str_contains($m, 'still unconfirmed: d') && str_contains($m, 'accepted: c,d'));

$m = ConfirmIds::refusal($unconfirmed, $selected, ['a']);
check('an additive migration id is not a destructive one', str_contains($m, 'no destructive migration has the id(s) a'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
