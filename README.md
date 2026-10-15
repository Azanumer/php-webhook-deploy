# PHP Webhook Deploy

Dependency-free GitHub webhook receiver that auto-deploys your site on `git push`.
No framework, no queue — one PHP file behind your web server.

## How it works

1. GitHub POSTs a `push` event to `public/webhook.php`.
2. The receiver verifies the `X-Hub-Signature-256` HMAC with your shared secret (`hash_equals`).
3. If the repo (and branch) matches `config.php`, each configured command runs with a hard timeout.
4. Every deploy is appended as a JSON line to `storage/deployments.log`; `GET ?status=1` shows the latest.

## Setup

```bash
cp config.example.php config.php
openssl rand -hex 32          # paste into config.php 'secret'
mkdir -p storage && chown www-data:www-data storage
```

Point your web server at `public/` (or copy `public/webhook.php` into an existing
docroot). On GitHub: repo → Settings → Webhooks → Add webhook, Payload URL
`https://yourdomain.com/webhook.php`, Content-Type `application/json`, Secret = yours.

## Files

| File | What it is |
|---|---|
| `public/webhook.php` | The receiver: signature verify, repo/branch match, runner, status endpoint |
| `config.example.php` | Repo → commands mapping, secret, log dir (copy to `config.php`) |
| `scripts/deploy-example.sh` | Example deploy script: `git reset --hard`, composer install, cache flush, OPcache reset |

## Security notes

- Commands come from your own `config.php` — the webhook payload is never interpolated into shell.
- Each command has a timeout (default 120s); a hung deploy is killed with SIGKILL.
- Keep `config.php` and `storage/` outside the docroot; add `config.php` to `.gitignore`.
- Run the web-server user with the least privilege it needs (git read, site-dir write).

Requires PHP 7.4+. MIT licensed.
