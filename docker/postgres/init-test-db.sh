#!/bin/bash
# Creates the database the PHPUnit suite runs against.
#
# The test suite can't use SQLite: the schema depends on Postgres-only features
# (the pg_trgm extension, GIN trigram indexes, the COALESCE expression unique
# index on variations, jsonb, and partial indexes), so tests have to run on the
# same engine as production or they'd be testing a different schema.
#
# Postgres runs everything in docker-entrypoint-initdb.d exactly once, when the
# data volume is first created. An existing environment needs the database made
# by hand:
#   docker compose exec db psql -U diecast -d postgres -c 'CREATE DATABASE diecast_testing OWNER diecast;'
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<-EOSQL
    CREATE DATABASE ${POSTGRES_DB}_testing OWNER $POSTGRES_USER;
EOSQL
