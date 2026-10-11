<?php
// Offline proof of the install_bootstrap run (BootstrapInstall) against a fake
// Portal that behaves like api_assets.php and api_bootstrap.php: first run,
// re-run, source updates, local edits, case-insensitive names, write failures
// (binding withheld, then completed by a re-run), a binding held elsewhere,
// bad input, and call order. No network, no files.
//
//     php tests/unit/test-bootstrap-install.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/BootstrapInstall.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/BootstrapInstall.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/BootstrapInstall.php';

use ArchMcp\BootstrapInstall;
use ArchMcp\ManagedFile;
use ArchMcp\PortalCaller;

final class FakePortal implements PortalCaller
{
    /** @var array<string, string> arch-bootstrap source files */
    public array $source = [];
    /** @var array<string, string> the project's assets, name => content */
    public array $assets = [];
    /** @var array<string, array{0: string, 1: string}> stored install records, name => [source, installed] */
    public array $records = [];
    /** @var array<string, string> Claude project id => slug */
    public array $bindings = [];
    /** @var list<array{0: string, 1: array<string, mixed>}> every call, in order */
    public array $calls = [];
    /** @var array<string, string> lower-case extension => error code for writes */
    public array $failWrite = [];
    public ?string $failName = null;
    public bool $corruptRead = false;
    /** @var array<string, mixed>|null */
    public ?array $listFail = null;

    private function find(string $name): ?string
    {
        foreach (array_keys($this->assets) as $n) {
            if (strtolower((string) $n) === strtolower($name)) {
                return (string) $n;
            }
        }

        return null;
    }

    public function call(string $action, string $slug, array $fields = []): array
    {
        $this->calls[] = [$action, $fields];
        switch ($action) {
            case 'bootstrap_list':
                if (null !== $this->listFail) {
                    return $this->listFail;
                }
                $files = [];
                foreach ($this->source as $n => $c) {
                    $files[] = ['name' => $n, 'size' => \strlen($c), 'sha256' => hash('sha256', $c)];
                }

                return ['success' => true, 'files' => $files];
            case 'bootstrap_read':
                $n = (string) $fields['name'];
                if (!isset($this->source[$n])) {
                    return ['success' => false, 'code' => 'not_found', 'error' => 'No such arch-bootstrap file.'];
                }
                $c = $this->source[$n].($this->corruptRead ? 'x' : '');

                return ['success' => true, 'name' => $n, 'content' => $c, 'sha256' => hash('sha256', $c)];
            case 'list':
                $details = [];
                foreach ($this->assets as $n => $c) {
                    $details[] = ['name' => $n, 'size' => \strlen($c), 'sha256' => hash('sha256', $c)];
                }

                return ['success' => true, 'files' => array_keys($this->assets), 'details' => $details, 'links' => []];
            case 'write':
                $name = (string) $fields['name'];
                $ext = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
                if (isset($this->failWrite[$ext])) {
                    return ['success' => false, 'code' => $this->failWrite[$ext], 'error' => "Agent writes of '.{$ext}' files are not enabled for this project."];
                }
                if ($name === $this->failName) {
                    return ['success' => false, 'code' => 'stale', 'error' => 'Overwrite refused: expected_sha256 does not match.'];
                }
                $existing = $this->find($name);
                $onConflict = (string) ($fields['on_conflict'] ?? 'fail');
                if (null !== $existing) {
                    if ('fail' === $onConflict) {
                        return ['success' => false, 'code' => 'conflict', 'error' => 'A file with that name already exists.'];
                    }
                    if ('overwrite' === $onConflict && ($fields['expected_sha256'] ?? '') !== hash('sha256', $this->assets[$existing])) {
                        return ['success' => false, 'code' => 'stale', 'error' => 'Overwrite refused: expected_sha256 does not match.'];
                    }
                    $store = $existing;
                } else {
                    $store = $name;
                }
                $this->assets[$store] = (string) $fields['content'];

                return ['success' => true, 'action' => null === $existing ? 'created' : 'overwritten', 'name' => $store, 'sha256' => hash('sha256', (string) $fields['content']), 'publish' => (string) ($fields['publish'] ?? '')];
            case 'install_records':
                $rows = [];
                foreach ($this->records as $n => [$s, $i]) {
                    $rows[] = ['asset_name' => $n, 'source_sha256' => $s, 'installed_sha256' => $i, 'installed_by' => 'x', 'installed_at' => '2026-10-11 22:00:00'];
                }

                return ['success' => true, 'files' => $rows];
            case 'record_install':
                $new = [];
                foreach ((array) $fields['files'] as $f) {
                    $cur = $this->find((string) $f['asset_name']);
                    if (null === $cur) {
                        return ['success' => false, 'code' => 'not_found', 'error' => 'No such file.'];
                    }
                    if (hash('sha256', $this->assets[$cur]) !== $f['installed_sha256']) {
                        return ['success' => false, 'code' => 'mismatch', 'error' => 'installed_sha256 does not match the current version.'];
                    }
                    $new[(string) $f['asset_name']] = [$f['source_sha256'], $f['installed_sha256']];
                }
                foreach ($new as $n => $v) {
                    $this->records[$n] = $v;
                }

                return ['success' => true, 'recorded' => \count($new)];
            case 'register_binding':
                $id = (string) $fields['claude_project_id'];
                if (isset($this->bindings[$id]) && $this->bindings[$id] !== $slug) {
                    return ['success' => false, 'code' => 'bound_elsewhere', 'error' => 'This Claude project is registered for a different project; an owner of that project must remove it in Portal.'];
                }
                $created = !isset($this->bindings[$id]);
                $this->bindings[$id] = $slug;

                return ['success' => true, 'created' => $created, 'rebound' => false];
        }

        return ['success' => false, 'code' => 'invalid', 'error' => 'unknown action'];
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_column($this->calls, 0);
    }

