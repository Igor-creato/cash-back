# FingerprintJS OSS — locally vendored bundle

Supply-chain hardening for Group 11a of the security refactor plan
(`obsidian/knowledge/decisions/security-refactor-plan-2026-04-21.md`).
Closes findings **F-34-007** (external CDN) and **F-34-008** (`new Function` dynamic import).

## Pinned version

| Field   | Value                                               |
| ------- | --------------------------------------------------- |
| Package | `@fingerprintjs/fingerprintjs`                      |
| Version | **3.4.2**                                           |
| License | MIT                                                 |
| Source  | npm registry                                        |
| Format  | UMD (minified) — attaches `window.FingerprintJS`    |
| Upstream file | `dist/fp.umd.min.js` (renamed to `fp.min.js`) |

## Integrity (SHA-256)

```
7ea8ebef5077e8029cac03bc0e702c9239370bc129b337f977f2d2f770ea17a9  fp.min.js
```

Verify locally:

```bash
sha256sum assets/vendor/fingerprintjs/fp.min.js
```

If the digest diverges from this value, the bundle has been modified — do not deploy.

## Why v3.x, not v4.x

v4.x is licensed under **BSL 1.1** (Business Source License), which restricts
commercial use. v3.x series (last: 3.4.2) remains **MIT**. The public API we use
(`FingerprintJS.load()` → `agent.get()` → `{ visitorId, confidence }`) is
identical between v3 and v4 for our anti-fraud use case.

## Upgrade procedure

1. `npm install @fingerprintjs/fingerprintjs@<new-version> --no-save` in a scratch
   directory (outside this repo).
2. Confirm `LICENSE` is still MIT (not BSL). BSL = stop.
3. Copy `dist/fp.umd.min.js` → `assets/vendor/fingerprintjs/fp.min.js`
   (UMD build, so `<script>` tag sets `window.FingerprintJS`).
4. Copy `LICENSE` → `assets/vendor/fingerprintjs/LICENSE`.
5. Recompute SHA-256 and update this README.
6. Bump `'4.5.1'`-style version constants in
   `antifraud/class-fraud-collector.php::enqueue_fingerprint_script()`.
7. Run `FingerprintJsLocalBundleTest` — must be GREEN.

## Not modified

Upstream files are copied verbatim. Any local patch must be documented here
and re-verified by upstream diff.
