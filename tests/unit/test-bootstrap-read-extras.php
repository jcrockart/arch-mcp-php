<?php
// Offline proof of the two ARCH-78 additions to arch_bootstrap_read (BootstrapRead)
// against a fake Portal:
//   A. the caller's Claude project ID goes to Portal as an optional, INFORMATIONAL
//      claude_project_id on both calls, only when it is a valid ID (a pasted project
//      URL is reduced to the GUID); nothing changes when it is empty or invalid;
//   B. the file listing passes through well-formed `version` and `updated` fields
//      and still drops everything else Portal might add.
// The existing behaviour is proved in test-bootstrap-read.php. No network, no files.
//
//     php tests/unit/test-bootstrap-read-extras.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/BootstrapRead.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/BootstrapRead.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/BootstrapRead.php';

use ArchMcp\BootstrapRead;
use ArchMcp\PortalCaller;

final class FakeExtrasPortal implements PortalCaller
{
    /** @var array<string, string> */
    public array $source = [];
    /** @var array<string, array<string, mixed>> extra listing fields per file name */
    public array $extras = [];
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    public function call(string $action, string $slug, array $fields = []): array
    {
        $this->calls[] = [$action, $slug, $fields];
        if ('bootstrap_list' === $action) {
            $files = [];
            foreach ($this->source as $n => $c) {
                $files[] = ['name' => $n, 'size' => \strlen($c), 'sha256' => hash('sha256', $c)] + ($this->extras[$n] ?? []);
            }

            return ['success' => true, 'files' => $files];
        }
        if ('bootstrap_read' === $action) {
            $n = (string) $fields['name'];
            if (!isset($this->source[$n])) {
                return ['success' => false, 'code' => 'not_found', 'error' => 'No such arch-bootstrap file.'];
            }

            return ['success' => true, 'name' => $n, 'content' => $this->source[$n], 'sha256' => hash('sha256', $this->source[$n])] + ($this->extras[$n] ?? []);
        }

        return ['success' => false, 'code' => 'invalid', 'error' => 'unexpected action'];
    }
}

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$ID = '123e4567-e89b-12d3-a456-426614174000';

echo "A. the Claude project ID (informational)\n";
$p = new FakeExtrasPortal();
$p->source = ['a.md' => 'text'];
BootstrapRead::run($p, 'demo', '', strtoupper($ID));
check('list: a valid ID is sent as claude_project_id, in lower case', ['claude_project_id' => $ID] === $p->calls[0][2]);
$p->calls = [];
BootstrapRead::run($p, 'demo', 'a.md', "https://claude.ai/project/$ID");
check('read: a pasted project URL is reduced to the ID and sent with the name', ['name' => 'a.md', 'claude_project_id' => $ID] === $p->calls[0][2]);
$p->calls = [];
BootstrapRead::run($p, 'demo', 'a.md', $ID);
check('read: one Portal call, and the ID rides along with the name', 1 === \count($p->calls) && 'bootstrap_read' === $p->calls[0][0] && 'a.md' === $p->calls[0][2]['name']);
foreach (['', '   ', 'not-a-guid', '00000000-0000-0000-0000-000000000000', str_repeat('a', 400)] as $bad) {
    $p->calls = [];
    BootstrapRead::run($p, 'demo', '', $bad);
    check('list: an empty or invalid ID is not sent: '.json_encode(substr($bad, 0, 12)), [] === $p->calls[0][2]);
}
$p->calls = [];
BootstrapRead::run($p, 'demo', 'a.md', 'not-a-guid');
check('read: an invalid ID leaves the call exactly as before', ['name' => 'a.md'] === $p->calls[0][2]);
$p->calls = [];
$r = BootstrapRead::run($p, 'demo', '../x', $ID);
check('a refused path still makes no call, ID or not', [] === $p->calls && 'invalid' === $r['code']);
$p->calls = [];
$r = BootstrapRead::run($p, 'demo', 'a.md', $ID);
check('the ID does not appear in what the chat gets back', !str_contains(json_encode($r), $ID));

echo "\nB. version and updated on the listing\n";
$p = new FakeExtrasPortal();
$p->source = ['a.md' => 'text', 'b.md' => 'more', 'c.md' => 'last'];
$p->extras = [
    'a.md' => ['version' => '2026-11-02.1', 'updated' => '2026-11-02'],
    'b.md' => ['version' => "  v3\x00\n ", 'updated' => '2026-11-02T10:15:30+11:00', 'secret' => 'x', 'content' => 'leak'],
    'c.md' => ['version' => str_repeat('v', 100), 'updated' => 'yesterday'],
];
$r = BootstrapRead::run($p, 'demo', '');
check('well-formed version and updated are passed through', '2026-11-02.1' === $r['files'][0]['version'] && '2026-11-02' === $r['files'][0]['updated']);
check('a version is cleaned of control characters, and an ISO date-time is accepted', 'v3' === $r['files'][1]['version'] && '2026-11-02T10:15:30+11:00' === $r['files'][1]['updated']);
check('any other new field is still dropped', !isset($r['files'][1]['secret']) && !isset($r['files'][1]['content']) && ['name', 'size', 'sha256', 'version', 'updated'] === array_keys($r['files'][1]));
check('a version over 64 characters is cut, and a badly shaped date is dropped', 64 === \strlen($r['files'][2]['version']) && !isset($r['files'][2]['updated']));
check('name, size and sha256 are never overridden by extras', 'a.md' === $r['files'][0]['name'] && 4 === $r['files'][0]['size'] && hash('sha256', 'text') === $r['files'][0]['sha256']);
foreach (['2026-11-02', '2026-11-02 10:15', '2026-11-02T10:15:30Z', '2026-11-02T10:15:30.5Z', 'tomorrow', '02/11/2026', '2026-11-02; DROP', ''] as $date) {
    $p->extras = ['a.md' => ['updated' => $date]];
    $r = BootstrapRead::run($p, 'demo', '');
    $kept = isset($r['files'][0]['updated']);
    $shouldKeep = \in_array($date, ['2026-11-02', '2026-11-02 10:15', '2026-11-02T10:15:30Z'], true);
    check('updated '.json_encode($date).($shouldKeep ? ' is kept' : ' is dropped'), $kept === $shouldKeep);
}
$p->extras = ['a.md' => ['version' => 42, 'updated' => ['x']]];
$r = BootstrapRead::run($p, 'demo', '');
check('values that are not strings are dropped', ['name', 'size', 'sha256'] === array_keys($r['files'][0]));
$p->extras = ['a.md' => ['version' => '   ']];
$r = BootstrapRead::run($p, 'demo', '');
check('a version that is blank after cleaning is dropped', !isset($r['files'][0]['version']));
$p->extras = [];
$r = BootstrapRead::run($p, 'demo', '');
check('no extras from Portal: the entries are exactly as before', ['name', 'size', 'sha256'] === array_keys($r['files'][0]));
$p->extras = ['a.md' => ['version' => 'v1', 'updated' => '2026-11-02']];
$r = BootstrapRead::run($p, 'demo', 'a.md');
check('the single-file read reply does not gain version or updated', true === $r['success'] && !isset($r['version']) && !isset($r['updated']) && ['success', 'name', 'size', 'sha256', 'content'] === array_keys($r));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
