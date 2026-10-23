# arch-mcp-php

Hosted PHP MCP server implementing the ARCH Agent-Connection Layer
(Codegen CLI Design §8 on crockart.atlassian.net, space AW), built on the
official PHP MCP SDK. Runs at `https://mcp.crockart.com.au/`.

Every call arrives with the caller's OAuth identity (Portal is the
authorization server). The server checks the caller's membership and role on
the project, then runs the tool against that project's checkout.

## Sessions and the commit gate

The session logic lives in this repo, in one copy, and runs against whichever
project the chat works in. Projects no longer carry their own `arch.py` or
`validate.py`.

- `arch_session_start`, `arch_session_commit`, `arch_session_discard`,
  `arch_session_recover` (owners and admins), `arch_session_status`.
- `arch_session_validate` checks a project's `metadata/` the way a commit's
  validate step would, without committing.
- A lane's gate steps come from its entry in the project's `arch-gate.json`.
  A step is `{"name": "...", "cmd": [...]}` (run from the project root) or
  `{"name": "validate", "builtin": "validate"}` (the built-in metadata
  validator). The older `["python3", "validate.py"]` step is read as the
  built-in validator. The metadata lane needs no entry.
- A commit is versioned when the lane's `"versioned"` key says so; with no key,
  when the lane is `metadata` or its stage paths include `metadata/`.

Code: `GateConfig` and `GateRunner` (gate), `MetadataValidator` and the
`Validate*` files (validator), `SessionStart`, `SessionCommit`,
`SessionDiscarder`, `SessionRecover`, `SessionPurge`, `SessionVersion`,
`SessionFile` (sessions).

## Other tools

Code files (`arch_code_*`), session metadata files (`arch_session_*_file`),
assets (`arch_assets_*`), core git (`arch_core_git_*`), production state
(`arch_prod_state`), dependency install, database reads and migrations
(`db_*`), and bootstrap install (`install_bootstrap`).

## Tests

`tests/unit/test-*.php` are the commit gate's test steps (see
`arch-gate.json`); run any one with `php tests/unit/<name>.php`.
`tests/acceptance/verify-lanes.py` checks the lanes against a live server.

Deploy: `composer install` for `vendor/`, then point Apache/PHP-FPM at
`public/`. `var/sessions/` must be writable (FileSessionStore).
