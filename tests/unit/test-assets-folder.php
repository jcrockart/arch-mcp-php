<?php
// Offline proof of the asset folder argument (AssetsCall, ARCH-81) against a
// fake Portal: the `folder` field reaches api_assets.php on all four actions
// (list, read, write, set_publish) when it is given, is left out when it is
// empty or omitted, Portal's reply (including `folders`, `folder`, `archived`
// and refusals) comes back unchanged, and ProjectsTools' four asset tools take
// an optional `folder`. No network, no files.
//
//     php tests/unit/test-assets-folder.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/AssetsCall.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/AssetsCall.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/AssetsCall.php';

use ArchMcp\AssetsCall;
use ArchMcp\PortalCaller;

final class FakeAssetsPortal implements PortalCaller
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $reply */
    public function __construct(public array $reply)
    {
    }

    public function call(string $action, string $slug, array $fields = []): array
    {
        $this->calls[] = [$action, $slug, $fields];

        return $this->reply;
    }
}

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$bodies = [
    'list' => [],
    'read' => ['name' => 'notes.md'],
    'write' => ['name' => 'notes.md', 'content' => 'x', 'on_conflict' => 'fail', 'expected_sha256' => '', 'notes' => '', 'publish' => ''],
    'set_publish' => ['name' => 'notes.md', 'publish' => 'none'],
];

foreach ($bodies as $action => $fields) {
    echo "$action\n";

    $p = new FakeAssetsPortal(['success' => true]);
    AssetsCall::send($p, $action, 'demo', $fields, 'docs/specs');
    check('folder present: one call, same action and slug, folder sent', 1 === \count($p->calls) && $action === $p->calls[0][0] && 'demo' === $p->calls[0][1] && 'docs/specs' === ($p->calls[0][2]['folder'] ?? null));
    check('folder present: the action\'s own fields are untouched', array_diff_key($p->calls[0][2], ['folder' => 1]) === $fields);

    $p = new FakeAssetsPortal(['success' => true]);
    AssetsCall::send($p, $action, 'demo', $fields, '');
    check('folder empty: no folder field is sent', !\array_key_exists('folder', $p->calls[0][2]));
    check('folder empty: the body is exactly the action\'s own fields', $p->calls[0][2] === $fields);

    $p = new FakeAssetsPortal(['success' => true]);
    AssetsCall::send($p, $action, 'demo', $fields);
    check('folder omitted: no folder field is sent', !\array_key_exists('folder', $p->calls[0][2]) && $p->calls[0][2] === $fields);

    $p = new FakeAssetsPortal(['success' => true]);
    AssetsCall::send($p, $action, 'demo', $fields, 'Not A Valid/Folder');
    check('a folder Portal would refuse is still sent unchanged (Portal decides)', 'Not A Valid/Folder' === $p->calls[0][2]['folder']);
}

echo "replies come back unchanged\n";
$list = ['success' => true, 'folder' => '', 'files' => ['a.md'], 'details' => [['name' => 'a.md', 'folder' => '']], 'folders' => [['folder' => '', 'files' => 1], ['folder' => 'docs', 'files' => 2]], 'links' => []];
$p = new FakeAssetsPortal($list);
check('list reply with folders is returned as Portal sent it', $list === AssetsCall::send($p, 'list', 'demo', []));
$write = ['success' => true, 'name' => 'notes.md', 'folder' => 'docs', 'archived' => 1, 'publish' => 'none'];
$p = new FakeAssetsPortal($write);
check('write reply with folder and archived count is returned as Portal sent it', $write === AssetsCall::send($p, 'write', 'demo', $bodies['write'], 'docs'));
$refusal = ['success' => false, 'code' => 'forbidden', 'error' => 'Only a project owner or admin can write to the bootstrap folder.'];
$p = new FakeAssetsPortal($refusal);
check('a refusal (forbidden) is returned unchanged', $refusal === AssetsCall::send($p, 'write', 'demo', $bodies['write'], 'bootstrap'));
$invalid = ['success' => false, 'code' => 'invalid', 'error' => 'Invalid folder.'];
$p = new FakeAssetsPortal($invalid);
check('a refusal (invalid) is returned unchanged', $invalid === AssetsCall::send($p, 'set_publish', 'demo', $bodies['set_publish'], '_archive'));
check('one Portal call per send, never a retry', 1 === \count($p->calls));

echo "the four tools take an optional folder\n";
$src = (string) file_get_contents($ROOT.'/src/ProjectsTools.php');
foreach (['archAssetsWriteFile', 'archAssetsSetPublish', 'archAssetsReadFile', 'archAssetsListFiles'] as $m) {
    $sig = 1 === preg_match('/public function '.$m.'\(([^)]*)\)/', $src, $mm) ? $mm[1] : '';
    check("$m has string \$folder = ''", str_contains($sig, "string \$folder = ''"));
    $start = (int) strpos($src, 'public function '.$m.'(');
    $next = strpos($src, "\n    public function ", $start + 10);
    $block = substr($src, $start, (false === $next ? \strlen($src) : $next) - $start);
    check("$m sends through AssetsCall with the folder", str_contains($block, 'AssetsCall::send(') && str_contains($block, '$folder'));
}
$block = substr($src, (int) strpos($src, 'public function archAssetsReadFile('), 1200);
check('read without an OAuth bearer refuses a folder instead of ignoring it', str_contains($block, "'' !== \$folder"));
$block = substr($src, (int) strpos($src, 'public function archAssetsListFiles('), 1200);
check('list without an OAuth bearer refuses a folder instead of ignoring it', str_contains($block, "'' !== \$folder"));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
