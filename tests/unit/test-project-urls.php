<?php
// Offline proof of the site URLs in arch_project_info (ProjectUrls) and of the
// legacy 'site' lane note: the recognised document-root shape, refusal of
// anything else with a note (never a guess), untrusted config values, the
// legacy single hosting block, and the wiring into ProjectInfo::describe().
// Temp directories only, no network, no git.
//
//     php tests/unit/test-project-urls.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/ProjectUrls.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/ProjectUrls.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/ProjectUrls.php';
require $ROOT.'/src/ProjectInfo.php';

use ArchMcp\ProjectInfo;
use ArchMcp\ProjectUrls;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

$env = static fn (string $domain, string $root): array => [
    'provider' => 'cpanel', 'server_host' => 'h.example.com', 'account_username' => 'crockart',
    'domain' => $domain, 'document_root' => $root, 'deploy_path' => '/home/crockart/x',
];
$cfg = [
    'project' => ['slug' => 'test-bootstrap-0925'],
    'environments' => [
        'staging' => $env('arch-staging.crockart.com.au', '/home/crockart/public_html/arch-staging.crockart.com.au/test-bootstrap-0925'),
        'production' => $env('arch.crockart.com.au', '/home/crockart/public_html/arch.crockart.com.au/test-bootstrap-0925'),
    ],
];

echo "fromConfig(): the provisioned shape\n";
$r = ProjectUrls::fromConfig($cfg);
check('staging URL is the domain plus the project folder', 'https://arch-staging.crockart.com.au/test-bootstrap-0925/' === $r['staging']);
check('production URL likewise', 'https://arch.crockart.com.au/test-bootstrap-0925/' === $r['production']);
check('no note when both are known', !isset($r['note']) && 'metadata/project.config.json' === $r['source']);
check('no server path anywhere in the result', !str_contains(json_encode($r), '/home/'));

