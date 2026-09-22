<?php

/*
 * ARCH Agent-Connection MCP server — public HTTP entrypoint.
 *
 * Runs under normal Apache/PHP-FPM request handling (no persistent
 * daemon needed — Apache is already the always-on process, the same way
 * it already serves the rest of this host's sites). Each request is
 * handled by StreamableHttpTransport exactly once, then this script
 * exits, same as any other PHP page.
 */

require_once dirname(__DIR__).'/vendor/autoload.php';

// ArchProfiles is new in the 2026-09-01 token-scoping change. Required
// explicitly rather than relying on the autoloader: vendor/ on this host
// was built elsewhere and uploaded, so if its autoloader was generated
// with --classmap-authoritative it will not see a class added later, and
// composer may not be installed on the host to regenerate it. PSR-4
// would find it anyway; this just removes a failure mode from the
// deploy. Harmless either way (require_once).
require_once dirname(__DIR__).'/src/ArchProfiles.php';
require_once dirname(__DIR__).'/src/OAuthBearer.php';
require_once dirname(__DIR__).'/src/PortalProjectResolver.php';
require_once dirname(__DIR__).'/src/ProjectsTools.php';

use ArchMcp\ArchProfiles;
use ArchMcp\ArchTools;
use ArchMcp\OAuthBearer;
use ArchMcp\ProjectsTools;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Capability\Registry\Container;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Http\Discovery\Psr17Factory;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

function archMcpLogger(): LoggerInterface
{
    static $logger = null;
    if (null !== $logger) {
        return $logger;
    }

    return $logger = new class extends AbstractLogger {
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $line = sprintf('[%s] %s', strtoupper((string) $level), $message);
            if ([] !== $context) {
                $line .= ' '.json_encode($context, \JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            error_log($line);
        }
    };
}

set_exception_handler(static function (\Throwable $t): void {
    archMcpLogger()->critical('Uncaught exception: '.$t->getMessage(), ['exception' => $t]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'internal_error']);
});

$logger = archMcpLogger();

// This host's own scheme+host, used below to build absolute URLs
// (WWW-Authenticate's resource_metadata, and the metadata document's
// own `resource` field) WITHOUT hardcoding a domain in git — staging
// and production are two checkouts of the same git history serving two
// different domains, so any URL that needs to differ between them has
// to be computed from the live request or read from deploy-time state
// outside git (see OAuthBearer::ISSUER_URL_PATH for the latter), never
// written as a literal here.
$requestScheme = (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) ? 'https' : 'http';
$requestHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$resourceMetadataUrl = $requestScheme.'://'.$requestHost.'/oauth-protected-resource/projects';

// ---------------------------------------------------------------------
// /oauth-protected-resource/projects — RFC 9728 protected-resource
// metadata for the OAuth-gated /projects address below. A client that
// gets refused there is expected to fetch exactly the URL named in that
// refusal's WWW-Authenticate header (below) to learn how to get a
// token; this is that document.
//
// Served at a NON-standard path — NOT /.well-known/oauth-protected-
// resource/projects, the RFC's conventional location — because
// public/.htaccess's routing whitelist deliberately refuses any path
// segment starting with a dot (documented anti-traversal hardening: "a
// path segment may not begin with a dot"). Carving a dot-segment
// exception into that rule is a change to host-specific deployment
// config outside this git-tracked code lane, and a call for James, not
// this script — flagged to him alongside this change. RFC 9728 doesn't
// require the well-known convention path; a client is meant to fetch
// whatever URL the header actually gives it, so this route is
// spec-compliant, just not at the conventional location. .htaccess
// still needs ONE new RewriteRule added (matching the existing pattern
// already used for ^projects/?$ etc.) before this route is reachable at
// all — that part is not done as of this commit.
//
// Deliberately unauthenticated: RFC 9728 protected-resource metadata
// must be publicly fetchable, unlike every other address in this file.
// ---------------------------------------------------------------------
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', \PHP_URL_PATH);
if (\is_string($requestPath) && 1 === preg_match('#^/oauth-protected-resource/projects/?$#', $requestPath)) {
    $issuer = OAuthBearer::portalIssuer();

    if (null === $issuer) {
        // Deploy-time misconfiguration (missing/empty ISSUER_URL_PATH
        // file) — never advertise an empty authorization_servers list,
        // fail loudly instead so this is noticed rather than silently
        // breaking discovery.
        $logger->critical('OAuthBearer::portalIssuer() unset — cannot serve protected-resource metadata');
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'internal_error']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'resource' => $requestScheme.'://'.$requestHost.'/projects',
        'authorization_servers' => [$issuer],
    ]);
    exit;
}

