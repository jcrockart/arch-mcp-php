# arch-mcp-php

Hosted PHP MCP server implementing the ARCH Agent-Connection Layer
(Codegen CLI Design §8 on crockart.atlassian.net, space AW). Wraps
`arch.py` via subprocess exactly as a human would from the CLI — holds
no logic of its own beyond that, and no git credential. Runs at
`https://mcp.crockart.com.au/`, built on the official PHP MCP SDK.

Points at a durable local clone of `arch-collab-core`
(`ArchConfig::REPO_PATH`) — not this repo. This repo is the MCP server
itself; the metadata/CLI it wraps lives in `jcrockart/arch-collab-core`.

8 tools: `arch_session_start`, `arch_codegen_preview`,
`arch_session_commit`, `arch_session_discard`, `arch_session_status`,
`arch_session_write_file`, `arch_session_read_file`,
`arch_session_list_files`.

Deploy: `composer install` for `vendor/`, then point Apache/PHP-FPM at
`public/`. `var/sessions/` must be writable (FileSessionStore).
