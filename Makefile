.PHONY: up down logs psql migrate migration-status test check docs docs-stop docs-check docs-export

up:
	@docker compose up -d --build --wait
	@printf 'Log viewer: http://%s/\n' "$$(docker compose port web 8080)"
	@printf 'Adminer: http://%s/\n' "$$(docker compose port adminer 8080)"

down:
	@docker compose down

logs:
	@docker compose logs --tail=50 -f scheduler

psql:
	@docker compose exec postgres sh -c 'exec psql -X --username="$${POSTGRES_USER}" --dbname="$${POSTGRES_DB}"'

migrate:
	@docker compose exec -T postgres sh -c 'exec psql -X --set=ON_ERROR_STOP=on --username="$${POSTGRES_USER}" --dbname="$${POSTGRES_DB}" --file=/migrations/migrate.sql'

migration-status:
	@docker compose exec -T postgres sh -c 'exec psql -X --set=ON_ERROR_STOP=on --username="$${POSTGRES_USER}" --dbname="$${POSTGRES_DB}" --command="TABLE schema_migrations"'

test:
	@docker compose build check
	@docker compose run --rm --no-deps -T check composer test

check:
	@docker compose build check
	@docker compose run --rm --no-deps -T check

docs:
	@docker compose up -d --wait docs
	@printf 'Documentation: http://%s/\n' "$$(docker compose port docs 8000)"

docs-stop:
	@docker compose stop docs

docs-check:
	@docker compose run --rm --no-deps -T docs build --strict --config-file mkdocs.yml

docs-export:
	@mkdir -p build/docs
	@docker compose run --rm --no-deps -T --user "$$(id -u):$$(id -g)" \
	  --volume "$(CURDIR)/build/docs:/site" docs \
	  build --strict --config-file mkdocs.yml --site-dir /site