// ---------------------------------------------------------------------
// /projects — Slice 2 of claude/proposal-arch-mcp-oauth-projects-
// connector.md. A DIFFERENT address, a DIFFERENT authentication
// mechanism, checked BEFORE the token-gate block below rather than
// folded into it: every other address authenticates a single token
// that names ITS OWN profile up front (X-Api-Key, ArchProfiles.php);
// this one authenticates a Portal USER via a short-lived OAuth bearer
// JWT (Authorization header, OAuthBearer.php), and which project a
// call concerns is only known once that call supplies a `slug`
// argument — see ProjectsTools.php's own docblock for why that changes
// the tool-registration shape below (everything unconditional; the
// runtime check inside ProjectsTools/PortalProjectResolver carries the
// full weight, not an advertised-list filter).
//
// UNLIKE the token gate below, an unauthenticated call here gets 401 +
// WWW-Authenticate, not a bare 404. Reviewed with James 2026-09-21 (see
// the proposal doc): the token gate's 404-not-401/403 posture exists so
// an unauthenticated caller can't distinguish "wrong token" from
// "nothing here" — but /projects is meant to be discovered and driven
// through the standard OAuth client flow (claude.ai's own connector
// registration, among others), which requires a real 401 carrying
// resource_metadata per RFC 9728/the MCP authorization spec. A bare 404
// here doesn't hide anything a determined caller couldn't already tell
// from the existence of this address in the proposal doc and the
// broader MCP OAuth discovery convention; it only broke discovery for
// legitimate clients. Still fails CLOSED either way — no header,
// malformed token, bad signature, expired, wrong scope all collapse to
// the same 401, and the raw token is never logged.
// ---------------------------------------------------------------------
if (\is_string($requestPath) && 1 === preg_match('#^/projects/?$#', $requestPath)) {
    $claims = OAuthBearer::verifyRequest($_SERVER);

    if (null === $claims) {
        $logger->warning('Rejected /projects MCP request', ['via' => 'oauth-bearer']);
        http_response_code(401);
        header('Content-Type: application/json');
        header('WWW-Authenticate: Bearer resource_metadata="'.$resourceMetadataUrl.'"');
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }

    $portalUserId = $claims['sub'];
    $logger->info('MCP /projects request authorised', ['portal_user_id' => $portalUserId]);

    $container = new Container();
    $container->set(LoggerInterface::class, $logger);
    $container->set(ProjectsTools::class, new ProjectsTools($logger, $portalUserId));

    // Partitioned per Portal user, same isolation principle as the
    // per-address partitioning below — one authenticated user's MCP
    // session state must never be visible to another.
    $sessionDir = dirname(__DIR__).'/var/sessions/projects-'.hash('sha256', $portalUserId);

    $builder = Server::builder()
        ->setServerInfo('arch-mcp (projects)', '0.3.0')
        ->setLogger($logger)
        ->setContainer($container)
        ->setSession(new FileSessionStore($sessionDir))
        ->addTool([ProjectsTools::class, 'archSessionStart'], 'arch_session_start')
        ->addTool([ProjectsTools::class, 'archCodegenPreview'], 'arch_codegen_preview')
        ->addTool([ProjectsTools::class, 'archSessionCommit'], 'arch_session_commit')
        ->addTool([ProjectsTools::class, 'archSessionDiscard'], 'arch_session_discard')
        ->addTool([ProjectsTools::class, 'archSessionStatus'], 'arch_session_status')
        ->addTool([ProjectsTools::class, 'archSessionWriteFile'], 'arch_session_write_file')
        ->addTool([ProjectsTools::class, 'archSessionReadFile'], 'arch_session_read_file')
        ->addTool([ProjectsTools::class, 'archSessionListFiles'], 'arch_session_list_files')
        ->addTool([ProjectsTools::class, 'archCodeWriteFile'], 'arch_code_write_file')
        ->addTool([ProjectsTools::class, 'archCodeReadFile'], 'arch_code_read_file')
        ->addTool([ProjectsTools::class, 'archCodeListFiles'], 'arch_code_list_files')
        ->addTool([ProjectsTools::class, 'archSiteWriteFile'], 'arch_site_write_file')
        ->addTool([ProjectsTools::class, 'archSiteReadFile'], 'arch_site_read_file')
        ->addTool([ProjectsTools::class, 'archSiteListFiles'], 'arch_site_list_files')
        ->addTool([ProjectsTools::class, 'archAssetsWriteFile'], 'arch_assets_write_file')
        ->addTool([ProjectsTools::class, 'archAssetsReadFile'], 'arch_assets_read_file')
        ->addTool([ProjectsTools::class, 'archAssetsListFiles'], 'arch_assets_list_files')
        ->addTool([ProjectsTools::class, 'archCoreGitStatus'], 'arch_core_git_status')
        ->addTool([ProjectsTools::class, 'archCoreGitDiff'], 'arch_core_git_diff')
        ->addTool([ProjectsTools::class, 'archCoreGitLog'], 'arch_core_git_log')
        ->addTool([ProjectsTools::class, 'archCoreGitShow'], 'arch_core_git_show')
        ->addTool([ProjectsTools::class, 'archCoreGitFetch'], 'arch_core_git_fetch')
        ->addTool([ProjectsTools::class, 'archCoreGitPullFastForward'], 'arch_core_git_pull')
        ->addTool([ProjectsTools::class, 'archCoreGitPushOrigin'], 'arch_core_git_push')
        ->addTool([ProjectsTools::class, 'archCoreDbPendingMigrations'], 'arch_core_db_pending_migrations')
        ->addTool([ProjectsTools::class, 'archCoreDbApplyMigrations'], 'arch_core_db_apply_migrations')
        ->setCapabilities(new ServerCapabilities(
            tools: true,
            toolsListChanged: false,
            resources: false,
            resourcesSubscribe: false,
            resourcesListChanged: false,
            prompts: false,
            promptsListChanged: false,
            logging: false,
            completions: false,
        ));

    $server = $builder->build();

    $psr17 = new Psr17Factory();
    $transport = new StreamableHttpTransport(
        $psr17->createServerRequestFromGlobals(),
        logger: $logger,
    );

    $response = $server->run($transport);

    (new SapiEmitter())->emit($response);

    exit;
}

