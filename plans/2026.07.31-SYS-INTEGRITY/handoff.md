# HANDOFF — 2026-08-06 — after P8.3

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P8.4

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | ноль реальных LLM-вызовов; нужен `docker exec agelto_app …` для миграции/тестов (обход чужого дифа `docker-compose.yml`) |
| Context | continue (/clear) — ручной item |
| Суть | Персист: `prc_estimate_items.quality` + `prc_estimates.validation_notes` (миграция аддитивная), сборщик `quality` в общей точке `EstimatePersister::itemAttributes()` обоих writer-путей, аддитивная построчная карта причин в `ValidationReport`/`EstimateValidator`, правило re-run (ключ `model` перезаписывается, `validator` исчезает) |
| Команда | — (`Exec = manual`: `task:plan-exec` отказал бы по undershoot-гейту §9, Routing P8.4 = implementation/high) |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P8.4
```

**Done:** P8.3 закрыт 🟠 2026-08-06 заходом `plan-run` (сессия sonnet/high, совпадает с Routing —
без перебора тира). Item-коммит **`43ff0c7`**, bookkeeping-коммит — следом за этой перезаписью.
Модель получила канал: `App\Dto\Procurement\Analyzers\Estimate\RowQuality` (`reason`+`fields`) +
`App\Enums\Procurement\Estimate\RowQualityReason` (`Unreadable`/`ArithmeticMismatch`/`NumberingGap`,
`isModelOrigin()`), опциональное `EstimateRow::$quality` с `#[Description]`, гейт фиксированной
схемы (неизвестный ключ / неизвестная причина / причина вне model-origin подмножества / пустой
`fields`), промт `extraction:rows` `version: 2 → 3` (правило `unreadable` + запрет помечать законно
пустые поля, И1–И4 не тронуты), `ai:prompts:sync` применён и проверен. Полная фактура — `phases/
P8.md` P8.3 Completion Notes.

**Known Deviations P8.3 (статус 🟠, не 🟢) — три, детали в `phases/P8.md`:**

1. Item-коммит `43ff0c7` несёт дифф вне `Files` P8.3: `tests/Fixtures/Llm/step-json-schemas.json`
   (golden-фикстура) обязательно пересобрана, иначе существующий тест из перечисленного `Files`
   (`JsonSchemaGeneratorTest.php`) стал бы красным от намеренного изменения схемы. Дифф чисто
   аддитивный (+37 строк).
2. `ai:prompts:sync --check` (Implementation Rules п.4) прогнан ПОСЛЕ правки файла промта вместо ДО
   — по факту вывода отсутствие прочего дрейфа подтверждено тем же способом, что дал бы pre-check
   (process-отклонение по порядку, не по результату).
3. Гейт `quality.fields` (запрет пустого списка) добавлен сверх трёх проверок, буквально
   перечисленных в Scope Included п.4 — прямое следствие формы §3.1 `research/12`, названной
   Inputs item'а, не «улучшение заодно».

**Remaining:**

- **Фаза P8** — items P8.4–P8.7 (счёт 2/7 — счётчик считает только `🟢`, P8.3 закрыт `🟠`).
  Очередь по данным: P8.4 (персист) → P8.5 (витрина) → P8.6 (gold/eval) → P8.7 (доки+приёмка фазы).
- Фазы P1–P7 терминальны (`🟠`) — работы по ним не осталось.

**Sources of truth:**

- `research/12-uncertainty-model.md` — замороженная модель; §8 «Карта следствий» — вход `Scope
  Included` P8.4–P8.6 (P8.3 закрыт по нему без отклонений формы).
- `phases/P8.md` P8.3 — Completion Notes/Known Deviations (закрыт); P8.4 — ТЗ уже приведено к карте
  следствий (D158), читать в новой редакции; `## Phase Status` фазы (2/7 по `🟢`).
