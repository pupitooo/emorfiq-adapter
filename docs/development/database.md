# Database

## Start PostgreSQL and Adminer

`make up` starts PostgreSQL and Adminer together with the application. To start
only the database tools without rebuilding PHP:

```sh
docker compose up -d --wait postgres adminer
```

PostgreSQL uses the `18.6-alpine` runtime from public ECR, UTC database and log
time zones, a health check, and persistent storage. Adminer uses a pinned
`6.1.1-standalone` image and the `nette` theme.
The UI is exposed directly on a loopback port in this project.

## Connect

Open [Adminer](http://localhost:8090) and enter:

| Field | Value |
| --- | --- |
| System | PostgreSQL |
| Server | `postgres` |
| Username | `emorfiq` |
| Password | `emorfiq_local_dev` |
| Database | `emorfiq` |

The server name is resolved inside the Compose network. From a host-side SQL
client, use `127.0.0.1:5433` instead. Both published ports bind only to loopback.

For an interactive terminal session without installing PostgreSQL locally:

```sh
make psql
```

## Configuration and storage

Copy `.env.example` to `.env` to override the local defaults, or provide
environment variables when starting services:

```sh
ADMINER_PORT=8091 POSTGRES_PORT=5434 docker compose up -d --wait postgres adminer
```

`POSTGRES_PASSWORD` overrides the development password. PostgreSQL uses
`POSTGRES_DB`, `POSTGRES_USER`, and `POSTGRES_PASSWORD` to initialize a new volume;
changing the environment does not update credentials in an existing database.
The example credentials are intended for local development.

Database files live in the Compose-managed `postgres_data` volume, mounted at
`/var/lib/postgresql` for PostgreSQL 18. `make down` preserves this volume.

The database starts without application tables. Apply the versioned schema
explicitly with the [migration commands](migrations.md). Runtime connections use
`DATABASE_DSN`, `DATABASE_USER`, and `DATABASE_PASSWORD`, supplied by Compose.
`DATABASE_PASSWORD` defaults to `POSTGRES_PASSWORD` when it is not set explicitly.
Connection registration and transaction ownership are described in
[Service wiring](../configuration/services.md#database-boundary).

Run `make test-storage` for isolated PostgreSQL storage tests. See
[Storage tests](../execution/storage.md#storage-tests) for coverage and isolation.
