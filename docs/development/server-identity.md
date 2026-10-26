# Server identity

The scheduler reads its server identity from the `SERVER_ID` environment variable
when `bin/run.php` starts. The value must be non-empty and must not contain
surrounding whitespace. Missing or invalid values stop startup with an error;
the application does not generate an ID or infer one from the hostname.

## Set the identity

Assign each server a distinct, stable value, for example `scheduler-prod-01`.
Keep it unchanged across process restarts and deployments. Scheduler processes
and containers belonging to the same server share this value.

With Docker Compose, set it in `.env` or export it before starting the scheduler:

```sh
SERVER_ID=scheduler-prod-01 make up
```

Compose passes the value to the scheduler container. It supplies `emorfiq-local`
by default for local development; override this value when deploying to multiple
servers. `.env.example` includes the local default.

For standalone PHP, export `SERVER_ID` alongside the database environment
variables described in [Service wiring](service-wiring.md#database-boundary).
PHP does not load `.env` itself.

## Recovery attribution and run ownership

`SERVER_ID` identifies the server performing recovery. It is passed as `claimedBy`
when reserving execution and stored as `abandoned_by` when that reservation
recovers an expired run. See [Recovery](../failure-and-recovery/recovery.md).

Each run has a separate database-generated `owner_token`. Completion and lock
ownership use that token together with the run ID, so processes sharing
`SERVER_ID` still have separate run ownership. See
[History and locks](../coordination/history.md).
