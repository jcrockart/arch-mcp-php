# tests/acceptance/ — checks against the running server

These authenticate with real tokens from `~/arch-mcp-secrets/tokens.json`
and talk to `https://mcp.crockart.com.au`. Run them after any change to
`index.php`, `ArchTools.php`, `ArchProfiles.php` or `.htaccess`.

| Script | Side effects |
|---|---|
| `verify-scoping.py` | none. Negative tests plus per-profile tool counts. |
| `verify-lanes.py` | **writes `_scope-test.txt` into every profile's site lane, every run.** |

## The probe files

`verify-lanes.py` proves writes land in the right directory by actually
writing one. For `james-arch-collab` that lands in `arch-collab-core/site/`,
which is symlinked into `public_html/arch-collab` — so the probe is
**publicly served** at `crockart.com.au/arch-collab/_scope-test.txt` until
you remove it. This has already happened twice.

The script prints the correct `rm` line when it finishes. Run it:

    rm /home/crockart/arch-collab-core/site/_scope-test.txt
    rm /home/crockart/public_html/rcp/_scope-test.txt

## What these cannot tell you

They exercise the PHP request path. They say nothing about static files
served directly by Apache — a backup or stray file in `public/` is fetchable
without PHP ever running, and every one of these checks would still pass.
That gap is real and was found the hard way (round 3). Check it separately:

    curl -s -o /dev/null -w '%{http_code}\n' https://mcp.crockart.com.au/index.php
    curl -s -o /dev/null -w '%{http_code}\n' https://mcp.crockart.com.au/composer.json

Both should be 404.
