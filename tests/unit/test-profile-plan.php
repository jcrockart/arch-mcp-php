<?php
// Offline proof of PortalProjectResolver::profilePlan(): which of a project's
// profile rows a tool call uses. No database, no network, no secrets.
//
//     php tests/unit/test-profile-plan.php
//
// Locate the app root by walking up until src/PortalProjectResolver.php
// appears, so this works from tests/unit/ or from a payload directory.
$ROOT = __DIR__;
while (!is_file($ROOT.'/src/PortalProjectResolver.php') && \dirname($ROOT) !== $ROOT) {
    $ROOT = \dirname($ROOT);
}
if (!is_file($ROOT.'/src/PortalProjectResolver.php')) {
    fwrite(STDERR, "Cannot locate app root from ".__DIR__."\n");
    exit(2);
}

require $ROOT.'/src/PortalProjectResolver.php';

use ArchMcp\PortalProjectResolver;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $what);
}

echo "Production Portal (the /projects and /projects-token addresses)\n";
check('staging tool prefers the staging row, may fall back',
    PortalProjectResolver::profilePlan('production', 'staging') === ['staging', true]);
check('production tool uses the production row, no fallback',
    PortalProjectResolver::profilePlan('production', 'production') === ['production', false]);
check('unknown kind is treated as production, no fallback',
    PortalProjectResolver::profilePlan('production', 'nonsense') === ['production', false]);
check('empty kind is treated as production, no fallback',
    PortalProjectResolver::profilePlan('production', '') === ['production', false]);

echo "\nStaging Portal (the /projects-staging address)\n";
check('staging tool uses staging rows only',
    PortalProjectResolver::profilePlan('staging', 'staging') === ['staging', false]);
check('production tool still only ever reaches staging rows',
    PortalProjectResolver::profilePlan('staging', 'production') === ['staging', false]);

echo "\nInvariants\n";
$fallbackEver = false;
foreach (['production', 'staging'] as $portal) {
    foreach (['production', 'staging', '', 'x'] as $kind) {
        [$pref, $fb] = PortalProjectResolver::profilePlan($portal, $kind);
        if ($fb && !('production' === $portal && 'staging' === $kind)) {
            $fallbackEver = true;
        }
    }
}
check('fallback only for a staging tool on production Portal', !$fallbackEver);

$stagingPortalReachesProduction = false;
foreach (['production', 'staging', '', 'x'] as $kind) {
    [$pref, $fb] = PortalProjectResolver::profilePlan('staging', $kind);
    if ('staging' !== $pref || $fb) {
        $stagingPortalReachesProduction = true;
    }
}
check('staging Portal never reaches a production row', !$stagingPortalReachesProduction);

$productionToolFallsBack = false;
foreach (['production', 'staging'] as $portal) {
    [$pref, $fb] = PortalProjectResolver::profilePlan($portal, 'production');
    if ($fb) {
        $productionToolFallsBack = true;
    }
}
check('a production tool never falls back to another row', !$productionToolFallsBack);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