- `plan.md` — §5 D153–D158, §4 Status Board (P8 `🟡` 2/7), §6 Update Log, §3 Routing (P8.4 —
  `implementation/high`, `manual`, `full`, без изменений).
- `findings/P8-degraded-source.md` — фактура P8.1, на которой стоит модель.
- `app/Dto/Procurement/Analyzers/Estimate/RowQuality.php` + `app/Enums/Procurement/Estimate/
  RowQualityReason.php` — контракт, который P8.4 персистит под ключ `model` формы хранения (§3.2).

**Open risks:**

- **Генератор исходящей схемы не умеет ограничить enum подмножеством** — `RowQuality.reason` несёт
  ПОЛНЫЙ `RowQualityReason` (включая validator-origin значения), отказ на них держит бэкенд
  (`EstimateRow::validateAndCreate()`). Названо в ТЗ P8.3 заранее как допустимое отклонение, не
  требует действия — если P8.4/P8.6 захотят сузить схему, это отдельное решение.
- **Стоимость формы**: `response_schema` шага `extract_rows` выросла на 750 симв. (+9.6%, 7813→8563,
  Gemini-адаптер) добавлением `quality`. Цена платится на КАЖДОМ вызове `extract_rows` (не только на
  деградированном входе) — если P8.6 измерит заметный рост входных токенов на боевом трафике,
  пересмотр формы (например, урезать `RowQuality.reason` до одного значения без enum) — предмет
  отдельного решения, не блокер сегодня.
- **`RepresentationResolver::forLlm()` не отдаёт `pdf_ocr` для голых PDF-сканов** (находка P8.1
  §3 «ж») — факт конфигурации, не баг; вне Scope P8.2/P8.3, отдельного решения так и не получил.
- **И-Б непроверяем сегодня:** «доля помеченных на деградированном эталоне > 0» — требует платного
  прогона с НОВЫМ промтом (version 3, готов после P8.3); условие передано в вердикт измеримости P8.6.
- **Вердикт «деградированных в P4 нет» — временный** (23/66 документов не прошли
  `DocumentPreparer`), пересчитывать той же командой при работе с живым корпусом.
- Стало неверно ещё при закрытии P8.1, не правлено (вне Scope): абзац под Status Board `plan.md`
  §4 утверждает «До exec фазы остаётся один шаг — `plan-audit design`».
- Чужой незакоммиченный диф, НЕ ТРОНУТ (`plan.md` §2 Execution Rules — называть, не трогать):
  `M .env.example`, `M docker-compose.yml` (жёсткое `MINIO_ROOT_PASSWORD:?…` без дефолта —
  `docker compose` CLI падает на интерполяции ДО любой команды; рабочий обход —
  `docker exec agelto_app …`), `M docs/03_Dev/process/testing.md`, untracked
  `.swissknife.json`/`.swissknife/`/`.swissknifeman/`/`config/cms.php`/`database/seeders/User/`/
  `docker/php/conf.d/pcov-dev.ini`/`estimates/gemini-analys.md`/
  `plans/2026.07.31-SYS-INTEGRITY/artifacts/.write-test`.
- Прочие Open risks — без изменений (промты P2.12 не поставлены/OQ-7; миграция P8.4 на БД с
  боевыми данными; качество пометок неизмеримо на eval-контуре, D148).

**Workarounds/Deferred/Open questions:**

- *Workarounds:* команды идут `docker exec agelto_app …` вместо `docker compose exec app …` —
  вынужденный обход чужого дифа `docker-compose.yml`; Validation P8.4 написана в форме `docker
  compose exec app …` — тот же обход применим и там.
- *Deferred:* пересмотр `ai.representations.policy` для PDF-сканов (находка P8.1 §3 «ж»); режим
  bench «без gold» (D151, вариант c); вторая model-origin причина в enum (цена — смена формы поля
  `quality` с объекта на список, названа в §3.1 `research/12`).
- *Open questions:* без изменений (OQ-4/5/6/7/10).
