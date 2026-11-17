# Deploying Housepot

This takes Housepot from nothing to <https://housepot.owright.dev> on the **same server as GenDoc**, reusing its Caddy and your existing Postgres container. After the first setup, every push to `main` that passes the tests is one **Approve** click from production.

It follows the same pattern as GenDoc's `DEPLOYMENT.md`; where a step is identical, this guide says so rather than repeating the why.

## Contents

1. [How it fits together](#1-how-it-fits-together)
2. [Point the domain at the server](#2-point-the-domain-at-the-server)
3. [Create the database](#3-create-the-database)
4. [Add the site to Caddy](#4-add-the-site-to-caddy)
5. [Set up the Housepot folder](#5-set-up-the-housepot-folder)
6. [Set up Resend (email)](#6-set-up-resend-email)
7. [Let the server pull images](#7-let-the-server-pull-images)
8. [Set up GitHub](#8-set-up-github)
9. [First deploy](#9-first-deploy)
10. [Check it works](#10-check-it-works)
11. [Everyday deploys and rolling back](#11-everyday-deploys-and-rolling-back)
12. [Logs and troubleshooting](#12-logs-and-troubleshooting)

---

## 1. How it fits together

```
                       ┌─▶ web (nginx) ──▶ app (PHP-FPM) ──┐
 Browser ─HTTPS─▶ Caddy┤                                   ├─▶ your Postgres container
                       └─▶ reverb (WebSockets, /app/*)     │
                                                horizon ───┤   (queue workers: broadcasts, mail)
                                              scheduler ───┤   (nightly invite prune, Horizon snapshots)
                                                  redis ◀──┘   (queue, cache)
```

- **Six containers** in a Compose project named `housepot`. `app`, `horizon`, `scheduler` and `reverb` all run the same `housepot-app` image with different commands; `web` is nginx; `redis` is private to the project.
- **Caddy** sends `wss://housepot.owright.dev/app/...` to `reverb` and everything else to `web`. No Housepot container publishes a port (same reason as GenDoc: Docker's port rules bypass `ufw`).
- **Networks:**
  - `internal`: between Housepot's containers, no internet.
  - `egress`: outbound internet for the containers that send email.
  - your Postgres network (`DB_NETWORK`): so the app can reach the database.
  - Caddy's shared network: only `web` and `reverb` join it.
- **Images** are built by GitHub Actions (`.github/workflows/deploy.yml`) after the `tests` workflow passes on `main`, tagged with the commit SHA, and pulled by the server. Each deploy runs `php artisan migrate --force` **before** starting the new containers; if a migration fails, the old version keeps running.
- **The browser's Reverb settings** (`VITE_REVERB_*`) are compiled into the JavaScript at build time, so they come from GitHub variables (§8), not the server's `.env`.

## 2. Point the domain at the server

Add a DNS **A record** for `housepot.owright.dev` pointing at the server's IPv4 address (and **AAAA** for IPv6 if GenDoc has one). Caddy can't get a certificate until this resolves, so do it first:

```bash
dig +short housepot.owright.dev   # should print the server's IP
```

## 3. Create the database

Housepot gets its own role and database on your existing Postgres 17 container, with no superuser rights.

SSH in, then find the Postgres container and the Docker network it's on:

```bash
docker ps --format '{{.Names}}\t{{.Image}}' | grep -i postgres
docker inspect <postgres-container> --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}'
```

Note both: the **network name** becomes `DB_NETWORK`, and the **container name** (or its Compose service name) becomes `DB_HOST` in §5.

Create the role and database. Generate the password first and keep it for §5:

```bash
openssl rand -base64 24 | tr -d '/+=' | cut -c1-32          # the database password

docker exec -it <postgres-container> psql -U postgres
```

```sql
CREATE ROLE housepot LOGIN PASSWORD 'PASTE_PASSWORD_HERE';
CREATE DATABASE housepot OWNER housepot;
\q
```

(If your superuser isn't `postgres`, use that name in `-U`.)

## 4. Add the site to Caddy

Add the block from `docker/caddy/Caddyfile.example` to the server's Caddyfile, next to GenDoc's:

```caddyfile
housepot.owright.dev {
	encode zstd gzip

	# Reverb: browsers connect to wss://housepot.owright.dev/app/{key}; /apps/... is its HTTP API.
	@reverb path /app/* /apps/*
	reverse_proxy @reverb housepot-reverb:8080

	reverse_proxy housepot-web:8080
}
```

Caddy handles the WebSocket upgrade by itself. Reload it:

```bash
cd /srv/caddy
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile
```

Until Housepot is running you'll get `502 Bad Gateway`; that's expected.

Check the name of Caddy's shared network. GenDoc's compose file calls it `web`:

```bash
docker network ls | grep -E ' (web|caddy) '
```

If yours is called something other than `web`, set `CADDY_NETWORK` to it in §5.

## 5. Set up the Housepot folder

```bash
sudo mkdir -p /srv/housepot && sudo chown deploy:deploy /srv/housepot
sudo -iu deploy
cd /srv/housepot
curl -fsSL https://raw.githubusercontent.com/owright89/housepot/main/compose.prod.yaml -o compose.yaml   # only if the repo is public; otherwise scp it
touch .env && chmod 600 .env
```

Generate the secrets:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)"
echo "REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)"
echo "REVERB_APP_KEY=$(openssl rand -hex 16)"
echo "REVERB_APP_SECRET=$(openssl rand -hex 32)"
```

Then write `/srv/housepot/.env` (`nano .env`):

```dotenv
APP_NAME=HousePot
APP_KEY=base64:PASTE
APP_URL=https://housepot.owright.dev

# Database: your existing Postgres container (§3)
DB_NETWORK=PASTE_NETWORK_NAME
DB_HOST=PASTE_CONTAINER_OR_SERVICE_NAME
DB_PORT=5432
DB_DATABASE=housepot
DB_USERNAME=housepot
DB_PASSWORD=PASTE

# Reverb (server side). REVERB_APP_KEY must match the GitHub variable in §8.
REVERB_APP_ID=PASTE
REVERB_APP_KEY=PASTE
REVERB_APP_SECRET=PASTE

# Email via Resend's SMTP (§6)
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=2587
MAIL_USERNAME=resend
MAIL_PASSWORD=PASTE_RESEND_API_KEY
MAIL_FROM_ADDRESS=hello@owright.dev
MAIL_FROM_NAME=HousePot

# Who can open /horizon (comma-separated emails of registered users)
HORIZON_ADMINS=PASTE_YOUR_EMAIL

# Only if Caddy's network isn't called "web" (§4)
# CADDY_NETWORK=caddy

# The commit SHA to run. Deploys update this line automatically.
IMAGE_TAG=REPLACE_ME
```

You **don't** need to set `APP_ENV`, `APP_DEBUG`, `LOG_CHANNEL`, the session, cache, queue, Redis or server-side Reverb host settings: `compose.yaml` sets production values for them. Leave `SESSION_DOMAIN` unset so the cookie stays on `housepot.owright.dev` and isn't shared with GenDoc.

After editing `.env` later, run `docker compose up -d` (recreates the containers). A plain restart keeps the old values.

## 6. Set up Resend (email)

Production needs real email for sign-up verification and password resets.

1. Create an account at <https://resend.com>.
2. **Domains → Add domain** → `owright.dev` (or a subdomain such as `mail.owright.dev`). Add the DNS records Resend shows (SPF, DKIM and optionally DMARC) at your DNS provider, then wait for **Verified**.
3. **API Keys → Create** with **Sending access** only, restricted to that domain. Paste it as `MAIL_PASSWORD`.
4. Make sure `MAIL_FROM_ADDRESS` uses the verified domain.

Housepot uses Resend's SMTP server, so no extra package is needed. It uses port **2587** (STARTTLS) because this server's host blocks outbound 465 and 587. To check which ports are open from the app container:

```bash
for port in 465 587 2587; do
  sudo -u deploy docker compose exec -T app php -r "echo $port, ': ', @fsockopen('smtp.resend.com', $port, \$e, \$s, 5) ? 'open' : 'blocked', PHP_EOL;"
done
```

## 7. Let the server pull images

The images are `ghcr.io/owright89/housepot-app` and `-web`. They contain no secrets (the Reverb key baked into the JavaScript is public by design). Either:

- **Make the packages public** after the first build: GitHub → your profile → **Packages** → each package → **Package settings** → **Change visibility** → Public. Or:
- **Keep them private** and log the `deploy` user in with a classic token that has only `read:packages`:
  ```bash
  echo "YOUR_TOKEN" | docker login ghcr.io -u owright89 --password-stdin
  ```

## 8. Set up GitHub

In the **housepot** repository:

### 8.1 Deploy key

The `deploy` user already exists from GenDoc. Give this repo its own key so you can revoke it separately. On your own computer:

```bash
ssh-keygen -t ed25519 -C "github-actions-housepot" -f housepot_deploy -N ""
```

Append `housepot_deploy.pub` to `/home/deploy/.ssh/authorized_keys` on the server. Then get the host key line for GitHub:

```bash
ssh-keyscan -t ed25519 housepot.owright.dev
```

### 8.2 The production environment

**Settings → Environments → New environment** → `production`:

- **Required reviewers**: add yourself. Every deploy then waits for your approval. (Free on public repositories; private ones need a paid plan. Without it, deploys run automatically after the tests pass.)
- **Deployment branches**: `main` only.
- **Environment secrets:**

  | Secret | Value |
  |---|---|
  | `DEPLOY_SSH_KEY` | Contents of the private key `housepot_deploy` |
  | `DEPLOY_KNOWN_HOSTS` | The `ssh-keyscan` line |

- **Environment variables:**

  | Variable | Value |
  |---|---|
  | `DEPLOY_HOST` | `housepot.owright.dev` (or the server IP) |
  | `DEPLOY_USER` | `deploy` |
  | `DEPLOY_PATH` | `/srv/housepot` (the default) |

### 8.3 Build variables

**Settings → Secrets and variables → Actions → Variables** (repository level, so the build job can read them):

| Variable | Value |
|---|---|
| `REVERB_APP_KEY` | The same `REVERB_APP_KEY` as the server's `.env` |
| `REVERB_PUBLIC_HOST` | Optional; defaults to `housepot.owright.dev` |

Then delete `housepot_deploy` (the private key) from your computer.

## 9. First deploy

1. Push to `main`. The `tests` workflow runs; when it passes, the `deploy` workflow starts.
2. **Actions → deploy**: after the images build, approve **production**.
3. The job uploads `compose.yaml`, sets `IMAGE_TAG`, pulls the images, **runs the migrations**, then starts everything and waits for the healthchecks.
4. If the packages are private and this is the first build, log the server in (§7) before approving.

You can also start it by hand from **Actions → deploy → Run workflow**.

## 10. Check it works

```bash
cd /srv/housepot
docker compose ps        # app, web, horizon, reverb, redis "healthy"; scheduler "running"
curl -I https://housepot.owright.dev
```

Then in a browser:

1. **Register.** The verification email should arrive (check Resend's **Logs** if not).
2. **Create a household, add a bill.**
3. **Real-time:** open the household as a second user in a private window, add a bill as one, and watch the other update. The **Live** chip should be green. If it says *Reconnecting…*, see §12.
4. Open `https://housepot.owright.dev/horizon` while logged in as a `HORIZON_ADMINS` email.

## 11. Everyday deploys and rolling back

**Deploying:** merge to `main`, wait for tests and build, approve. There's a few seconds' gap while containers restart. Open pages reconnect to Reverb and refetch on their own.

**Rolling back:** re-run an older successful **deploy** run from the Actions tab (within 30 days), or on the server:

```bash
cd /srv/housepot
sed -i 's/^IMAGE_TAG=.*/IMAGE_TAG=<full-commit-sha>/' .env
docker compose pull && docker compose up -d --wait
```

> **Migrations don't roll back automatically.** Rolling back code is safe when the newer migrations only *added* things (the usual case). If a release changed or dropped columns, restore from a database backup instead.

**Backups:** Housepot's data is the `housepot` database in your Postgres container. Include it in that server's backups, e.g. a nightly `docker exec <postgres-container> pg_dump -U postgres -Fc housepot > housepot-$(date +%F).dump`.

## 12. Logs and troubleshooting

```bash
cd /srv/housepot
docker compose logs -f app        # Laravel and PHP errors
docker compose logs -f horizon    # queued jobs (broadcasts, mail)
docker compose logs -f reverb     # WebSocket server
docker compose exec app php artisan about
```

| Symptom | Likely cause |
|---|---|
| `502 Bad Gateway` | Housepot isn't healthy (`docker compose ps`), or `web`/`reverb` aren't on Caddy's network (`CADDY_NETWORK`). |
| `network ... not found` on deploy | `DB_NETWORK` or `CADDY_NETWORK` in `.env` doesn't match `docker network ls`. |
| Migration step fails with "could not translate host name" | `DB_HOST` isn't the Postgres container's name or alias on `DB_NETWORK`. |
| "Live" chip stuck on *Reconnecting…* | The GitHub `REVERB_APP_KEY` variable doesn't match `.env` (rebuild after fixing), or the Caddy block is missing the `@reverb` route. The browser console shows the failing `wss://` URL. |
| Pages don't update live but the chip is green | `horizon` isn't running: broadcasts are queued. `docker compose logs horizon`. |
| No verification email | `MAIL_*` settings or the Resend domain isn't verified yet; see Resend's Logs and `docker compose logs app`. |
| Registration hangs ~30s, then "Connection timed out" | The host blocks that SMTP port. Use `MAIL_PORT=2587` with `MAIL_SCHEME=smtp` (§6). |
| `/horizon` gives 403 | Your email isn't in `HORIZON_ADMINS` (then `docker compose up -d`). |
| `.env` changes ignored | Use `docker compose up -d`, not `restart`. |