echo "\nurlFor(): shapes\n";
check('document root that is the domain web root: the bare domain', 'https://d.example.com/' === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com'));
check('trailing slash on the document root is fine', 'https://d.example.com/app/' === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com/app/'));
check('a deeper folder keeps its segments', 'https://d.example.com/a/b/' === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com/a/b'));
check('domain case does not matter, output is lower case', 'https://arch.example.com/app/' === ProjectUrls::urlFor('Arch.Example.COM', '/home/u/public_html/arch.example.com/app'));
check('dots and hyphens in a folder name are fine', 'https://d.example.com/my-app.v2/' === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com/my-app.v2'));

echo "\nurlFor(): refused, never guessed\n";
check('document root under a different domain', null === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/other.example.com/app'));
check('document root not under public_html', null === ProjectUrls::urlFor('d.example.com', '/var/www/d.example.com/app'));
check('a prefix of the domain is not the domain', null === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com.evil.net/app'));
check('traversal in the folder', null === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com/../x'));
check('a space in a folder name', null === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com/my app'));
check('query characters in a folder name', null === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com/a?b=1'));
check('a folder starting with a dot', null === ProjectUrls::urlFor('d.example.com', '/home/u/public_html/d.example.com/.git'));
check('empty domain', null === ProjectUrls::urlFor('', '/home/u/public_html/d.example.com'));
check('a domain that is not a host name', null === ProjectUrls::urlFor('not a domain', '/home/u/public_html/not a domain'));
check('a domain with a scheme or path in it', null === ProjectUrls::urlFor('https://d.example.com/x', '/home/u/public_html/d.example.com'));
check('empty document root', null === ProjectUrls::urlFor('d.example.com', ''));

echo "\nfromConfig(): partial and legacy configs\n";
$only = ['environments' => ['production' => $cfg['environments']['production']]];
$r = ProjectUrls::fromConfig($only);
check('production only: staging is null, with a note naming staging', null === $r['staging'] && 'https://arch.crockart.com.au/test-bootstrap-0925/' === $r['production'] && str_contains($r['note'], 'staging'));
$legacy = ['hosting' => $env('legacy.example.com', '/home/u/public_html/legacy.example.com')];
$r = ProjectUrls::fromConfig($legacy);
check('legacy single hosting block counts as production', null === $r['staging'] && 'https://legacy.example.com/' === $r['production'] && str_contains($r['note'], 'staging'));
$r = ProjectUrls::fromConfig([]);
check('no hosting at all: both null, one note, no crash', null === $r['staging'] && null === $r['production'] && isset($r['note']));
$odd = ['environments' => ['staging' => $env('s.example.com', '/srv/odd'), 'production' => $cfg['environments']['production']]];
$r = ProjectUrls::fromConfig($odd);
check('one environment unrecognised: only that one is null, with a note', null === $r['staging'] && null !== $r['production'] && str_contains($r['note'], 'staging URL could not be worked out'));
$r = ProjectUrls::fromConfig(['environments' => ['staging' => 'oops', 'production' => ['domain' => 5]]]);
check('wrong types in the config are tolerated', null === $r['staging'] && null === $r['production']);
$both = $cfg + ['hosting' => $env('legacy.example.com', '/home/u/public_html/legacy.example.com')];
check('environments win if both blocks are present', 'https://arch.crockart.com.au/test-bootstrap-0925/' === ProjectUrls::fromConfig($both)['production']);

echo "\nforCheckout(): the file\n";
$base = sys_get_temp_dir().'/archurls'.getmypid();
mkdir("$base/ok/metadata", 0775, true);
file_put_contents("$base/ok/metadata/project.config.json", json_encode($cfg));
$r = ProjectUrls::forCheckout("$base/ok");
check('reads metadata/project.config.json in the checkout', 'https://arch-staging.crockart.com.au/test-bootstrap-0925/' === $r['staging']);
mkdir("$base/none", 0775, true);
$r = ProjectUrls::forCheckout("$base/none");
check('no config file: nulls and a note, no path in it', null === $r['staging'] && null === $r['production'] && str_contains($r['note'], 'no project.config.json') && !str_contains(json_encode($r), $base));
mkdir("$base/bad/metadata", 0775, true);
file_put_contents("$base/bad/metadata/project.config.json", '{oops');
check('invalid JSON: nulls and a note', str_contains(ProjectUrls::forCheckout("$base/bad")['note'], 'not valid JSON'));
check('empty directory name: nulls and a note', null === ProjectUrls::forCheckout('')['staging']);

echo "\nProjectInfo::describe(): wiring and the legacy site lane\n";
$p = static fn (string $root, array $lanes): array => [
    'label' => 'p', 'root' => $root, 'lanes' => $lanes, 'session_tools' => true, 'write_extensions' => ['php'],
    'pull_allowed' => false, 'pull_branch' => 'main', 'push_allowed' => false, 'push_branch' => 'main',
    'db_apply_allowed' => false, 'dependency_manager' => 'none', 'profile_environment' => 'staging',
];
$r = ProjectInfo::describe('demo', 'production', $p("$base/ok", ['code' => '', 'core' => '', 'site' => '', 'metadata' => '']), null);
check('describe() carries the URLs', 'https://arch-staging.crockart.com.au/test-bootstrap-0925/' === $r['urls']['staging'] && 'https://arch.crockart.com.au/test-bootstrap-0925/' === $r['urls']['production']);
check('the site lane is still listed, but explained', \in_array('site', $r['staging']['lanes'], true) && str_contains($r['staging']['lane_notes']['site'], 'legacy') && str_contains($r['staging']['lane_notes']['site'], "'code' lane"));
check('only the site lane gets a note', ['site'] === array_keys($r['staging']['lane_notes']));
$r = ProjectInfo::describe('demo', 'production', $p("$base/ok", ['code' => '', 'core' => '']), null);
check('no site lane: no lane_notes key at all', !isset($r['staging']['lane_notes']));
check('no server path anywhere in the whole reply', !str_contains(json_encode($r), $base) && !str_contains(json_encode($r), '/home/crockart'));

exec('rm -rf '.escapeshellarg($base));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
