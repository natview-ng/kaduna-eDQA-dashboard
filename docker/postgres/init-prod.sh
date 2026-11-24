#!/bin/sh
# First boot of a server database (compose.prod.yml): the roles and privileges of init-dev.sql,
# with real passwords from the environment. Postgres runs it once, when the data volume is
# empty. Changing a password later is ALTER ROLE ... PASSWORD, not this script.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v migrator_password="$EDQA_MIGRATOR_PASSWORD" -v app_password="$EDQA_APP_PASSWORD" <<'SQL'
CREATE ROLE edqa_migrator LOGIN PASSWORD :'migrator_password';
CREATE ROLE edqa_app      LOGIN PASSWORD :'app_password';

CREATE DATABASE edqa OWNER edqa_migrator ENCODING 'UTF8' TEMPLATE template0;

\connect edqa
CREATE EXTENSION IF NOT EXISTS pg_trgm;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO edqa_app;
GRANT ALL   ON SCHEMA public TO edqa_migrator;
ALTER DEFAULT PRIVILEGES FOR ROLE edqa_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO edqa_app;
ALTER DEFAULT PRIVILEGES FOR ROLE edqa_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO edqa_app;
SQL
