# Security Policy

## Secrets

- Never hardcode real secrets: passwords, API keys, tokens, cookies, private keys, OAuth credentials, HMAC secrets, DB credentials, SSH keys.
- Do not search for, extract, decrypt, or use real secrets from the database, env files, logs, admin panels, CI, servers, or local files without a separate explicit user request.
- If a check needs a credential, use the existing runtime/config path. Tests must use generated synthetic placeholders that do not resemble real provider tokens.
- Do not print secrets in chat, diffs, fixtures, snapshots, logs, exceptions, test names, comments, docs, or CI artifacts.
- If a secret is found accidentally, stop, do not repeat the value, report only the path/type, and recommend provider rotation plus Git history purge.
- Before commit/push for integration or credential-related changes, run a redacted secret scan.

Local fallback when Gitleaks is not installed:

```powershell
rtk powershell -NoProfile -ExecutionPolicy Bypass -File tools/secret-scan.ps1
```

CI runs Gitleaks and Semgrep secret checks. Removing a secret from the current tree does not remove it from Git history; history rewrite and force-push require a separate explicit approval.
