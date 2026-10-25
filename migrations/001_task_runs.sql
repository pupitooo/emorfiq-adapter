CREATE TABLE task_runs (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    schedule_id text NOT NULL,
    scheduled_for timestamptz NOT NULL,
    task_class text NOT NULL,
    cron_expression text NOT NULL,
    schedule_timezone text NOT NULL,

    without_overlapping boolean NOT NULL,
    overlap_expires_after_minutes integer NOT NULL,
    on_one_server boolean NOT NULL,

    status text NOT NULL,
    owner_token uuid NOT NULL UNIQUE,
    started_at timestamptz NOT NULL,
    lease_expires_at timestamptz,
    finished_at timestamptz,
    error_message text,
    abandoned_at timestamptz,
    abandoned_by text
);

CREATE TABLE task_locks (
    schedule_id text NOT NULL,
    lock_type text NOT NULL CHECK (lock_type IN ('overlap', 'occurrence')),
    scheduled_for timestamptz,
    owner_token uuid NOT NULL,
    run_id bigint REFERENCES task_runs (id) ON DELETE SET NULL,
    expires_at timestamptz,
    PRIMARY KEY (owner_token, lock_type),
    CHECK (
        (lock_type = 'overlap' AND scheduled_for IS NULL AND expires_at IS NOT NULL)
        OR (lock_type = 'occurrence' AND scheduled_for IS NOT NULL AND expires_at IS NULL)
    )
);

-- Completion removes overlap locks; expired locks require explicit recovery.
CREATE UNIQUE INDEX task_locks_overlap
    ON task_locks (schedule_id) WHERE lock_type = 'overlap';

-- Occurrence reservations survive completion, recovery, and history deletion.
CREATE UNIQUE INDEX task_locks_occurrence
    ON task_locks (schedule_id, scheduled_for) WHERE lock_type = 'occurrence';

CREATE INDEX task_locks_run ON task_locks (run_id);
CREATE INDEX task_locks_expiring ON task_locks (expires_at) WHERE lock_type = 'overlap';

CREATE INDEX task_runs_history
    ON task_runs (schedule_id, scheduled_for DESC, id DESC);
