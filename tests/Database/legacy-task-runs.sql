INSERT INTO task_runs (
    schedule_id, scheduled_for, task_class, cron_expression, schedule_timezone,
    without_overlapping, overlap_expires_after_minutes, on_one_server,
    status, owner_token, started_at, lease_expires_at, finished_at
) VALUES
    ('legacy-abandoned', '2026-10-26 10:00:00+00', 'RecordingTask', '* * * * *', 'UTC',
     true, 5, true, 'abandoned', gen_random_uuid(),
     '2026-10-26 10:00:00+00', '2026-10-26 10:05:00+00', '2026-10-26 10:06:00+00'),
    ('legacy-success', '2026-10-26 10:00:00+00', 'RecordingTask', '* * * * *', 'UTC',
     true, 5, true, 'succeeded', gen_random_uuid(),
     '2026-10-26 10:00:00+00', '2026-10-26 10:05:00+00', '2026-10-26 10:01:00+00'),
    ('legacy-single-only', '2026-10-26 10:00:00+00', 'RecordingTask', '* * * * *', 'UTC',
     false, 5, true, 'in_progress', gen_random_uuid(),
     '2026-10-26 10:00:00+00', '2026-10-26 10:05:00+00', NULL);