// ---------------------------------------------------------------------
// Token gate. Added 2026-09-01, addressing scheme revised 2026-09-02.
//
// Before this, the server had no authentication of any kind: anyone who
// knew the URL could call all 11 tools against arch-collab-core,
// including both write lanes (Codegen CLI Design §9 item 5). Now the
// token — in the x-api-key header — selects a profile that decides the
// root directory, the available lanes, and whether the session tools
// exist at all for this caller.
//
// The URL carries the ADDRESS, which is an identifier and not a
// credential: /project/<name> for a dedicated space, or
// /group/<name>/<sub...> for a shared space in which each sub-project
// is a separate subtree under one group token. Both must agree with the
// token's own profile or the request is refused — a connector pointed
// at the wrong address with a valid token must fail loudly rather than
// succeed quietly against the wrong tree.
//
// 404 rather than 401/403, and before any MCP machinery starts: an
// unauthenticated caller should not be able to distinguish "wrong
// token" from "nothing here", nor reach anything that would tell them
// what this endpoint is. The token is never written to the log — only
// the resolved address is.
// ---------------------------------------------------------------------
// One call makes the whole authorisation decision: token (header, or
// path during migration), profile lookup, address match, and — for a
// group — resolving the sub-project subtree.
[$profile, $authVia] = ArchProfiles::resolveRequest($_SERVER);

if (null === $profile) {
    // 'via' records how the caller TRIED to authenticate, never what with.
    $logger->warning('Rejected MCP request', ['via' => $authVia]);
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not_found']);
    exit;
}

// 'via' is the migration signal: once this never reads 'path' again,
// flip ArchProfiles::ALLOW_PATH_TOKEN to false and the URL stops being
// a credential.
$logger->info('MCP request authorised', ['address' => $profile['address'], 'via' => $authVia]);

$container = new Container();
$container->set(LoggerInterface::class, $logger);

// Hand the SDK a pre-built ArchTools carrying this caller's profile,
// rather than letting the container construct a default one.
$container->set(ArchTools::class, new ArchTools($logger, $profile));

