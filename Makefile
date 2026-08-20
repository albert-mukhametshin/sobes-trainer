.PHONY: build up down logs shell install test lint db-create db-migrate ai-install ai-pull ai-asr ai-ollama

build:
	docker compose build

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f

shell:
	docker compose exec php sh

install:
	docker compose run --rm php composer install

test:
	docker compose exec -e APP_ENV=test php php vendor/bin/phpunit

lint:
	docker compose exec php php bin/console lint:container
	docker compose exec php php bin/console lint:yaml config

db-create:
	docker compose exec php php bin/console doctrine:database:create --if-not-exists

db-migrate:
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

ai-install:
	cd local-ai && uv sync

ai-pull:
	ollama pull qwen3.5:9b-q4_K_M

ai-asr:
	cd local-ai && AUDIO_STORAGE_DIR="$(CURDIR)/var/audio" LOCAL_AI_TOKEN="local-interview-trainer" LOCAL_ASR_MODEL="v3_e2e_rnnt" GIGAAM_DEVICE="auto" uv run uvicorn app:app --host 0.0.0.0 --port 8091

ai-ollama:
	ollama serve
