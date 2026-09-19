# Production deployment

Every push to `main` triggers [.github/workflows/deploy.yml](../.github/workflows/deploy.yml):

1. Builds a Docker image from [deploy/Dockerfile](Dockerfile) — the official
   `dolibarr/dolibarr:23.0.3-php8.2` image overlaid with this repo's patched
   `htdocs/` (core patches + `htdocs/custom/mystore`).
2. Pushes it to `ghcr.io/ademir93/dolibarr-store` tagged `latest` + commit SHA.
3. Over SSH to the server: copies [docker-compose.yml](docker-compose.yml) to
   `/opt/dolibarr/`, pulls the SHA-pinned image, `docker compose up -d
   --remove-orphans --renew-anon-volumes`, prunes old images, smoke-tests
   `http://127.0.0.1:8080`.

The app is served on port **8080**; MySQL data (`dolibarr_mysql`) and the
documents dir (`dolibarr_documents`, holds `install.lock`) persist across
deploys.

## Required GitHub secrets

Set in *Settings → Secrets and variables → Actions*:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | server hostname |
| `DEPLOY_USER` | SSH user (currently `root`; use a dedicated deploy user when possible) |
| `DEPLOY_SSH_KEY` | private key whose public half is in the server's `authorized_keys` |

Registry auth needs no extra secret — the workflow's own `GITHUB_TOKEN` logs
the server into ghcr.io for each pull.

## Server prerequisites (one-time)

- Docker Engine + compose v2.
- `/opt/dolibarr/.env` (chmod 600) with the real secrets — the deploy fails
  fast if it is missing:

  ```
  MYSQL_ROOT_PASSWORD=...
  MYSQL_PASSWORD=...
  DOLI_DB_PASSWORD=...        # must equal MYSQL_PASSWORD
  DOLI_ADMIN_PASSWORD=...     # Dolibarr superadmin (first install only)
  DOLI_CRON_KEY=...
  DOLI_INSTANCE_UNIQUE_ID=... # encryption salt — set once, never change
  DOLI_COMPANY_NAME=...
  DOLI_URL_ROOT=https://erp.csbjeans.com   # the URL browsers use, not the internal :8080 port
  ```

  `DOLI_URL_ROOT` becomes `$dolibarr_main_url_root`, which Dolibarr uses to build
  every absolute link. If it names the internal `host:8080` address, users
  reached through the reverse proxy get sent to that address by those links.

## Things to know

- **Modules are managed via git only.** `/var/www/html/custom` is deliberately
  not a named volume; `--renew-anon-volumes` replaces it with the image's
  content each deploy, so modules installed through the web UI do not survive.
- **Core patches that change the DB schema do not auto-migrate** (the image
  only runs migrations when the Dolibarr version changes). Logic-only patches
  are fine; apply schema changes manually or via an install hook script.
- `conf.php` is generated inside the container from the `DOLI_*` env vars on
  each fresh container; it is never stored in git or the image.
