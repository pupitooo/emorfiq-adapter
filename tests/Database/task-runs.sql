CREATE FUNCTION insert_run(
    schedule text,
    minute integer,
    one_server boolean,
    overlap boolean,
    state text DEFAULT 'in_progress',
    expired boolean DEFAULT false
) RETURNS bigint LANGUAGE sql AS $$
    INSERT INTO task_runs (
        schedule_id, scheduled_for, task_class, cron_expression, schedule_timezone,
        without_overlapping, overlap_expires_after_minutes, on_one_server,
        status, owner_token, started_at, lease_expires_at
    ) VALUES (
        schedule, '2026-10-26 10:00:00+00'::timestamptz + minute * interval '1 minute',
        'RecordingTask', '* * * * *', 'UTC', overlap, 5, one_server,
        state, gen_random_uuid(), clock_timestamp(),
        clock_timestamp() + CASE WHEN expired THEN interval '-1 minute' ELSE interval '5 minutes' END
    ) RETURNING id;
$$;

CREATE FUNCTION expect_conflict(schedule text, minute integer, one_server boolean, overlap boolean,
    expected_index text) RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    actual_index text;
BEGIN
    BEGIN
        PERFORM insert_run(schedule, minute, one_server, overlap);
    EXCEPTION WHEN unique_violation THEN
        GET STACKED DIAGNOSTICS actual_index = CONSTRAINT_NAME;
        IF actual_index <> expected_index THEN
            RAISE EXCEPTION 'Expected index %, got %', expected_index, actual_index;
        END IF;
        RETURN;
    END;
    RAISE EXCEPTION 'Expected conflict on %, insert succeeded', expected_index;
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('completed', 0, true, true, 'succeeded');
    PERFORM expect_conflict('completed', 0, true, true, 'task_runs_occurrence');
    PERFORM insert_run('completed', 1, true, true);
    RAISE NOTICE 'PASS completed occurrence stays reserved, next minute is allowed';
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('failed', 0, true, true, 'failed');
    PERFORM expect_conflict('failed', 0, true, true, 'task_runs_occurrence');
    PERFORM insert_run('failed', 1, true, true);
    RAISE NOTICE 'PASS failed occurrence stays reserved and releases active schedule';
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('active', 0, true, true);
    PERFORM expect_conflict('active', 1, true, true, 'task_runs_active_schedule');
    RAISE NOTICE 'PASS different minutes cannot overlap protected active run';
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('expired', 0, true, true, 'in_progress', true);
    PERFORM expect_conflict('expired', 1, true, true, 'task_runs_active_schedule');
    RAISE NOTICE 'PASS elapsed lease alone does not release active index';
END;
$$;

DO $$
DECLARE
    changed integer;
BEGIN
    UPDATE task_runs SET status = 'abandoned', finished_at = clock_timestamp()
    WHERE schedule_id = 'expired' AND status = 'in_progress' AND lease_expires_at <= clock_timestamp();
    GET DIAGNOSTICS changed = ROW_COUNT;
    IF changed <> 1 THEN
        RAISE EXCEPTION 'Expected one expired transition, got %', changed;
    END IF;
    PERFORM insert_run('expired', 1, true, true);
    PERFORM expect_conflict('expired', 0, true, false, 'task_runs_occurrence');
    RAISE NOTICE 'PASS explicit abandonment frees later occurrence, preserves old reservation';
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('single-only', 0, true, false);
    PERFORM insert_run('single-only', 1, true, false);
    PERFORM expect_conflict('single-only', 0, true, false, 'task_runs_occurrence');
    RAISE NOTICE 'PASS single-server protection permits overlapping different occurrences';
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('overlap-only', 0, false, true);
    PERFORM expect_conflict('overlap-only', 0, false, true, 'task_runs_active_schedule');
    UPDATE task_runs SET status = 'succeeded' WHERE schedule_id = 'overlap-only';
    PERFORM insert_run('overlap-only', 0, false, true);
    RAISE NOTICE 'PASS overlap-only protection permits same occurrence after completion';
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('unprotected', 0, false, false);
    PERFORM insert_run('unprotected', 0, false, false);
    RAISE NOTICE 'PASS both protections disabled permits duplicate active occurrences';
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('independent-first', 0, true, true);
    PERFORM insert_run('independent-second', 0, true, true);
    RAISE NOTICE 'PASS same task class under different schedule IDs remains independent';
END;
$$;

DO $$
BEGIN
    PERFORM insert_run('mixed-options', 0, true, true);
    PERFORM insert_run('mixed-options', 0, false, false);
    RAISE NOTICE 'PASS index predicates exclude unprotected rows; consistent configuration is required';
END;
$$;

DO $$
DECLARE
    changed integer;
BEGIN
    UPDATE task_runs SET status = 'abandoned'
    WHERE schedule_id = 'expired' AND status = 'in_progress' AND lease_expires_at <= clock_timestamp();
    GET DIAGNOSTICS changed = ROW_COUNT;
    IF changed <> 0 THEN
        RAISE EXCEPTION 'Recovery must ignore terminal and live rows';
    END IF;
    IF (SELECT count(*) FROM task_runs WHERE schedule_id = 'expired') <> 2 THEN
        RAISE EXCEPTION 'Recovery must retain history';
    END IF;
    RAISE NOTICE 'PASS repeated recovery ignores terminal/live rows and retains history';
END;
$$;

ROLLBACK;
\echo 'PASS all task_runs schema scenarios; test schema rolled back'
