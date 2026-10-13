<?php
// Offline proof of arch_bootstrap_read (BootstrapRead) against a fake Portal:
// list, read, the hash it returns, refusal of anything that is not a plain file
// name before any call, Portal refusals passed through, and a file whose text
// does not match Portal's hash being refused. No network, no files.
//
//     php tests/unit/test-bootstrap-read.php
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

final class FakeReadPortal implements PortalCaller
{
    /** @var array<string, string> */
    public array $source = [];
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];
    /** @var array<string, mixed>|null */
    public ?array $force = null;
    public bool $corrupt = false;

    public function call(string $action, string $slug, array $fields = []): array
    {
        $this->calls[] = [$action, $slug, $fields];
        if (null !== $this->force) {
            return $this->force;
        }
        if ('bootstrap_list' === $action) {
            $files = [];
            foreach ($this->source as $n => $c) {
                $files[] = ['name' => $n, 'size' => \strlen($c), 'sha256' => hash('sha256', $c)];
            }

            return ['success' => true, 'files' => $files];
        }
        if ('bootstrap_read' === $action) {
            $n = (string) $fields['name'];
            if (!isset($this->source[$n])) {
                return ['success' => false, 'code' => 'not_found', 'error' => 'No such arch-bootstrap file.'];
            }

            return ['success' => true, 'name' => $n, 'content' => $this->source[$n].($this->corrupt ? 'x' : ''), 'sha256' => hash('sha256', $this->source[$n])];
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

$p = new FakeReadPortal();
$p->source = ['ARCH-CONSTITUTION.md' => "# Constitution\n", 'entity.schema.json' => '{"v":2}'];

echo "list\n";
$r = BootstrapRead::run($p, 'demo', '');
check('no path lists name, size and sha256 for each file', true === $r['success'] && 2 === \count($r['files']) && 'ARCH-CONSTITUTION.md' === $r['files'][0]['name'] && 15 === $r['files'][0]['size'] && hash('sha256', "# Constitution\n") === $r['files'][0]['sha256']);
check('a blank or whitespace path is also a list', true === BootstrapRead::run($p, 'demo', '   ')['success'] && isset(BootstrapRead::run($p, 'demo', ' ')['files']));
check('the reply points to the next call and carries no file text', str_contains($r['next'], 'path') && !isset($r['content']) && !str_contains(json_encode($r), '# Constitution'));
check('the list call goes to Portal for the slug, with no extra fields', 'bootstrap_list' === $p->calls[0][0] && 'demo' === $p->calls[0][1] && [] === $p->calls[0][2]);

echo "\nread\n";
$p->calls = [];
$r = BootstrapRead::run($p, 'demo', 'ARCH-CONSTITUTION.md');
check('returns the text, its size and its sha256', true === $r['success'] && "# Constitution\n" === $r['content'] && 15 === $r['size'] && hash('sha256', "# Constitution\n") === $r['sha256'] && 'ARCH-CONSTITUTION.md' === $r['name']);
check('one Portal call, bootstrap_read, with the name', 1 === \count($p->calls) && 'bootstrap_read' === $p->calls[0][0] && ['name' => 'ARCH-CONSTITUTION.md'] === $p->calls[0][2]);
$r = BootstrapRead::run($p, 'demo', '  entity.schema.json ');
check('the path is trimmed', true === $r['success'] && 'entity.schema.json' === $r['name']);

echo "\nrefused before any call\n";
foreach (['../x', 'a/b', 'a\\b', "a\x00b", "a\nb", '..', str_repeat('a', 201)] as $bad) {
    $p->calls = [];
    $r = BootstrapRead::run($p, 'demo', $bad);
    check('not a plain file name: '.json_encode(substr($bad, 0, 12)), false === $r['success'] && 'invalid' === $r['code'] && [] === $p->calls);
}

echo "\nPortal refusals and bad data\n";
$r = BootstrapRead::run($p, 'demo', 'nope.md');
check('unknown file: Portal code passed through, with what was being read', false === $r['success'] && 'not_found' === $r['code'] && str_contains($r['error'], "could not read 'nope.md'"));
$q = new FakeReadPortal();
$q->force = ['success' => false, 'code' => 'not_found', 'error' => "No such project 'demo', or not accessible to this account."];
$r = BootstrapRead::run($q, 'demo', '');
check('not a member: the list refusal comes back', false === $r['success'] && 'not_found' === $r['code'] && str_contains($r['error'], 'could not list the framework files'));
$q->force = ['success' => false];
check('a reply with no code or message still gives a usable error', 'server' === BootstrapRead::run($q, 'demo', 'x.md')['code']);
$q->force = ['success' => true, 'name' => 'x.md'];
check('a success with no text is refused, not returned empty', false === BootstrapRead::run($q, 'demo', 'x.md')['success']);
$c = new FakeReadPortal();
$c->source = ['a.md' => 'text'];
$c->corrupt = true;
$r = BootstrapRead::run($c, 'demo', 'a.md');
check('text that does not match the hash Portal reported is refused', false === $r['success'] && 'changed' === $r['code'] && !isset($r['content']));
$e = new FakeReadPortal();
check('an empty source list is a success with no files, not an error', true === BootstrapRead::run($e, 'demo', '')['success'] && [] === BootstrapRead::run($e, 'demo', '')['files']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
