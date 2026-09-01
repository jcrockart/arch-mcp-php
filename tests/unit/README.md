# tests/unit/ — offline

No network, no secrets, no server. Builds throwaway directory trees under
`/tmp` and exercises the scoping logic directly. Safe to run anywhere, by
anyone, at any time.

    php test-scoping.php

28 assertions covering token extraction, path containment, the
sibling-directory boundary, dot-component denial, lane allowlisting and
the write-extension allowlist. Add a case here before fixing any scoping
bug — every defect found so far was reproducible offline.
