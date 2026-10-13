<?php
// Offline proof of the install_bootstrap run (BootstrapRegister) against a fake
// Portal: it registers the (slug, Claude project ID) pair and nothing else,
// cleans the label, accepts a pasted project URL, passes Portal's refusals
// through without leaking another project's name, and refuses bad input before
// any call. No network, no files.
//
//     php tests/unit/test-bootstrap-register.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/BootstrapRegister.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/BootstrapRegister.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/BootstrapRegister.php';

use ArchMcp\BootstrapRegister;
use ArchMcp\PortalCaller;

final class FakeRegisterPortal implements PortalCaller
{
    /** @var array<string, string> Claude project id => slug */
    public array $bindings = [];
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];
    /** @var array<string, mixed>|null a scripted reply that overrides everything */
    public ?array $force = null;

    public function call(string $action, string $slug, array $fields = []): array
    {
        $this->calls[] = [$action, $slug, $fields];
        if (null !== $this->force) {
            return $this->force;
        }
        if ('register_binding' !== $action) {
            return ['success' => false, 'code' => 'invalid', 'error' => 'unexpected action'];
        }
        $id = (string) $fields['claude_project_id'];
        if (isset($this->bindings[$id]) && $this->bindings[$id] !== $slug) {
            return ['success' => false, 'code' => 'bound_elsewhere', 'error' => 'This Claude project is registered for a different project; an owner of that project must remove it in Portal.'];
        }
        $created = !isset($this->bindings[$id]);
        $this->bindings[$id] = $slug;

        return ['success' => true, 'created' => $created, 'rebound' => false];
    }
}

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$ID = '123e4567-e89b-12d3-a456-426614174000';

echo "first registration\n";
$p = new FakeRegisterPortal();
$r = BootstrapRegister::run($p, 'demo', strtoupper($ID), "  My Claude project\n");
check('succeeds, ID folded to lower case, created', true === $r['success'] && 'demo' === $r['slug'] && $ID === $r['binding']['claude_project_id'] && true === $r['binding']['created'] && false === $r['binding']['rebound']);
check('exactly one Portal call, register_binding, for the slug', 1 === \count($p->calls) && 'register_binding' === $p->calls[0][0] && 'demo' === $p->calls[0][1]);
check('the label is cleaned', 'My Claude project' === $p->calls[0][2]['name_label'] && $ID === $p->calls[0][2]['claude_project_id']);
check('the reply says what to do next, naming the tool and the hash check', str_contains($r['next'], 'arch_bootstrap_read') && str_contains($r['next'], 'sha256') && str_contains($r['next'], 'claudeProjectId'));
check('the reply carries no file contents', !isset($r['content']) && !isset($r['files']));

echo "\nre-run and variants\n";
$r = BootstrapRegister::run($p, 'demo', $ID, '');
check('same pair again: success, created false', true === $r['success'] && false === $r['binding']['created']);
check('an empty name is sent as null', null === $p->calls[1][2]['name_label']);
$p = new FakeRegisterPortal();
$r = BootstrapRegister::run($p, 'demo', "https://claude.ai/project/$ID", '');
check('a pasted project URL is accepted and stored as the GUID', true === $r['success'] && 'demo' === ($p->bindings[$ID] ?? null));

echo "\nrefusals\n";
$p = new FakeRegisterPortal();
$r = BootstrapRegister::run($p, 'demo', 'not-a-guid', '');
check('bad ID: refused before any call', false === $r['success'] && 'invalid' === $r['code'] && [] === $p->calls);
$p = new FakeRegisterPortal();
$p->bindings[$ID] = 'other-project';
$r = BootstrapRegister::run($p, 'demo', $ID, '');
check('bound to another project: code passed through, binding not moved', false === $r['success'] && 'bound_elsewhere' === $r['code'] && 'other-project' === $p->bindings[$ID]);
check('the other project is not named', !str_contains(json_encode($r), 'other-project'));
$p = new FakeRegisterPortal();
$p->force = ['success' => false, 'code' => 'not_found', 'error' => "No such project 'demo', or not accessible to this account."];
$r = BootstrapRegister::run($p, 'demo', $ID, '');
check('not a member: the Portal refusal comes back', false === $r['success'] && 'not_found' === $r['code'] && str_contains($r['error'], 'could not register'));
$p->force = ['success' => false];
$r = BootstrapRegister::run($p, 'demo', $ID, '');
check('a reply with no code or message still gives a usable error', false === $r['success'] && 'server' === $r['code'] && '' !== $r['error']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
