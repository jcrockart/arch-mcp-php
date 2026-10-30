<?php
// Offline proof of the `portal` block in arch_project_info (PortalLinks, ARCH-74):
// the base address is the first trusted issuer, https only, with no trailing
// slash; the project page is built from it; anything else is reported as not
// configured (null plus a note), never guessed. No network, no files.
//
//     php tests/unit/test-portal-links.php
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/PortalLinks.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/PortalLinks.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}
require $ROOT.'/src/PortalLinks.php';

use ArchMcp\PortalLinks;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "a configured https issuer\n";
$r = PortalLinks::describe('production', 'my-app', ['https://portal.crockart.com.au']);
check('environment, base_url and project_url', 'production' === $r['environment'] && 'https://portal.crockart.com.au' === $r['base_url'] && 'https://portal.crockart.com.au/project.php?slug=my-app' === $r['project_url']);
check('no note when configured', !isset($r['note']));
$r = PortalLinks::describe('staging', 'my-app', ['https://staging-portal.crockart.com.au/']);
check('a trailing slash is dropped', 'https://staging-portal.crockart.com.au' === $r['base_url'] && 'https://staging-portal.crockart.com.au/project.php?slug=my-app' === $r['project_url']);
$r = PortalLinks::describe('production', 'my-app', ['  https://portal.crockart.com.au/  ']);
check('surrounding whitespace is dropped', 'https://portal.crockart.com.au' === $r['base_url']);
$r = PortalLinks::describe('production', 'my-app', ['https://portal.crockart.com.au', 'https://other.example']);
check('only the FIRST issuer is used, the same one the Portal client calls', 'https://portal.crockart.com.au' === $r['base_url']);
$r = PortalLinks::describe('production', 'a b&c', ['https://portal.crockart.com.au']);
check('the slug is URL-encoded in the page address', 'https://portal.crockart.com.au/project.php?slug=a%20b%26c' === $r['project_url']);

echo "\nnot configured: null with a note, never a guess\n";
foreach ([
    [[], 'no issuer'],
    [['http://portal.crockart.com.au'], 'plain http'],
    [['portal.crockart.com.au'], 'no scheme'],
    [['ftp://portal.crockart.com.au'], 'another scheme'],
    [['https://'], 'no host'],
    [['https://portal.crockart.com.au/x?y=1'], 'a query string'],
    [['https://portal.crockart.com.au/#f'], 'a fragment'],
    [['https://por tal.crockart.com.au'], 'a space inside'],
    [[''], 'an empty string'],
    [[null], 'null'],
    [[42], 'not a string'],
    [[['https://portal.crockart.com.au']], 'an array'],
] as [$issuers, $label]) {
    $r = PortalLinks::describe('production', 'my-app', $issuers);
    check("$label: base_url and project_url null, note given", null === $r['base_url'] && null === $r['project_url'] && 'production' === $r['environment'] && isset($r['note']) && '' !== $r['note']);
}

echo "\nbaseUrl()\n";
check('same rule on its own', 'https://portal.crockart.com.au' === PortalLinks::baseUrl(['https://portal.crockart.com.au/']) && null === PortalLinks::baseUrl(['http://x.example']) && null === PortalLinks::baseUrl([]));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