    /** @return list<array<string, mixed>> the fields of every call of one action */
    public function fieldsOf(string $action): array
    {
        $out = [];
        foreach ($this->calls as [$a, $f]) {
            if ($a === $action) {
                $out[] = $f;
            }
        }

        return $out;
    }
}

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}
function fresh(): FakePortal {
    $p = new FakePortal();
    $p->source = ['ARCH-CONSTITUTION.md' => "# Constitution\n", 'entity.schema.json' => '{"v":2}'];

    return $p;
}

$ID = '123e4567-e89b-12d3-a456-426614174000';
$TM = ManagedFile::targetName('ARCH-CONSTITUTION.md');
$TJ = ManagedFile::targetName('entity.schema.json');

echo "first run\n";
$p = fresh();
$r = BootstrapInstall::run($p, $p, 'demo', $ID, "  My Claude project\n");
check('succeeds', true === $r['success'] && 2 === $r['written']);
check('both files installed under the managed prefix, with the header', str_contains($p->assets[$TM], 'MANAGED FRAMEWORK FILE') && isset(json_decode($p->assets[$TJ], true)['$comment']) && 2 === \count($p->assets));
check('install records stored, matching what is there', 2 === \count($p->records) && hash('sha256', $p->assets[$TM]) === $p->records[$TM][1] && hash('sha256', $p->source['ARCH-CONSTITUTION.md']) === $p->records[$TM][0]);
check('binding registered for the slug, created', 'demo' === ($p->bindings[$ID] ?? null) && true === $r['binding']['created'] && $ID === $r['binding']['claude_project_id']);
check('call order: read sources, read project, write, record, bind last', ['bootstrap_list', 'bootstrap_read', 'bootstrap_read', 'list', 'install_records', 'write', 'write', 'record_install', 'register_binding'] === $p->actions());
check('new files are created (refusing to replace) and published as root', 'fail' === $p->fieldsOf('write')[0]['on_conflict'] && 'root' === $p->fieldsOf('write')[0]['publish']);
check('the name label is cleaned and passed', 'My Claude project' === $p->fieldsOf('register_binding')[0]['name_label']);
check('files summary says two created', ['create' => 2] === $r['files']['counts']);
check('the reply never contains file text', !str_contains(json_encode($r), 'MANAGED'));

echo "\nre-run, nothing changed\n";
$p->calls = [];
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('succeeds with nothing written', true === $r['success'] && 0 === $r['written'] && ['unchanged' => 2] === $r['files']['counts']);
check('no write and no record call', !\in_array('write', $p->actions(), true) && !\in_array('record_install', $p->actions(), true));
check('binding already there: created false', false === $r['binding']['created']);
check('an empty name label is sent as null', null === $p->fieldsOf('register_binding')[0]['name_label']);

echo "\nsource updated, file untouched\n";
$oldSha = hash('sha256', $p->assets[$TM]);
$p->source['ARCH-CONSTITUTION.md'] = "# Constitution v2\n";
$p->calls = [];
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('only the changed file is written', true === $r['success'] && 1 === $r['written'] && ['update' => 1, 'unchanged' => 1] === $r['files']['counts']);
$w = $p->fieldsOf('write')[0];
check('as an overwrite pinned to the version that was there, keeping its publish setting', $TM === $w['name'] && 'overwrite' === $w['on_conflict'] && $oldSha === $w['expected_sha256'] && '' === $w['publish']);
check('the stored record moved to the new content', hash('sha256', $p->assets[$TM]) === $p->records[$TM][1] && hash('sha256', "# Constitution v2\n") === $p->records[$TM][0]);
check('only the changed file is re-recorded', 1 === \count($p->fieldsOf('record_install')[0]['files']));

