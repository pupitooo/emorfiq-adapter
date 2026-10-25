\set ON_ERROR_STOP on
BEGIN;
SELECT 'recovery_tests_' || pg_backend_pid() AS test_schema \gset
CREATE SCHEMA :test_schema;
SET LOCAL search_path TO :test_schema, pg_catalog;
