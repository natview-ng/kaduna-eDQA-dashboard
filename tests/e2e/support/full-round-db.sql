-- `make e2e-round` only (LOCAL): a separate database for the full-round journey, so closing a
-- round and publishing a rule version never touch the developer's `edqa`. Same owner, extension
-- and grants as docker/postgres/init-dev.sql. Dropped and made again on every run.
DROP DATABASE IF EXISTS edqa_e2e WITH (FORCE);
CREATE DATABASE edqa_e2e OWNER edqa_migrator ENCODING 'UTF8' TEMPLATE template0;

\connect edqa_e2e
CREATE EXTENSION IF NOT EXISTS pg_trgm;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO edqa_app;
GRANT ALL   ON SCHEMA public TO edqa_migrator;
ALTER DEFAULT PRIVILEGES FOR ROLE edqa_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO edqa_app;
ALTER DEFAULT PRIVILEGES FOR ROLE edqa_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO edqa_app;
