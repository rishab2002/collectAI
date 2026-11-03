# Deploying collectai.club to AWS Lightsail

## What this fixes

`https://collectai.club` returns Cloudflare **error 525, "SSL handshake
failed"**, and `http://collectai.club` returns Porkbun's parking page.

That is not a broken deployment — it is the absence of one. DNS is correctly
delegated to Cloudflare, but the apex and `www` are *proxied* A records still
pointing at Porkbun's parking service, left over from registration. Cloudflare
reaches that origin fine on port 80 and hands back the parking page; on port 443
it cannot complete a TLS handshake with a certificate that doesn't cover this
domain. Hence 200 over HTTP and 525 over HTTPS.

`img.collectai.club` already serves correctly over HTTPS, which proves the
Cloudflare edge certificate is fine. **The failing handshake is Cloudflare →
origin, and the fix is to give it an origin.**

Anyone diagnosing the 525 as a misconfigured web server will go looking for a
server that does not exist.

## What you are building

One Lightsail instance running `docker-compose.prod.yml`:

| Container | Role |
|---|---|
| `web` | nginx + php-fpm under supervisord, ports 80/443 |
| `worker` | `queue:work` — **without this every capture silently skips OCR** |
| `scheduler` | `schedule:work` — daily prices, the nightly recognition index, hourly scan pruning |
| `recognizer` | the local car recognizer (Python, OpenCV, Tesseract); compose network only, no ports. Reads cards and scans with no outside API |
| `db` | Postgres 16, named volume, not published to the host |
| `redis` | queues and cache, append-only |

Two named volumes besides the database's: `appstorage` (scan pictures — private,
so not in R2) and `recognition` (the catalog index, written by the app and read
by the recognizer). After the first deploy, write the index once instead of
waiting for the night: `docker compose -f docker-compose.prod.yml run --rm web php artisan catalog:export-recognition-index`.

Meilisearch is deliberately absent: `laravel/scout` is not installed, so it
would index nothing while consuming a few hundred MB.

## Prerequisites

- An AWS account.
- Access to the Cloudflare dashboard for `collectai.club`.
- The values from your development `.env`: the four `R2_*` keys and
  `COLLECTAI_ANTHROPIC_KEY`.

---

## 1. Create the instance

Lightsail → Create instance.

- **Platform:** Linux/Unix, **Ubuntu 24.04 LTS**
- **Plan:** 2 GB RAM is the floor; **4 GB is worth it.** The image is built on
  the box and `npm run build` is the memory-hungry step — on 1 GB it will be
  OOM-killed. Step 2 adds swap, which makes 2 GB workable.
- **Name:** `collectai`

Then, still in Lightsail:

- **Networking → Attach a static IP.** Do this before touching DNS. A static IP
  is free while attached to a running instance, and without one the address
  changes on every stop/start, silently breaking the DNS records you are about
  to write.
- **Networking → IPv4 Firewall:** allow **22 (SSH)**, **80 (HTTP)** and
  **443 (HTTPS)**. Restrict 22 to your own address if you can.

SSH in from the Lightsail console or your own client.

## 2. Install Docker and swap

```bash
curl -fsSL https://get.docker.com | sudo sh && sudo usermod -aG docker $USER
```

Log out and back in so the group membership takes effect.

Add swap — this is what stops the asset build being OOM-killed on a small
instance, and gives Postgres headroom:

```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile && echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

## 3. Clone and configure

```bash
sudo mkdir -p /opt/collectai && sudo chown $USER:$USER /opt/collectai && git clone https://github.com/rishab2002/collectAI.git /opt/collectai
```

```bash
cd /opt/collectai && cp .env.production.example .env
```

Edit `.env` and fill in every value marked `CHANGE ME`:

- `DB_PASSWORD` — generate one with `openssl rand -base64 32`
- `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_ENDPOINT` — copy from your
  development `.env`
- `COLLECTAI_ANTHROPIC_KEY` — copy from your development `.env`
- `ADMIN_PASSWORD` — set it now. Left blank, `AdminUserSeeder` generates one and
  prints it exactly once, which on a remote box means hunting through container
  logs.

Then generate the application key:

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml run --rm --no-deps web php artisan key:generate --show
```

Copy the printed `base64:...` value into `APP_KEY=` in `.env` by hand.

`--show` prints the key instead of writing it, which is required here: `.env` is
mounted read-only, so the usual `key:generate` would fail trying to rewrite it.
That mount is deliberate — nothing in the app should ever rewrite its own
configuration, and a stray `key:generate` on a running box would invalidate
every session with no trace of why.

> `APP_KEY` encrypts session data. Keep it. Rotating it logs everyone out.

## 4. Get an origin certificate

Cloudflare terminates TLS at the edge, but under **Full (strict)** it also
verifies the certificate your origin presents. Cloudflare issues one for free
that is trusted by its own edge:

