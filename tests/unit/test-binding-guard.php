<?php
// Offline proof of the binding decision (BindingGuard): who is exempt, the
// four ways a call can fail the check, grandfathering, and that log-only mode
// reports every violation but blocks none. Pure function, no network.
//
//     php tests/unit/test-binding-guard.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/BindingGuard.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/BindingGuard.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/BindingGuard.php';

use ArchMcp\BindingGuard;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$one = '123e4567-e89b-12d3-a456-426614174000';
$two = '223e4567-e89b-12d3-a456-426614174000';
$three = '323e4567-e89b-12d3-a456-426614174000';
$reg = [$one, $two];
$E = 'enforce';
$L = 'log';
$U = BindingGuard::CALLER_USER;

echo "exempt callers\n";
$r = BindingGuard::decide($E, BindingGuard::CALLER_PORTAL_SERVICE, '', [], false);
check('Portal service: allowed with no ID, no log', $r['allow'] && 'exempt_service' === $r['outcome'] && !$r['violation'] && !$r['log']);
$r = BindingGuard::decide($E, BindingGuard::CALLER_BREAKGLASS, '', null, false);
check('break-glass: allowed even with an unreadable registry, and logged', $r['allow'] && 'exempt_breakglass' === $r['outcome'] && $r['log'] && !$r['violation']);

echo "\nregistered ID\n";
foreach ([$one, strtoupper($two), "https://claude.ai/project/$one", "  $one "] as $form) {
    $r = BindingGuard::decide($E, $U, $form, $reg, false);
    check("accepted: $form", $r['allow'] && 'ok' === $r['outcome'] && !$r['violation'] && !$r['log'] && null === $r['error']);
}

echo "\nfour ways to fail, enforce mode\n";
$r = BindingGuard::decide($E, $U, '', $reg, false);
check('no ID on a bootstrapped project', !$r['allow'] && 'missing_id' === $r['outcome'] && $r['violation'] && $r['log'] && str_contains($r['error'], 'claudeProjectId'));
$r = BindingGuard::decide($E, $U, "   \n", $reg, false);
check('blank ID counts as missing', !$r['allow'] && 'missing_id' === $r['outcome']);
$r = BindingGuard::decide($E, $U, 'not-a-guid', $reg, false);
check('malformed ID', !$r['allow'] && 'invalid_id' === $r['outcome'] && str_contains($r['error'], 'not a valid'));
$r = BindingGuard::decide($E, $U, $three, $reg, false);
check('valid ID registered for another project', !$r['allow'] && 'unregistered_id' === $r['outcome'] && 'this Claude project is not registered for this project' === $r['error']);
$r = BindingGuard::decide($E, $U, $one, [], false);
check('no registration at all and not grandfathered', !$r['allow'] && 'not_bootstrapped' === $r['outcome'] && 'project not bootstrapped, run bootstrap first' === $r['error']);
$r = BindingGuard::decide($E, $U, '', [], false);
check('not bootstrapped is reported before a missing ID', 'not_bootstrapped' === $r['outcome']);

echo "\ngrandfathering\n";
$r = BindingGuard::decide($E, $U, '', [], true);
check('grandfathered, never bootstrapped: allowed with no ID, no log', $r['allow'] && 'grandfathered' === $r['outcome'] && !$r['violation'] && !$r['log']);
$r = BindingGuard::decide($E, $U, $one, [], true);
check('grandfathered: a supplied ID is not checked while nothing is registered', $r['allow'] && 'grandfathered' === $r['outcome']);
$r = BindingGuard::decide($E, $U, $three, $reg, true);
check('grandfathering ends once an ID is registered', !$r['allow'] && 'unregistered_id' === $r['outcome']);
$r = BindingGuard::decide($E, $U, '', $reg, true);
check('and then a missing ID is refused too', !$r['allow'] && 'missing_id' === $r['outcome']);

echo "\nregistry unreadable\n";
$r = BindingGuard::decide($E, $U, $one, null, true);
check('enforce: refused, not guessed', !$r['allow'] && 'registry_unavailable' === $r['outcome'] && $r['violation'] && $r['log']);
$r = BindingGuard::decide($L, $U, $one, null, false);
check('log-only: allowed, still reported', $r['allow'] && 'registry_unavailable' === $r['outcome'] && $r['violation'] && $r['log'] && null !== $r['error']);

echo "\nlog-only mode blocks nothing\n";
$cases = [
    ['', $reg, false, 'missing_id'],
    ['nope', $reg, false, 'invalid_id'],
    [$three, $reg, false, 'unregistered_id'],
    [$one, [], false, 'not_bootstrapped'],
];
foreach ($cases as [$id, $registered, $grand, $outcome]) {
    $r = BindingGuard::decide($L, $U, $id, $registered, $grand);
    check("$outcome: allowed, flagged as a violation, logged, message kept", $r['allow'] && $r['violation'] && $r['log'] && $outcome === $r['outcome'] && \is_string($r['error']));
}
check('an unknown mode behaves like log-only', BindingGuard::decide('whatever', $U, '', $reg, false)['allow']);
$r = BindingGuard::decide($L, $U, $one, $reg, false);
check('log-only, correct ID: plain ok', $r['allow'] && 'ok' === $r['outcome'] && !$r['violation'] && !$r['log']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
