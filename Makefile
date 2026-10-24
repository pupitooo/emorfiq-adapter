.PHONY: up down logs test check

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
