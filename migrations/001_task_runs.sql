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
    owner_token uuid NOT NULL,
    started_at timestamptz NOT NULL,
    lease_expires_at timestamptz NOT NULL,
    finished_at timestamptz,
    error_message text
);

-- A completed occurrence remains reserved when single-server execution is enabled.
CREATE UNIQUE INDEX task_runs_occurrence
    ON task_runs (schedule_id, scheduled_for)
    WHERE on_one_server;

-- Other due minutes cannot overlap an active protected execution.
-- Expired leases must be transitioned explicitly; time alone does not free this index.
CREATE UNIQUE INDEX task_runs_active_schedule
    ON task_runs (schedule_id)
    WHERE without_overlapping AND status = 'in_progress';

CREATE INDEX task_runs_expiring
    ON task_runs (lease_expires_at, id)
    WHERE status = 'in_progress';

CREATE INDEX task_runs_history
    ON task_runs (schedule_id, scheduled_for DESC, id DESC);
