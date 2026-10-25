-- History deliberately has no concurrency predicates. Locks enforce protection independently.
CREATE FUNCTION insert_run(schedule text, minute integer DEFAULT 0) RETURNS bigint LANGUAGE sql AS $$
    INSERT INTO task_runs (
        schedule_id, scheduled_for, task_class, cron_expression, schedule_timezone,
        without_overlapping, overlap_expires_after_minutes, on_one_server,
        status, owner_token, started_at
    ) VALUES (
        schedule, '2026-10-26 10:00:00+00'::timestamptz + minute * interval '1 minute',
        'RecordingTask', '* * * * *', 'UTC', false, 5, false,
        'in_progress', gen_random_uuid(), clock_timestamp()
    ) RETURNING id;
$$;

CREATE FUNCTION insert_lock(run bigint, kind text, deadline timestamptz DEFAULT NULL)
RETURNS void LANGUAGE sql AS $$
    INSERT INTO task_locks (schedule_id, lock_type, scheduled_for, owner_token, run_id, expires_at)
    SELECT schedule_id, kind, CASE WHEN kind = 'occurrence' THEN scheduled_for END,
        owner_token, id, CASE WHEN kind = 'overlap' THEN COALESCE(deadline, clock_timestamp() + interval '5 minutes') END
    FROM task_runs WHERE id = run;
$$;

CREATE FUNCTION expect_conflict(run bigint, kind text, expected_index text)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    actual_index text;
BEGIN
    BEGIN
        PERFORM insert_lock(run, kind);
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
DECLARE
    first_run bigint;
    second_run bigint;
BEGIN
    first_run := insert_run('history');
    second_run := insert_run('history');
    IF first_run = second_run OR (SELECT count(DISTINCT owner_token) FROM task_runs) <> 2
        OR EXISTS (SELECT 1 FROM task_locks) THEN
        RAISE EXCEPTION 'Duplicate unprotected executions require distinct history and no locks';
    END IF;
    PERFORM insert_lock(first_run, 'overlap');
    PERFORM insert_run('history');
    RAISE NOTICE 'PASS history neither acquires nor enforces concurrency locks';
END;
$$;

DO $$
DECLARE
    original bigint;
BEGIN
    original := insert_run('overlap');
    PERFORM insert_lock(original, 'overlap');
    PERFORM expect_conflict(insert_run('overlap', 1), 'overlap', 'task_locks_overlap');
    PERFORM insert_lock(insert_run('other-schedule'), 'overlap');
    RAISE NOTICE 'PASS overlap index blocks other minutes only within same schedule';
END;
$$;

DO $$
DECLARE
    original bigint;
BEGIN
    original := insert_run('occurrence');
    PERFORM insert_lock(original, 'occurrence');
    PERFORM expect_conflict(insert_run('occurrence'), 'occurrence', 'task_locks_occurrence');
    PERFORM insert_lock(insert_run('occurrence', 1), 'occurrence');
    UPDATE task_runs SET status = 'succeeded' WHERE id = original;
    PERFORM expect_conflict(insert_run('occurrence'), 'occurrence', 'task_locks_occurrence');
    RAISE NOTICE 'PASS occurrence index permits other minutes and survives completion';
END;
$$;

DO $$
DECLARE
    original bigint;
BEGIN
    original := insert_run('expired');
    PERFORM insert_lock(original, 'overlap', clock_timestamp() - interval '1 minute');
    PERFORM insert_lock(original, 'occurrence');
    UPDATE task_runs SET status = 'abandoned' WHERE id = original;
    PERFORM expect_conflict(insert_run('expired', 1), 'overlap', 'task_locks_overlap');
    DELETE FROM task_locks WHERE run_id = original AND lock_type = 'overlap';
    PERFORM insert_lock(insert_run('expired', 1), 'overlap');
    PERFORM expect_conflict(insert_run('expired'), 'occurrence', 'task_locks_occurrence');
    RAISE NOTICE 'PASS expiry and history status do not release locks; explicit removal frees overlap only';
END;
$$;

DO $$
DECLARE
    original bigint;
    original_owner uuid;
BEGIN
    original := insert_run('deleted-history');
    SELECT owner_token INTO STRICT original_owner FROM task_runs WHERE id = original;
    PERFORM insert_lock(original, 'overlap');
    PERFORM insert_lock(original, 'occurrence');
    DELETE FROM task_runs WHERE id = original;
    IF (SELECT count(*) FROM task_locks WHERE owner_token = original_owner AND run_id IS NULL) <> 2 THEN
        RAISE EXCEPTION 'Deleting history must detach both locks and preserve their owner';
    END IF;
    PERFORM expect_conflict(insert_run('deleted-history', 1), 'overlap', 'task_locks_overlap');
    PERFORM expect_conflict(insert_run('deleted-history'), 'occurrence', 'task_locks_occurrence');
    RAISE NOTICE 'PASS foreign key detaches deleted history without releasing either protection';
END;
$$;

DO $$
DECLARE
    original bigint;
BEGIN
    original := insert_run('owner');
    PERFORM insert_lock(original, 'overlap');
    PERFORM insert_lock(original, 'occurrence');
    PERFORM expect_conflict(original, 'overlap', 'task_locks_pkey');
    IF (SELECT count(*) FROM task_locks WHERE run_id = original) <> 2 THEN
        RAISE EXCEPTION 'One owner must be able to hold exactly one lock of each type';
    END IF;
    RAISE NOTICE 'PASS owner/type primary key permits both protection kinds';
END;
$$;

DO $$
DECLARE
    kind text;
    occurrence timestamptz;
    deadline timestamptz;
BEGIN
    FOR kind, occurrence, deadline IN VALUES
        ('unknown', NULL::timestamptz, NULL::timestamptz),
        ('overlap', NULL::timestamptz, NULL::timestamptz),
        ('overlap', now(), now()),
        ('occurrence', NULL::timestamptz, NULL::timestamptz),
        ('occurrence', now(), now())
    LOOP
        BEGIN
            INSERT INTO task_locks (schedule_id, lock_type, owner_token, scheduled_for, expires_at)
            VALUES ('invalid', kind, gen_random_uuid(), occurrence, deadline);
        EXCEPTION WHEN check_violation THEN
            CONTINUE;
        END;
        RAISE EXCEPTION 'Invalid lock shape accepted: %, %, %', kind, occurrence, deadline;
    END LOOP;
    RAISE NOTICE 'PASS lock constraints reject unknown types and invalid occurrence/deadline combinations';
END;
$$;

DO $$
BEGIN
    BEGIN
        INSERT INTO task_locks (schedule_id, lock_type, owner_token, run_id, expires_at)
        VALUES ('missing', 'overlap', gen_random_uuid(), 9223372036854775807, now());
    EXCEPTION WHEN foreign_key_violation THEN
        RAISE NOTICE 'PASS linked locks require existing history';
        RETURN;
    END;
    RAISE EXCEPTION 'Nonexistent run reference accepted';
END;
$$;

DO $$
DECLARE
    original bigint;
    owner uuid;
    duplicate bigint;
BEGIN
    original := insert_run('unique-owner');
    SELECT owner_token INTO STRICT owner FROM task_runs WHERE id = original;
    duplicate := insert_run('duplicate-owner');
    BEGIN
        UPDATE task_runs SET owner_token = owner WHERE id = duplicate;
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PASS history owner tokens remain unique';
        RETURN;
    END;
    RAISE EXCEPTION 'Duplicate owner token accepted';
END;
$$;

ROLLBACK;
\echo 'PASS all history and lock schema scenarios; test schema rolled back'
