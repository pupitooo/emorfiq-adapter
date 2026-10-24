.PHONY: up down logs test check docs docs-stop docs-check docs-export

up:
	@docker compose up -d --build --wait
	@printf '%s\n' 'http://localhost:$(or $(APP_PORT),8088)/'

down:
	@docker compose down

logs:
	@docker compose logs --tail=50 -f scheduler

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