Cloudflare dashboard → **SSL/TLS → Origin Server → Create Certificate**.

- Hostnames: `collectai.club, *.collectai.club`
- Validity: 15 years
- Leave the key type as RSA

You are shown the certificate and the private key **once**. On the box:

```bash
mkdir -p /opt/collectai/certs && chmod 700 /opt/collectai/certs
```

Paste the *Origin Certificate* into `/opt/collectai/certs/origin.pem` and the
*Private Key* into `/opt/collectai/certs/origin.key`, then:

```bash
chmod 600 /opt/collectai/certs/origin.key
```

`certs/` is gitignored — never commit either file.

**Check both files before starting the stack.** A bad paste here does not fail
gracefully: nginx refuses to load, supervisord retries ten times and gives up,
and the only thing compose reports is `container is unhealthy` after ~213
seconds — with php-fpm running fine the whole time, which sends you looking at
the wrong process.

```bash
cd /opt/collectai && head -1 certs/origin.pem && head -1 certs/origin.key && openssl x509 -in certs/origin.pem -noout -subject && openssl pkey -in certs/origin.key -noout && echo "BOTH FILES OK"
```

`origin.pem` must begin `-----BEGIN CERTIFICATE-----` and `origin.key` must
begin `-----BEGIN PRIVATE KEY-----` or `-----BEGIN RSA PRIVATE KEY-----`. The
usual causes of `PEM_read_bio_PrivateKey() failed` are the two files being
swapped, or a paste that dropped the trailing `-----END ...-----` line.

Then confirm the key actually belongs to the certificate — two valid files from
different issuances fail later and much more confusingly:

```bash
cd /opt/collectai && diff <(openssl x509 -noout -pubkey -in certs/origin.pem) <(openssl pkey -pubout -in certs/origin.key) && echo "KEY MATCHES CERTIFICATE"
```

> If you skip this, the container still boots: the entrypoint generates a
> self-signed pair and logs four `WARNING` lines saying so. Cloudflare Full
> (strict) then answers **526 Invalid SSL certificate** rather than 525.
> Different error, same blank page.

## 5. Start the stack

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml up -d --build
```

The first build takes several minutes — it compiles PHP extensions and runs
`npm ci` plus the Vite build. Watch it come up:

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml ps && docker compose -f docker-compose.prod.yml logs --tail=50 web
```

You want `web` to reach **healthy**, and the log to show `using the mounted
origin certificate` — not the self-signed warnings.

Then create the admin account:

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml run --rm web php artisan db:seed --class=AdminUserSeeder
```

## 6. Verify against the raw IP — before touching DNS

**Do this before step 7.** It is the difference between debugging a private box
and debugging a public outage.

From your laptop, with `<IP>` as the Lightsail static IP:

```bash
curl -sS --resolve collectai.club:443:<IP> --insecure -o /dev/null -w '%{http_code}\n' https://collectai.club/up
```

`200` means nginx, php-fpm, Postgres and Redis are all working and the app is
serving. `--insecure` is expected here and only here: the Origin CA certificate
is trusted by Cloudflare's edge, not by your laptop.

Do not continue until this returns 200.

## 7. Replace the parking DNS records

Cloudflare dashboard → **DNS → Records**.

There are already **A records for `collectai.club` and `www`** pointing at
Porkbun's parking service. **Edit them — do not add new ones.** Adding leaves
the parking records in place, they round-robin with yours, and the site works
about half the time in a way that looks like an intermittent bug.

| Type | Name | Content | Proxy |
|---|---|---|---|
| A | `collectai.club` | `<Lightsail static IP>` | **Proxied** (orange) |
| A | `www` | `<Lightsail static IP>` | **Proxied** (orange) |

Leave `img` exactly as it is — that is the R2 custom domain and has nothing to
do with this box.

Keep both records **proxied**. Grey-clouding them exposes the origin IP directly
and bypasses the Cloudflare allow-list in `docker/nginx/cloudflare-allow.conf`,
which would then reject the traffic anyway.

## 8. Set SSL to Full (strict)

Cloudflare dashboard → **SSL/TLS → Overview → Full (strict)**.

> **Do not use Flexible.** It makes the 525 disappear immediately, which reads
> as success — but Flexible means Cloudflare talks to your origin over plain
> HTTP, so every login and session cookie crosses the last hop unencrypted.
> Before step 7 it would also have simply served you the parking page over
> HTTPS.

Also enable **SSL/TLS → Edge Certificates → Always Use HTTPS**. The origin
deliberately does *not* redirect HTTP to HTTPS itself — an origin-side redirect
behind a proxy is what turns a Flexible setting into an infinite redirect loop.

## 9. Verify end to end

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://collectai.club/up
```

Then, in order:

