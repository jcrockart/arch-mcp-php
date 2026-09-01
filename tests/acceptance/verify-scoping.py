#!/usr/bin/env python3
"""
Step 5 verification for the token-scoping deploy.

Run ON THE SERVER (cPanel Terminal). Reads the tokens from
~/arch-mcp-secrets/tokens.json itself, so no secret is ever typed,
pasted, echoed or logged. Prints only labels, HTTP codes and tool names.

    python3 verify-scoping.py

Python 3.6-safe (this host's only python3 is 3.6.8). Stdlib only.
"""

import json
import os
import ssl
import sys
import urllib.error
import urllib.request

BASE = "https://mcp.crockart.com.au"
MAP = os.path.expanduser("~/arch-mcp-secrets/tokens.json")

# Tried in order — the SDK will reject versions it doesn't speak.
PROTOCOL_VERSIONS = ["2025-06-18", "2025-03-26", "2024-11-05"]

CTX = ssl.create_default_context()


def post(url, payload, session_id=None, timeout=20):
    """POST one JSON-RPC message. Returns (status, headers, parsed_or_raw)."""
    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=body, method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("Accept", "application/json, text/event-stream")
    if session_id:
        req.add_header("Mcp-Session-Id", session_id)
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=timeout) as r:
            return r.getcode(), dict(r.headers), decode(r.read(), r.headers)
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), decode(e.read(), e.headers)
    except Exception as e:                      # noqa: BLE001
        return None, {}, "TRANSPORT ERROR: {}".format(e)


def decode(raw, headers):
    """Handle both plain JSON and SSE (text/event-stream) responses."""
    text = raw.decode("utf-8", "replace").strip()
    if not text:
        return None
    ctype = (headers.get("Content-Type") or "").lower()
    if "text/event-stream" in ctype or text.startswith("event:") or text.startswith("data:"):
        for line in text.splitlines():
            if line.startswith("data:"):
                chunk = line[5:].strip()
                if chunk:
                    try:
                        return json.loads(chunk)
                    except ValueError:
                        return chunk
        return text
    try:
        return json.loads(text)
    except ValueError:
        return text


def handshake(url):
    """initialize -> notifications/initialized -> tools/list."""
    for version in PROTOCOL_VERSIONS:
        status, headers, data = post(url, {
            "jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {
                "protocolVersion": version,
                "capabilities": {},
                "clientInfo": {"name": "arch-scoping-test", "version": "1.0"},
            },
        })
        if status != 200 or not isinstance(data, dict) or "error" in data:
            continue

        sid = headers.get("Mcp-Session-Id") or headers.get("mcp-session-id")
        server = (data.get("result") or {}).get("serverInfo") or {}

        post(url, {"jsonrpc": "2.0", "method": "notifications/initialized"}, sid)

        status, _, data = post(url, {"jsonrpc": "2.0", "id": 2, "method": "tools/list"}, sid)
        if status != 200 or not isinstance(data, dict):
            return {"ok": False, "why": "tools/list -> HTTP {} {}".format(status, data)}
        if "error" in data:
            return {"ok": False, "why": "tools/list -> {}".format(data["error"])}

        tools = (data.get("result") or {}).get("tools") or []
        return {
            "ok": True,
            "protocol": version,
            "server": server.get("name", "?"),
            "session": "yes" if sid else "NO SESSION ID",
            "tools": sorted(t.get("name", "?") for t in tools),
        }

    return {"ok": False, "why": "initialize failed for all protocol versions; "
                               "last response: HTTP {} {}".format(status, data)}


def main():
    try:
        with open(MAP) as fh:
            token_map = json.load(fh)
    except Exception as e:                      # noqa: BLE001
        print("Cannot read {}: {}".format(MAP, e))
        return 1

    profiles = [(t, p) for t, p in token_map.items() if isinstance(p, dict)]
    if not profiles:
        print("No usable profiles in the token map.")
        return 1

    failures = 0

    print("=" * 62)
    print("NEGATIVE TESTS — these must all be refused")
    print("=" * 62)
    for label, path in [
        ("bare /",            "/"),
        ("bogus token",       "/t/" + ("z" * 64) + "/"),
        ("short token",       "/t/abc/"),
        ("traversal attempt", "/t/../"),
    ]:
        status, _, _ = post(BASE + path, {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}})
        good = status in (403, 404)
        failures += 0 if good else 1
        print("  [{}] {:<18} HTTP {}".format("PASS" if good else "FAIL", label, status))

    print()
    print("=" * 62)
    print("PROFILES")
    print("=" * 62)
    for token, profile in profiles:
        label = profile.get("label", "?")
        expected = 11 if profile.get("session_tools") else 3
        print("\n  {}  (expecting {} tools)".format(label, expected))
        print("  root: {}".format(profile.get("root", "?")))

        result = handshake("{}/t/{}/".format(BASE, token))
        if not result["ok"]:
            failures += 1
            print("  [FAIL] {}".format(result["why"]))
            continue

        count = len(result["tools"])
        ok = count == expected
        failures += 0 if ok else 1
        print("  protocol {} | serverInfo '{}' | session id: {}".format(
            result["protocol"], result["server"], result["session"]))
        print("  [{}] {} tools returned".format("PASS" if ok else "FAIL", count))
        for name in result["tools"]:
            print("        - {}".format(name))

        # The lane that must never appear for a non-session profile.
        if not profile.get("session_tools"):
            leaked = [t for t in result["tools"] if t.startswith("arch_session_")]
            if leaked:
                failures += 1
                print("  [FAIL] session tools exposed to a non-session profile: {}".format(leaked))
            else:
                print("  [PASS] no arch_session_* tools exposed")

    print("\n" + "=" * 62)
    print("FAILURES: {}".format(failures))
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