// The SDK's default session store (InMemorySessionStore) keeps session
// state in a PHP array in process memory. On this host, every HTTP
// request is served by a fresh, stateless PHP-FPM/LiteSpeed process —
// nothing persists between requests — so an in-memory store can never
// actually retain a session across two separate calls. FileSessionStore
// persists sessions to disk instead, matching the same "durable,
// process-independent state" principle already applied to the git repo
// itself (Codegen CLI Design §7.3).
//
// Session state is additionally partitioned per profile: a session
// belongs to one token's tree and must never be visible to another.
// Partition by ADDRESS, not label: two sub-projects of one group share a
// profile and a token, but must never share session state.
$sessionDir = dirname(__DIR__).'/var/sessions/'.hash('sha256', $profile['address']);

$builder = Server::builder()
    ->setServerInfo('arch-mcp ('.$profile['address'].')', '0.3.0')
    ->setLogger($logger)
    ->setContainer($container)
    ->setSession(new FileSessionStore($sessionDir));

// Tool registration is now per-profile. This filters what the caller is
// TOLD it can do; ArchTools::resolveScopedPath independently enforces
// what it can actually reach, because an unlisted tool can still be
// invoked by name. Both layers are required — neither alone is enough.
if ($profile['session_tools']) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archSessionStart'], 'arch_session_start')
        ->addTool([ArchTools::class, 'archCodegenPreview'], 'arch_codegen_preview')
        ->addTool([ArchTools::class, 'archSessionCommit'], 'arch_session_commit')
        ->addTool([ArchTools::class, 'archSessionDiscard'], 'arch_session_discard')
        ->addTool([ArchTools::class, 'archSessionStatus'], 'arch_session_status');
}

