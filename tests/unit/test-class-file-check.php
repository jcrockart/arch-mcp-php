<?php
// Offline proof of the commit-time bootstrap check (ARCH-85): a class file in
// src/ that src/bootstrap.php never loads must fail the commit gate, which is
// what happened to GameView.php in ttxmas-2026 (v0.1.47 to v0.1.54).
//
//     php tests/unit/test-class-file-check.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ClassFileCheck.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ClassFileCheck.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ClassFileCheck.php';
require $ROOT.'/src/GateRunner.php';

use ArchMcp\ClassFileCheck;
use ArchMcp\GateRunner;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

/** @param array<string, string> $files path under the project => contents */
function project(array $files): string {
    $d = sys_get_temp_dir().'/classcheck'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $body) {
        @mkdir(\dirname("$d/$path"), 0775, true);
        file_put_contents("$d/$path", $body);
    }

    return $d;
}

$cls = static fn (string $name): string => "<?php\ndeclare(strict_types=1);\nfinal class {$name} {}\n";

echo "the ttxmas-2026 mistake\n";
$good = [
    'src/bootstrap.php' => "<?php\nrequire_once __DIR__ . '/Db.php';\nrequire_once __DIR__ . '/Lobby.php';\nrequire_once __DIR__ . '/GameView.php';\nWeb::init();\n",
    'src/Db.php' => $cls('Db'),
    'src/Lobby.php' => $cls('Lobby'),
    'src/GameView.php' => $cls('GameView'),
    'src/arch_node_publish.php' => "<?php\nfunction arch_node_publish() {}\n",
];
$d = project($good);
$r = ClassFileCheck::run($d);
check('all class files required: passes', true === $r['ok']);

$bad = $good;
$bad['src/bootstrap.php'] = "<?php\nrequire_once __DIR__ . '/Db.php';\nrequire_once __DIR__ . '/Lobby.php';\nWeb::init();\n";
$d = project($bad);
$r = ClassFileCheck::run($d);
check('GameView.php missing from bootstrap: fails', false === $r['ok']);
check('the message names the file and its class', str_contains($r['output'], 'src/GameView.php') && str_contains($r['output'], 'class GameView'));
check('the message says what to add', str_contains($r['output'], "require_once __DIR__ . '/GameView.php';"));
check('functions-only file is not flagged', !str_contains($r['output'], 'arch_node_publish'));
check('files that are required are not listed', !str_contains($r['output'], 'Db.php') && !str_contains($r['output'], 'Lobby.php'));

echo "as a commit gate step\n";
$g = GateRunner::run($d, [['name' => ClassFileCheck::STEP, 'builtin' => ClassFileCheck::STEP]], 'sess', 'code');
check('gate run fails at the bootstrap-requires step', false === $g['ok'] && ClassFileCheck::STEP === $g['failedStep']);
check('transcript says the commit was aborted at that gate', str_contains($g['output'], "COMMIT ABORTED at gate 'bootstrap-requires'"));
$g = GateRunner::run(project($good), [['name' => ClassFileCheck::STEP, 'builtin' => ClassFileCheck::STEP]], 'sess', 'code');
check('gate run passes when everything is required', true === $g['ok']);
$v = GateRunner::validateOnly($d);
check('arch_session_validate reports it too', false === $v['ok'] && str_contains($v['output'], 'src/GateView.php') === false && str_contains($v['output'], 'src/GameView.php'));

echo "edge cases\n";
$c = $good;
$c['src/bootstrap.php'] = "<?php\nrequire_once __DIR__ . '/Db.php';\nrequire_once __DIR__ . '/Lobby.php';\n// require_once __DIR__ . '/GameView.php';\n/* require_once __DIR__ . '/GameView.php'; */\n";
check('a commented-out require does not count', false === ClassFileCheck::run(project($c))['ok']);

$c = $good;
$c['src/bootstrap.php'] = "<?php\nrequire_once __DIR__ . '/Db.php';\nrequire_once __DIR__ . '/models.php';\n";
$c['src/models.php'] = "<?php\nrequire_once __DIR__ . '/Lobby.php';\nrequire_once __DIR__ . '/GameView.php';\n";
check('a class loaded through another required file counts', true === ClassFileCheck::run(project($c))['ok']);

$c = $good;
$c['src/lib/Helper.php'] = $cls('Helper');
$c['src/bootstrap.php'] .= '';
check('a class file in a subfolder that nothing requires fails', false === ClassFileCheck::run(project($c))['ok']);
$c['src/bootstrap.php'] = "<?php\nrequire_once __DIR__ . '/Db.php';\nrequire_once __DIR__ . '/Lobby.php';\nrequire_once __DIR__ . '/GameView.php';\nrequire_once __DIR__ . '/lib/Helper.php';\n";
check('and passes once it is required by its path', true === ClassFileCheck::run(project($c))['ok']);

$c = $good;
$c['src/Util.php'] = "<?php\nfunction helper() { return Foo::class; }\n\$x = new class { };\n";
check('Foo::class and an anonymous class are not declarations', true === ClassFileCheck::run(project($c))['ok']);

$c = $good;
$c['src/Status.php'] = "<?php\nenum Status: string { case On = 'on'; }\n";
$c['src/Shape.php'] = "<?php\ninterface Shape {}\ntrait Walks {}\n";
$r = ClassFileCheck::run(project($c));
check('enums, interfaces and traits are class files too', false === $r['ok'] && str_contains($r['output'], 'enum Status') && str_contains($r['output'], 'interface Shape') && str_contains($r['output'], 'trait Walks'));

echo "when it does not apply\n";
check('no src/bootstrap.php: not applicable', false === ClassFileCheck::applies(project(['src/A.php' => $cls('A')])));
check('an autoloader in bootstrap: not applicable', false === ClassFileCheck::applies(project(['src/bootstrap.php' => "<?php\nspl_autoload_register(function (\$c) {});\n", 'src/A.php' => $cls('A')])));
check('composer autoload in bootstrap: not applicable', false === ClassFileCheck::applies(project(['src/bootstrap.php' => "<?php\nrequire __DIR__ . '/../vendor/autoload.php';\n", 'src/A.php' => $cls('A')])));
check('a project with the bootstrap applies', true === ClassFileCheck::applies(project($good)));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