echo "\nedited locally, source changed\n";
$p->assets[$TM] = 'hacked';
$p->source['ARCH-CONSTITUTION.md'] = "# Constitution v3\n";
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('overwritten, and named as edited', true === $r['success'] && [$TM] === $r['files']['edited'] && str_contains($p->assets[$TM], 'Constitution v3'));

echo "\nedited locally, source unchanged\n";
$p->assets[$TJ] = 'my own edit';
$p->calls = [];
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('left as the person edited it, and named', true === $r['success'] && 'my own edit' === $p->assets[$TJ] && [$TJ] === $r['files']['kept_edited']);
check('not written and not re-recorded (Portal would refuse its hash)', !\in_array('write', $p->actions(), true) && !\in_array('record_install', $p->actions(), true));

echo "\nnames match without regard to case\n";
$p = fresh();
$p->assets['ARCH-BOOTSTRAP--entity.schema.json'] = (string) ManagedFile::withHeader('entity.schema.json', '{"v":2}');
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
$sameName = array_filter(array_keys($p->assets), static fn ($n) => strtolower((string) $n) === strtolower($TJ));
check('the existing file is recognised, not duplicated', true === $r['success'] && 1 === \count($sameName) && ['create' => 1, 'unchanged' => 1] === $r['files']['counts']);
check('an identical file with no record is adopted', 2 === \count($p->records));

echo "\na write fails\n";
$p = fresh();
$p->failWrite['md'] = 'type_not_enabled';
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('reported as failed with the file and the code', false === $r['success'] && 1 === \count($r['failures']) && $TM === $r['failures'][0]['file'] && 'type_not_enabled' === $r['failures'][0]['code']);
check('the other file is installed and recorded', isset($p->assets[$TJ]) && isset($p->records[$TJ]) && !isset($p->records[$TM]));
check('no binding is registered', [] === $p->bindings && !\in_array('register_binding', $p->actions(), true) && str_contains($r['error'], 'not marked as bootstrapped'));
$p->failWrite = [];
$p->calls = [];
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('a re-run finishes the job and registers the binding', true === $r['success'] && 1 === $r['written'] && 'demo' === ($p->bindings[$ID] ?? null) && true === $r['binding']['created']);

echo "\na write is refused as stale\n";
$p = fresh();
BootstrapInstall::run($p, $p, 'demo', $ID, '');
$p->source['ARCH-CONSTITUTION.md'] = "# Newer\n";
$p->failName = $TM;
$p->calls = [];
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('named as a failure, nothing recorded for it, no re-registration', false === $r['success'] && 'stale' === $r['failures'][0]['code'] && !\in_array('record_install', $p->actions(), true) && !\in_array('register_binding', $p->actions(), true));

echo "\nthe Claude project is bound to a different project\n";
$p = fresh();
$p->bindings[$ID] = 'other-project';
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('files are installed, the run reports the refusal', false === $r['success'] && 'bound_elsewhere' === $r['code'] && 2 === $r['written'] && 2 === \count($p->assets));
check('the other project is not named', !str_contains(json_encode($r), 'other-project'));
check('the binding was not moved', 'other-project' === $p->bindings[$ID]);

echo "\nbad input and Portal refusals\n";
$p = fresh();
$r = BootstrapInstall::run($p, $p, 'demo', 'not-a-guid', '');
check('bad ID: refused before any call', false === $r['success'] && 'invalid' === $r['code'] && [] === $p->calls);
$p = fresh();
$r = BootstrapInstall::run($p, $p, 'demo', "https://claude.ai/project/$ID", '');
check('a pasted project URL is accepted and stored as the GUID', true === $r['success'] && 'demo' === ($p->bindings[$ID] ?? null));
$p = fresh();
$p->listFail = ['success' => false, 'code' => 'source_missing', 'error' => 'This Portal has no usable arch-bootstrap source.'];
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('no bootstrap source: Portal code passed through, nothing else called', false === $r['success'] && 'source_missing' === $r['code'] && ['bootstrap_list'] === $p->actions());
$p = fresh();
$p->listFail = ['success' => false, 'code' => 'not_found', 'error' => "No such project 'demo', or not accessible to this account."];
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('not a member: the Portal refusal comes back with what was being attempted', 'not_found' === $r['code'] && str_contains($r['error'], 'could not list the bootstrap files'));
$p = fresh();
$p->corruptRead = true;
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('a source that changed while being read: refused, nothing written', false === $r['success'] && 'changed' === $r['code'] && [] === $p->assets);
$p = fresh();
$p->source = [];
$r = BootstrapInstall::run($p, $p, 'demo', $ID, '');
check('an empty source list is an error, not a silent success', false === $r['success'] && 'source_missing' === $r['code']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
