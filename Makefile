UID := $(shell id -u)
GID := $(shell id -g)

.PHONY: up down build logs shell art comp test restart-worker

up:             ; docker compose up -d
down:           ; docker compose down
build:          ; docker compose build
logs:           ; docker compose logs -f $(S)
shell:          ; docker compose exec -u $(UID):$(GID) app sh
art:            ; docker compose exec -u $(UID):$(GID) app php artisan $(ARGS)
comp:           ; docker compose exec -u $(UID):$(GID) app composer $(ARGS)
test:           ; docker compose exec app php artisan test
restart-worker: ; docker compose restart horizon
