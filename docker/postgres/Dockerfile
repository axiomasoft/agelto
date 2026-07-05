FROM postgres:17

# pg_partman недоступен в postgres:17-alpine (нет PGDG apt-репозитория для Alpine
# и нет собранного пакета) — базовый образ переключён на debian-вариант postgres:17,
# где PGDG-репозиторий уже настроен из коробки (см. plans/V2-CORE/phases/P1.md P1.9).
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-17-partman \
    && rm -rf /var/lib/apt/lists/*
