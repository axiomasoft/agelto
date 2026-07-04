# HANDOFF — 2026-07-04 — after P0 (Фаза 0 закрыта)

**Next:** /task:plan-exec V2-CORE P1.1
**Done:** Фаза 0 целиком (P0.1 Laravel 12 + P0.2 Docker-стек + P0.3 test-gate/Pest). 7/7 сервисов healthy, `/up` 200, `make test-preflight`/`make test-php` зелёные (3 теста). См. `phases/P0.md` Phase Handoff и Known Deviations по каждому item — там все локальные особенности хоста (VPN/DNS, volume-неймспейс, PUID/PGID, занятые порты).
**Remaining:** Фаза 1 целиком (P1.1-P1.11) — полная схема БД, партиционирование, репозитории, инжест-процессор.
**Sources of truth:** `plans/V2-CORE/plan.md` · `plans/V2-CORE/phases/P0.md` · `plans/V2-CORE/phases/P1.md` · `docs/specs/v2/01-database.md` (актуальная версия — уже с правками по итогам разведки, source_guid/lot_guid) · `docker-compose.yml`
**Open risks:** `pg_partman` доступность в образе `postgres:17-alpine` не проверена — P1.9 может потребовать смены образа или fallback-only решения (эскалировать, не тихо переключаться, см. P1.9 Implementation Rules).
**Workarounds/Deferred/Open questions:**
- workarounds: см. `phases/P0.md` Known Deviations (P0.2 п.1-4, P0.3 п.1-2) — все про Docker/хост, не про домен.
- deferred: —
- open_questions: —