1. `https://collectai.club/login` renders **with styling** — unstyled means the
   Vite build did not make it into the image.
2. Log in; `/admin` shows the two queues.
3. Storage round trip:

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml run --rm web php artisan capture:check-storage
```

All four steps — write, read, public fetch, delete — must pass.

4. **Confirm the queue worker actually consumes.** This is the known silent
   failure: with no worker the upload succeeds, the submission stays `queued`,
   `ocr_output` stays null, and nothing errors anywhere. The test suite cannot
   catch it either, because `CaptureTest` calls `Queue::fake()` on purpose.

```bash
cd /opt/collectai && docker compose -f docker-compose.prod.yml logs -f worker
```

Leave that running, capture a real car from your phone at
`https://collectai.club/admin/capture`, and watch `ReadSubmissionPhotos` fire.

5. Install the PWA from the phone — Share → Add to Home Screen. The manifest is
   linked from the app layout, so install works from `/login` and `/admin/*`.
   It does *not* work from `/`, which is still the stock Laravel welcome page
   and carries no manifest link.

> Step 4 is the first time the OCR agent has ever run against the live API.
> Every test to date used a faked PSR-18 transport. If the structured-output
> subset rejects `"type": ["string", "null"]` in
> `app/AI/schemas/ocr_output.json`, this surfaces as a 400 at schema
> compilation and the fix is to switch that construct to `anyOf`.
> `casting_identity.json` uses the same construct and has billed real calls
> successfully, so it is likely fine — but it is unconfirmed on this schema.

---

## Deploying an update

```bash
cd /opt/collectai && git pull && docker compose -f docker-compose.prod.yml up -d --build
```

Migrations run automatically from the `web` entrypoint.

A restart is **required** for any code change: `opcache.validate_timestamps=0`
means PHP never re-reads a source file after first load. That is correct for an
immutable image, but it does mean editing a file inside a running container has
no effect whatsoever.

## Backups

Postgres runs on this box, so nothing else keeps a copy. Install the nightly
dump to R2 — the bucket already exists and R2 charges no egress:

```bash
crontab -e
```

```
17 3 * * * cd /opt/collectai && ./docker/backup/pg-dump-to-r2.sh >> /var/log/collectai-backup.log 2>&1
```

Set a **lifecycle rule on the `backups/` prefix** in the Cloudflare R2 dashboard
for retention. The script deliberately does not delete anything: a delete loop
running as root every night is a worse failure mode than paying for a few extra
objects.

A Lightsail instance snapshot is *not* a substitute — it captures the volume
mid-write, so a restored database has to replay WAL and may not come back.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **525** SSL handshake failed | Origin isn't speaking TLS on 443 | `docker compose -f docker-compose.prod.yml ps`; check 443 is open in the Lightsail firewall |
| **526** Invalid SSL certificate | Self-signed fallback in use | Check `logs web` for the `WARNING` lines; mount a real Origin CA cert (step 4) |
| **521** Web server is down | Container down or firewall closed | `logs web`; check the Lightsail firewall |
| **403** on every request | Cloudflare IP ranges stale, or a grey-clouded record | `./docker/nginx/refresh-cloudflare-ips.sh`, rebuild; confirm records are Proxied |
| Infinite redirect loop | Cloudflare SSL set to Flexible | Set Full (strict) |
| Parking page still appears | A record added rather than replaced | Delete the leftover parking A records |
| Upload fails on a big photo | Size limits out of step | `client_max_body_size`, `post_max_size` and `CAPTURE_MAX_PHOTO_KB` must be raised together |
| Submission stuck `queued`, `ocr_output` null | No worker | `docker compose -f docker-compose.prod.yml ps worker` |
| Job runs twice, two billed vision calls | `REDIS_QUEUE_RETRY_AFTER` ≤ worker `--timeout` | Keep retry_after (360) above the worker timeout (300) |
| Pages render unstyled | Vite build missing from the image | Rebuild; check the `assets` stage — it needs network for the Bunny font fetch |
| `web` never goes healthy; `dependency failed to start` after ~213s | nginx exited at startup and supervisord gave up; php-fpm stays up, so the container looks alive | `logs web \| grep emerg` — the first `[emerg]` line names the cause |
| `[emerg] cannot load certificate key ... PEM_read_bio_PrivateKey() failed` | `origin.key` is not a readable PEM key — usually swapped with `origin.pem`, or a truncated paste | Re-check both files with the `openssl` commands in step 4 |

**Reading a `web` failure.** `docker compose ... ps` reporting `unhealthy` says
only that `/up` did not answer. Because nginx and php-fpm are supervised
together, php-fpm surviving tells you nothing about nginx — go to the log and
find the first `[emerg]`, which is always the real message. nginx validates its
whole configuration before binding, so it reports one fatal error and stops;
fixing it can reveal the next one.
