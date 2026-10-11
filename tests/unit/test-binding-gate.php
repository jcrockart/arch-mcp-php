<?php
// Offline proof of the per-call binding check (BindingGate): which callers read
// the registry, what is logged and how (ID shortened), and that enforce mode
// blocks while log-only mode only reports. Fake registry, capturing logger.
//
//     php tests/unit/test-binding-gate.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/BindingGate.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/BindingGate.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
require $ROOT.'/src/BindingGate.php';

use ArchMcp\BindingGate;
use ArchMcp\BindingGuard;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$logger = new class implements Psr\Log\LoggerInterface {
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $lines = [];
    public function __call($m, $a) { $this->lines[] = [$m, $a]; }
};
$reset = static function () use ($logger): void { $logger->lines = []; };

$one = '123e4567-e89b-12d3-a456-426614174000';
$three = '323e4567-e89b-12d3-a456-426614174000';
$fetches = 0;
$registry = ['registered' => [$one], 'grandfathered' => false];
$fetch = static function (string $slug) use (&$fetches, &$registry) { ++$fetches; return $registry; };
$U = BindingGuard::CALLER_USER;

echo "registered ID\n";
$e = BindingGate::check($logger, 'enforce', $U, '7', 'demo', $one, $fetch);
check('proceeds, nothing logged, registry read once', null === $e && [] === $logger->lines && 1 === $fetches);

echo "\nenforce blocks, and logs\n";
$reset();
$e = BindingGate::check($logger, 'enforce', $U, '7', 'demo', $three, $fetch);
check('unregistered ID: the message comes back', 'this Claude project is not registered for this project' === $e);
[$level, $args] = $logger->lines[0];
check('logged as a warning with slug, user, outcome, mode and blocked', 'warning' === $level && 'binding_check' === $args[0] && 'demo' === $args[1]['slug'] && '7' === $args[1]['portal_user_id'] && 'unregistered_id' === $args[1]['outcome'] && 'enforce' === $args[1]['mode'] && true === $args[1]['blocked']);
check('the ID is logged as its first 8 characters only', '323e4567' === $args[1]['claude_project'] && !str_contains(json_encode($logger->lines), $three));
$reset();
$e = BindingGate::check($logger, 'enforce', $U, '7', 'demo', 'nope', $fetch);
check('an invalid ID is logged with no ID at all', 'invalid_id' === $logger->lines[0][1][1]['outcome'] && null === $logger->lines[0][1][1]['claude_project'] && null !== $e);

echo "\nlog-only reports, does not block\n";
$reset();
$e = BindingGate::check($logger, 'log', $U, '7', 'demo', $three, $fetch);
check('proceeds', null === $e);
check('but is logged, marked not blocked', 1 === \count($logger->lines) && false === $logger->lines[0][1][1]['blocked'] && 'log' === $logger->lines[0][1][1]['mode']);

echo "\nregistry unreadable\n";
$unreadable = static fn (string $slug) => null;
$reset();
check('enforce: refused', null !== BindingGate::check($logger, 'enforce', $U, '7', 'demo', $one, $unreadable) && 'registry_unavailable' === $logger->lines[0][1][1]['outcome']);
check('log-only: allowed', null === BindingGate::check($logger, 'log', $U, '7', 'demo', $one, $unreadable));

echo "\ngrandfathered project\n";
$registry = ['registered' => [], 'grandfathered' => true];
$reset();
check('proceeds with no ID and no log line', null === BindingGate::check($logger, 'enforce', $U, '7', 'old', '', $fetch) && [] === $logger->lines);
$registry = ['registered' => [], 'grandfathered' => false];
check('a new project with no binding is refused in enforce mode', 'project not bootstrapped, run bootstrap first' === BindingGate::check($logger, 'enforce', $U, '7', 'new', '', $fetch));

echo "\nexempt callers never read the registry\n";
$fetches = 0;
$reset();
$e = BindingGate::check($logger, 'enforce', BindingGuard::CALLER_PORTAL_SERVICE, '1', 'demo', '', $fetch);
check('Portal service: proceeds, no read, no log', null === $e && 0 === $fetches && [] === $logger->lines);
$e = BindingGate::check($logger, 'enforce', BindingGuard::CALLER_BREAKGLASS, '1', 'demo', '', $fetch);
check('break-glass: proceeds, no read, logged as exempt', null === $e && 0 === $fetches && 'exempt_breakglass' === $logger->lines[0][1][1]['outcome'] && false === $logger->lines[0][1][1]['blocked']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
