# bin/ — operational scripts for this host

These run against the **live** server and the **live** token map at
`~/arch-mcp-secrets/tokens.json`. They are not tests. Read the side-effect
column before running anything.

| Script | Reads secrets | Side effects |
|---|---|---|
| `check-tokens.py` | yes | none — reports structure, never prints a token |
| `mint-tokens.py` | yes | **rewrites the token map.** `--force` keeps a `.bak`. Reminting invalidates every registered connector URL. |

`mint-tokens.py --url <label>` prints one connector URL. That is the only
command in this repo that puts a token on screen; everything else reports
labels.

Offline unit tests live in `tests/unit/` and are safe to run anywhere.
Live acceptance checks live in `tests/acceptance/` — see that README, one
of them writes files into served directories.
