#!/usr/bin/env python3
"""
Step 5b — prove the lanes actually reach the right filesystem, live.

verify-scoping.py proved the MENU is right (3 tools vs 11). It did not
prove the tools touch the correct directory: tools/list says nothing
about where arch_site_write_file would write. This calls the tools for
real and checks the root.

Run ON THE SERVER. Reads tokens from ~/arch-mcp-secrets/tokens.json;
never prints them.

    python3 verify-lanes.py

Writes exactly one harmless file (_scope-test.txt) into each profile's
root to prove writes land in the right place. Delete it afterwards; the
script tells you the path. All traversal probes are READS, so a broken
boundary is detected without writing anything outside the root.
"""

import json
import os
import ssl
import sys
import urllib.error
import urllib.request

BASE = "https://mcp.crockart.com.au"
MAP = os.path.expanduser("~/arch-mcp-secrets/tokens.json")
PROTOCOL_VERSIONS = ["2025-06-18", "2025-03-26", "2024-11-05"]
CTX = ssl.create_default_context()

# Probes are built per-lane. A lane rooted AT the tree (subdir "") and a
# lane one level down (subdir "site") need different ../ depths to reach
# anything interesting — a fixed list silently tests nothing for one of
# them. The first version of this script used an rcp-shaped list, so for
# james-arch-collab every probe resolved to a path that never existed and
# passed for the wrong reason: ../arch.py, the exact file DEPLOY.md
# claims is unreachable, was never actually tried.
GENERIC_ESCAPES = [
    "../../../../etc/passwd",
    "/etc/passwd",
    "../../../../../home/crockart/arch-mcp-secrets/tokens.json",
]


def escapes_for(subdir):
    """Paths that would escape THIS lane, given how deep it sits."""
    up = "../" * (len(subdir.strip("/").split("/")) if subdir.strip("/") else 0)
    probes = list(GENERIC_ESCAPES)
    if up:
        # Lane is below the root: one level up is the repo itself.
        probes += [up + "arch.py",
                   up + "validate.py",
                   up + "metadata/todo.json",
                   up + ".git/config"]
    else:
        # Lane IS the root: the interesting targets are inside it.
        probes += [".git/config",
                   ".git/hooks/post-merge",
                   ".htaccess",
                   "../rcp-backup/index.html"]
    return probes


def post(url, payload, sid=None):
    req = urllib.request.Request(url, data=json.dumps(payload).encode("utf-8"), method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("Accept", "application/json, text/event-stream")
    if sid:
        req.add_header("Mcp-Session-Id", sid)
    try:
        with urllib.request.urlopen(req, context=CTX, timeout=25) as r:
            return r.getcode(), dict(r.headers), decode(r.read(), r.headers)
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), decode(e.read(), e.headers)
    except Exception as e:                      # noqa: BLE001
        return None, {}, "TRANSPORT ERROR: {}".format(e)


def decode(raw, headers):
    text = raw.decode("utf-8", "replace").strip()
    if not text:
        return None
    ctype = (headers.get("Content-Type") or "").lower()
    if "text/event-stream" in ctype or text.startswith(("event:", "data:")):
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


def connect(url):
    for version in PROTOCOL_VERSIONS:
        status, headers, data = post(url, {
            "jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {"protocolVersion": version, "capabilities": {},
                       "clientInfo": {"name": "arch-lane-test", "version": "1.0"}},
        })
        if status == 200 and isinstance(data, dict) and "error" not in data:
            sid = headers.get("Mcp-Session-Id") or headers.get("mcp-session-id")
            post(url, {"jsonrpc": "2.0", "method": "notifications/initialized"}, sid)
            return sid
    return None


_id = [10]


def call(url, sid, tool, args):
    _id[0] += 1
    status, _, data = post(url, {
        "jsonrpc": "2.0", "id": _id[0], "method": "tools/call",
        "params": {"name": tool, "arguments": args},
    }, sid)
    if not isinstance(data, dict):
        return {"transport": "HTTP {} {}".format(status, data)}
    if "error" in data:
        return {"rpc_error": data["error"]}
    return {"result": data.get("result")}


