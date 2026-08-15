---
id: D69
date: 2026-08-10
status: accepted
item: P2.3
items: [P2.3]
supersedes: []
superseded_by: null
---
## Решение

P1 задаёт для preflight единственный host-version contract: успешный запуск
`docker compose version`, то есть доступность Compose v2 subcommand. Числовой minimum
version в P1 не заморожен и P2.3 не вводит второй numeric threshold; он вызывает тот же
capability check перед собственными read-only проверками. RAG:— (repo-grounded:
`docker/scripts/compose-preflight.sh`, `docker/tests/make-contract.sh`).

## Почему

P2.3 требовал «читать P1 version thresholds», но P1 публикует только capability contract.
Числовой порог, придуманный P2.3, стал бы несогласованным вторым policy source.

## Alternatives

1. Добавить numeric minimum в P2.3 — отклонено: дублирует policy P1.
2. Переоткрыть терминальную P1 только ради новой numeric policy — отклонено: текущий Compose
   carrier уже доказывает требуемую capability.
3. Зафиксировать P1 capability contract как единственный вход P2.3 — выбранный вариант.

## Consequences

P2.3 проверяет presence/capability через `docker compose version`, не парсит версию и не
меняет P1 product files. Изменение Compose feature floor в будущем требует новой D-записи и
обновления P1 carrier до возобновления P2.3.