if ($profile['session_tools'] && isset($profile['lanes']['metadata'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archSessionWriteFile'], 'arch_session_write_file')
        ->addTool([ArchTools::class, 'archSessionReadFile'], 'arch_session_read_file')
        ->addTool([ArchTools::class, 'archSessionListFiles'], 'arch_session_list_files');
}

if (isset($profile['lanes']['core'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCoreGitStatus'], 'arch_core_git_status')
        ->addTool([ArchTools::class, 'archCoreGitDiff'], 'arch_core_git_diff')
        ->addTool([ArchTools::class, 'archCoreGitLog'], 'arch_core_git_log')
        ->addTool([ArchTools::class, 'archCoreGitShow'], 'arch_core_git_show')
        ->addTool([ArchTools::class, 'archCoreGitFetch'], 'arch_core_git_fetch');
}

// Added 2026-09-12, alongside item 3 ("pull to production"). Deliberately
// a SEPARATE conditional block from the unconditional core-lane block
// above, gated on the profile's pull_allowed flag (set only via
// mint-tokens.py --allow-pull) — a profile without the flag doesn't even
// see this tool exists, matching the "advertised list is a filter,
// runtime check is the real control, both required" principle already in
// effect for every other lane above. The runtime check lives in
// ArchTools::archCoreGitPullFastForward() itself ($this->pullAllowed),
// so this gate is belt-and-suspenders, not the only enforcement.
if (isset($profile['lanes']['core']) && ($profile['pull_allowed'] ?? false)) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCoreGitPullFastForward'], 'arch_core_git_pull');
}

// Added 2026-09-13, item 4 ("push to origin"). Same pattern as the
// pull_allowed block above: a profile without push_allowed doesn't even
// see this tool exists. Setting push_allowed alone does nothing live
// until a deploy key with write access to that checkout's GitHub origin
// is also configured on the host -- this file and ArchTools.php never
// create, store, or read one.
if (isset($profile['lanes']['core']) && ($profile['push_allowed'] ?? false)) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCoreGitPushOrigin'], 'arch_core_git_push');
}

// Added 2026-09-17 -- schema/data migration tooling (see claude/note-
// 2026-09-17-db-migration-gap-and-proposal.md for the incident and
// design). Read-only "pending migrations" is unconditional for any
// core-lane profile, same as status/diff/log/show/fetch above -- it
// only reads the target project's own migration files and its own
// schema_migrations table, never writes anything.
if (isset($profile['lanes']['core'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCoreDbPendingMigrations'], 'arch_core_db_pending_migrations');
}

// The write-capable counterpart -- same opt-in-per-profile pattern as
// pull_allowed/push_allowed above, via a new db_apply_allowed flag (set
// in profiles.json, never by the live MCP request). A profile without
// the flag doesn't even see this tool exists; the runtime check lives
// in ArchTools::archCoreDbApplyMigrations() itself ($this->dbApplyAllowed),
// so this is belt-and-suspenders, not the only enforcement -- same
// two-layer principle as every other gated tool in this file.
if (isset($profile['lanes']['core']) && ($profile['db_apply_allowed'] ?? false)) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCoreDbApplyMigrations'], 'arch_core_db_apply_migrations');
}

// Added 2026-08-31, deployed with James live at the terminal after an
// overnight draft-and-test cycle (not shipped unattended — see Codegen
// CLI Design §8 for why). Deliberately separate name prefix
// (arch_site_* vs arch_session_*) so the tool list itself documents
// that these aren't session-gated and don't touch metadata/.
if (isset($profile['lanes']['site'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archSiteWriteFile'], 'arch_site_write_file')
        ->addTool([ArchTools::class, 'archSiteReadFile'], 'arch_site_read_file')
        ->addTool([ArchTools::class, 'archSiteListFiles'], 'arch_site_list_files');
}

// Added 2026-09-16 — exposes arch.py's `code` lane over MCP (built and
// proven directly against core/mcp 2026-09-07; this is what lets it be
// driven from here instead of a terminal). Session-gated like metadata,
// not ungoverned like site: archCodeWriteFile itself checks both that a
// session is active AND that its lane is 'code' (see ArchTools.php). A
// profile without a 'code' lane doesn't even see these tools exist,
// same "advertised list is a filter, runtime check is the real control"
// principle as every other block here. See claude/proposal-arch-mcp-
// code-lane.md.
if (isset($profile['lanes']['code'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archCodeWriteFile'], 'arch_code_write_file')
        ->addTool([ArchTools::class, 'archCodeReadFile'], 'arch_code_read_file')
        ->addTool([ArchTools::class, 'archCodeListFiles'], 'arch_code_list_files');
}

// Added for the AI-context "assets" lane — see
// claude/proposal-arch-mcp-assets-lane.md. Same pattern as the site-lane
// block above: a profile without an 'assets' lane doesn't even see
// these tools exist. Used two ways: the per-project profile's own
// read/write assets lane, and every project's read-only
// "<slug>-bootstrap" profile pointed at the shared framework root
// (read-only enforced the normal way, via write_extensions: [] set by
// mint-tokens.py --read-only — there is no separate mechanism here).
if (isset($profile['lanes']['assets'])) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archAssetsWriteFile'], 'arch_assets_write_file')
        ->addTool([ArchTools::class, 'archAssetsReadFile'], 'arch_assets_read_file')
        ->addTool([ArchTools::class, 'archAssetsListFiles'], 'arch_assets_list_files');
}

// Added 2026-09-21 — the Portal-first project inception flow (see
// claude/proposal-portal-first-project-inception.md). Deliberately NOT
// gated on any lane, unlike every block above: this tool doesn't read
// or write anything in THIS profile's own tree at all. It makes an
// outbound HTTPS call to arch-portal's own inception-fill endpoint,
// carrying a slug + one-time token the caller was handed by Portal
// itself when the project was requested there — Portal, not this
// server, validates that pair and remains the sole writer of its own
// `projects` table. So the only thing worth gating here is "may this
// token attempt the call at all", via a new bootstrap_fill_allowed flag
// (set in profiles.json, off by default, provisioned via
// mint-tokens.py --allow-bootstrap-fill). Same "advertised list is a
// filter, runtime check is the real control, both required" principle
// as every other block above — the runtime check lives in
// ArchTools::archBootstrapFillInceptionRow() itself
// ($this->bootstrapFillAllowed).
if ($profile['bootstrap_fill_allowed'] ?? false) {
    $builder = $builder
        ->addTool([ArchTools::class, 'archBootstrapFillInceptionRow'], 'arch_bootstrap_fill_inception_row');
}

$server = $builder
    ->setCapabilities(new ServerCapabilities(
        tools: true,
        toolsListChanged: false,
        resources: false,
        resourcesSubscribe: false,
        resourcesListChanged: false,
        prompts: false,
        promptsListChanged: false,
        logging: false,
        completions: false,
    ))
    ->build();

$psr17 = new Psr17Factory();
$transport = new StreamableHttpTransport(
    $psr17->createServerRequestFromGlobals(),
    logger: $logger,
);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