def flatten(result):
    """Squash an MCP tools/call result into one searchable lowercase string."""
    return json.dumps(result, default=str).lower()


def main():
    try:
        with open(MAP) as fh:
            token_map = json.load(fh)
    except Exception as e:                      # noqa: BLE001
        print("Cannot read {}: {}".format(MAP, e))
        return 1

    profiles = [(t, p) for t, p in token_map.items() if isinstance(p, dict)]
    failures = 0

    print("Profiles in the token map: {}".format(
        ", ".join(p.get("label", "?") for _, p in profiles) or "(none)"))
    print("This script cannot know which profiles you MEANT to create.")
    print("If one you expected is missing from that list, it is missing from")
    print("the map — not merely untested.\n")

    for token, profile in profiles:
        label = profile.get("label", "?")
        root = profile.get("root", "?")
        lanes = profile.get("lanes", {})
        url = "{}/t/{}/".format(BASE, token)

        print("=" * 62)
        print("{}   root: {}".format(label, root))
        print("=" * 62)

        sid = connect(url)
        if not sid:
            failures += 1
            print("  [FAIL] could not establish a session\n")
            continue

        if "site" not in lanes:
            print("  (no site lane; skipping)\n")
            continue

        # 1. Listing must show THIS root's files.
        r = call(url, sid, "arch_site_list_files", {})
        print("  arch_site_list_files ->")
        print("    {}".format(json.dumps(r, default=str)[:600]))

        # 2. Write inside the lane must succeed and land in the right place.
        #    NOTE: the probe lands under root/<lane subdir>, not root — the
        #    earlier version of this script reported the wrong rm path for
        #    any lane with a subdirectory, and for arch-collab that left a
        #    live file at crockart.com.au/arch-collab/_scope-test.txt.
        site_subdir = lanes.get("site", "")
        lane_dir = os.path.join(root.rstrip("/"), site_subdir) if site_subdir else root.rstrip("/")
        probe = "_scope-test.txt"
        body = "scope probe for {} - safe to delete".format(label)
        w = call(url, sid, "arch_site_write_file", {"path": probe, "content": body})
        wrote = "true" in flatten(w) and "rpc_error" not in w
        failures += 0 if wrote else 1
        print("  [{}] write {} inside root".format("PASS" if wrote else "FAIL", probe))

        rb = call(url, sid, "arch_site_read_file", {"path": probe})
        echoed = "safe to delete" in flatten(rb)
        failures += 0 if echoed else 1
        print("  [{}] read it back".format("PASS" if echoed else "FAIL"))
        print("       -> delete afterwards:  rm {}/{}".format(lane_dir, probe))

        # 3. Traversal probes, all reads — nothing is written outside the root.
        for path in escapes_for(site_subdir):
            rr = call(url, sid, "arch_site_read_file", {"path": path})
            blob = flatten(rr)
            leaked = ("root:x:" in blob or "[core]" in blob
                      or "<!doctype" in blob or "<html" in blob
                      or "import " in blob or "def " in blob
                      or "label" in blob and "lanes" in blob)
            ok = not leaked
            failures += 0 if ok else 1
            print("  [{}] refused read: {}".format("PASS" if ok else "FAIL *** ESCAPED ***", path))

        # 4. A lane this token was never granted.
        if "metadata" not in lanes:
            mr = call(url, sid, "arch_session_read_file", {"path": "todo.json"})
            blob = flatten(mr)
            # Either the tool isn't registered, or it is and the lane check bites.
            ok = ("rpc_error" in mr) or ("false" in blob) or ("error" in blob)
            failures += 0 if ok else 1
            print("  [{}] arch_session_read_file rejected (tool not granted)".format(
                "PASS" if ok else "FAIL"))
        print()

    print("=" * 62)
    print("FAILURES: {}".format(failures))
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
