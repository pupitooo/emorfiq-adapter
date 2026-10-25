ALTER TABLE task_runs
    ADD COLUMN abandoned_at timestamptz,
    ADD COLUMN abandoned_by text,
    ALTER COLUMN lease_expires_at DROP NOT NULL;

-- Occurrence deduplication has no execution deadline of its own.
UPDATE task_runs SET lease_expires_at = NULL WHERE NOT without_overlapping;

-- Older recovery used finished_at for detection time, not confirmed completion.
-- The original recovery actor was not recorded and cannot be reconstructed.
UPDATE task_runs
SET abandoned_at = finished_at,
    abandoned_by = 'legacy:unknown',
    finished_at = NULL
WHERE status = 'abandoned';
