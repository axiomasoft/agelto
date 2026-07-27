# HANDOFF — 2026-07-27 — after P2.4

**Next:** design-item: task:plan-design 2026.07.26-EST-TRAIN P2.4

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item |
| Суть | P2.4 заблокирован: Validation-4 требует `ai:fixtures:verify` → «расхождений: 0» на реальном корпусе, но 11/24 эталонов несут `gold_source ≠ none`, чей `gold_path` физически не существует — сборка `estimates/gold/**` прямо исключена из Scope P2.4 (эта работа — P5). Первый реальный прогон дал `ok: 13 · расхождений: 11 (все — отсутствующий gold_path) · лишних: 0`. Владелец решил (2026-07-27): эскалация, решать через `plan-design`, не форсировать код. Нужно решить: переформулировать Validation-4 (gold_path — предупреждение до готовности P5) ИЛИ переформулировать критерий закрытия item'а под факт «первый прогон до P5». Холодный вход: `plan.md` → `phases/P2.md` P2.4 (блок эскалации + Pending Work) → `handoff.md` |

```
/task:plan-design 2026.07.26-EST-TRAIN P2.4
```

**Done:** P2.1 — 🟠 (диски `estimates`/`estimates_s3` + `ai.fixtures`, посторонний дифф —
`league/flysystem-aws-s3-v3` с хоста). P2.2 — 🟠 (реестр `prc_ai_fixtures`: модель, enum'ы,
репозиторий, фабрика; посторонний дифф — общий `phpstan`/`larastan`-коммит `7485520`, установлен
по решению владельца, вне `Files` P2.1/P2.2). P2.3 — 🟢 (`FixtureManifest`/`FixtureManifestEntry` +
`ai:fixtures:import`, идемпотентный по sha256, шесть кейсов). P2.4 — код написан и зелёный
(`FixturesManifestCommand`, `FixturesVerifyCommand`, семь тестов), реальный прогон
`manifest → import → verify` на корпусе выполнен (24/24 импортировано, 0 отклонено) — НЕ
закоммичен, item заблокирован расхождением Validation-4 со Scope (см. выше). Детали каждого item'а
— `phases/P2.md` соответствующие Completion Notes.

**Remaining:** P2.4 требует design-решения владельца по критерию Validation-4, затем
`/task:plan-exec 2026.07.26-EST-TRAIN P2.4` повторно (код уже готов — потребуется только
скорректировать `FixturesVerifyCommand`/Validation под принятое решение, если оно потребует правки
кода). Фазы P3–P9 не начаты.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Meta v0.15.0, §3 Routing, D1–D90) ·
`plans/2026.07.26-EST-TRAIN/phases/P2.md` (ТЗ P2.1–P2.4 + блок эскалации P2.4) ·
`plans/2026.07.26-EST-TRAIN/artifacts/fixture-shortlist.json` (24 записи, счётчик `gold_source`:
13 `none` / 10 `deterministic` / 1 `llm_assisted`) · `estimates/manifest.json` (сгенерирован
реальным прогоном, НЕ закоммичен — ждёт решения по P2.4) · `docs/specs/v2/08-estimate-extraction.md`
§8 · `plans/EST-AI/plan.md`.

**Open risks:**
- **P2.4 заблокирован structural-противоречием ТЗ**: Validation-4 (`verify` → 0 расхождений) и
  Scope Excluded («создание `estimates/gold/**` — P5») взаимно исключают друг друга на первом
  реальном прогоне. Без design-решения item не закрыть.
- Реальный корпус УЖЕ развёрнут в хранилище и реестре (24 записи `prc_ai_fixtures`, файлы на диске
  `estimates`) прогоном P2.4 — это корректный промежуточный факт, но `estimates/manifest.json` не
  закоммичен: `git status --porcelain estimates/` покажет untracked `manifest.json`, пока item не
  закрыт.
- Все риски, унаследованные от P1 (см. предыдущий `handoff.md` до этого прогона): `CL-16` без OCR,
  21 файл КС-3 вне extraction, величина вне шести полей F1, коды эталонов уезжают в пути хранилища
  (уже частично реализовано P2.1–P2.4), OQ-3/OQ-4 открыты.
- `phpstan`/`larastan` подключены НЕ по плану, а по решению владельца в ходе P2.2 (коммит
  `7485520`, level 5, baseline 290 предсуществующих ошибок) — последующие items плана используют
  уже подключённый инструмент.

**Workarounds/Deferred/Open questions:**
- workarounds: `REDIS_PORT` переопределён на хосте (порт 6379 занят другим проектом `sova_redis`) —
  `REDIS_PORT=6391 make up`/`docker compose --env-file .env --env-file .env.docker exec …`; `make
  artisan`/`make ps` из корневого `Makefile` конфликтуют с файлом `artisan` в корне репо (make
  считает цель `artisan` уже актуальной) — использовать `docker compose --env-file … exec app php
  artisan …` напрямую; запись в bind-mounted `estimates/`/`app/**` из контейнера требует ACL
  (`setfacl -R -m u:www-data:rwx <путь>`), иначе `Permission denied` (UID хоста ≠ `www-data`
  контейнера); `.env.docker*.example` правились через `python3 -c "open(...).read()/write(...)"` —
  Read/Edit-тулы дают permission-deny на `.env*` независимо от project settings.json (харнесс-
  уровень); прочие — см. предыдущий `handoff.md` (перенесены без изменений).
- deferred: см. предыдущий `handoff.md` (без изменений) + переформулировка Validation-4 P2.4 (эта
  эскалация).
- open_questions: OQ-3, OQ-4 — без изменений (см. предыдущий `handoff.md`).
