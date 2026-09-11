# Исходный бриф владельца

Дата: 2026-09-10

Создать remediation непрерывного парсинга ЕИС на основании `AGENTS.md`,
`PARSING_PRODUCTION_AUDIT.md`, `PARSING_PRODUCTION_ACCEPTANCE.md` и терминального Layout v2 архива
`plans/archive/2026.08.06-CRAWL-HARDEN/{plan.md,status.md,handoff.md}`.

Нужны два результата: корневой документ `PARSING_PRODUCTION_REMEDIATION.md` и новый Layout v2
master plan в `plans/`. План должен быть зависимым и phase-first: партиции; полнота и конкурентность
crawling; достоверность snapshots и lifecycle документов; recovery/storage/timeouts; финальная
независимая приёмка и controlled-production gate.

Обязательно покрыть A1–A9, B1, NEW-1 и NEW-2. B2 оформить явным решением с live-verification
gate. B3 оформить отдельным неблокирующим observability item. Для каждого item назвать точные
файлы, regression/fault-injection сценарии, команды проверки и критерии закрытия.

Design-сессия не изменяет приложение, тесты или конфигурацию. Все чужие dirty changes сохраняются.
До и после plan work выполняются обязательные Task checks в порядке из `AGENTS.md`.
