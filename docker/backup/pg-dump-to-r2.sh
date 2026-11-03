#!/bin/sh
# Nightly Postgres backup to a PRIVATE R2 bucket (R2_BACKUP_BUCKET).
#
# **Never the photo bucket.** collectai-photos is world-readable through
# img.collectai.club, and a dump holds every account's email and password hash.
# Until 2026-11-03 this script wrote there, under a name made of the run's
# timestamp — guessable, since the run is at 03:17. It now refuses unless the
# backup bucket is set and is a different bucket. Make that bucket in the R2
# dashboard and give it no custom domain and no public r2.dev access: R2 buckets
# are private until you do either.
#
# Postgres runs on the same Lightsail box as the app, which is the one real cost
# of that shape: nothing else is keeping a copy. An instance snapshot is not a
# substitute — it captures the volume mid-write, so restoring one gives you a
# database that has to replay WAL and may not come back at all.
#
# Costs next to nothing: R2's free tier covers 10 GB and R2 charges no egress.
# The same R2 API token works if it is allowed both buckets.
#
# Install on the box (from the repo root):
#
#   crontab -e
#   17 3 * * * cd /opt/collectai && ./docker/backup/pg-dump-to-r2.sh >> /var/log/collectai-backup.log 2>&1
#
# Retention is deliberately NOT handled here. Set a lifecycle rule on the
# backups/ prefix in the Cloudflare dashboard instead: it runs server-side,
# needs no credentials on the box, and a bug in a delete loop that runs as root
# every night is a worse failure than paying for a few extra objects.

set -eu

cd "$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"

compose="docker compose -f docker-compose.prod.yml"

if [ ! -f .env ]; then
    echo "no .env in $(pwd) — cannot read R2 credentials" >&2
    exit 1
fi

# Read specific keys rather than sourcing .env. Sourcing would execute the file,
# and it contains "${APP_NAME}" interpolations plus values that a shell would
# happily reinterpret.
env_value() {
    grep -E "^$1=" .env | head -n 1 | cut -d= -f2- | sed 's/^"//; s/"$//'
}

R2_ACCESS_KEY_ID="$(env_value R2_ACCESS_KEY_ID)"
R2_SECRET_ACCESS_KEY="$(env_value R2_SECRET_ACCESS_KEY)"
R2_BUCKET="$(env_value R2_BUCKET)"
R2_ENDPOINT="$(env_value R2_ENDPOINT)"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"

R2_BACKUP_BUCKET="$(env_value R2_BACKUP_BUCKET)"

for required in R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_BACKUP_BUCKET R2_ENDPOINT; do
    eval "value=\$$required"
    if [ -z "$value" ]; then
        echo "$required is empty in .env — backups need their own private bucket (docs/deploy.md, Backups)" >&2
        exit 1
    fi
done

if [ "$R2_BACKUP_BUCKET" = "$R2_BUCKET" ]; then
    echo "R2_BACKUP_BUCKET is the photo bucket, which is public — refusing to upload a database dump there" >&2
    exit 1
fi

stamp="$(date -u +%Y-%m-%dT%H-%M-%SZ)"
archive="/tmp/diecast-${stamp}.sql.gz"

trap 'rm -f "$archive"' EXIT INT TERM

echo "[$(date -u +%FT%TZ)] dumping ${DB_DATABASE}"
$compose exec -T db pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" | gzip -9 > "$archive"

# A dump that failed partway still leaves a valid gzip stream, so size is the
# cheapest honest check. An empty or trivially small archive means the dump
# broke, and uploading it would quietly replace a good backup with a useless
# one — the failure mode where you only find out at restore time.
size="$(wc -c < "$archive" | tr -d ' ')"
if [ "$size" -lt 10000 ]; then
    echo "dump is only ${size} bytes — refusing to upload" >&2
    exit 1
fi

echo "[$(date -u +%FT%TZ)] uploading ${size} bytes to s3://${R2_BACKUP_BUCKET}/backups/"
docker run --rm -i \
    -e AWS_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID" \
    -e AWS_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY" \
    -e AWS_DEFAULT_REGION=auto \
    amazon/aws-cli:2.27.0 \
    s3 cp - "s3://${R2_BACKUP_BUCKET}/backups/diecast-${stamp}.sql.gz" \
    --endpoint-url "$R2_ENDPOINT" \
    < "$archive"

echo "[$(date -u +%FT%TZ)] done"
