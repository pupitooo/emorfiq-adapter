\set ON_ERROR_STOP on

BEGIN;

-- Serialize migration runners until schema changes and their versions commit.
SELECT pg_advisory_xact_lock(65103, 1);

CREATE TABLE IF NOT EXISTS schema_migrations (
    version integer PRIMARY KEY,
    name text NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT now()
);

SELECT NOT EXISTS (
    SELECT 1 FROM schema_migrations WHERE version = 1
) AS apply_001 \gset

\if :apply_001
    \ir 001_task_runs.sql
    INSERT INTO schema_migrations (version, name)
    VALUES (1, 'task_runs');
\endif

COMMIT;
