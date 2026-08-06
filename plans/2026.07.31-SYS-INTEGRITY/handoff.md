# HANDOFF — 2026-08-06 — after P8.7

**Next:** `/task:plan-close 2026.07.31-SYS-INTEGRITY P8`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — закрытие терминального плана, шаг-не-item |
| Context | NEW SESSION — шаг-не-item |
| Суть | Все семь items P8 терминальны — закрыть фазу P8 реконсиляцией (Status Board ↔ Phase Status), затем архивация плана целиком (P1–P8 терминальны) |

```
/task:plan-close 2026.07.31-SYS-INTEGRITY P8
```

**Done:** P8.7 закрыт **🟢 2026-08-06** заходом `plan-exec` (сессия **sonnet/medium** — предписано
Routing `implementation/low`, `Exec=plan-exec`; перебор effort (`low`→`medium`), недобора нет —
запись в Known Deviations item'а не заводилась: `plan-exec` пинит sonnet/medium жёстко, п.2
протокола относит это к структурно неизбежному, не к отклонению исполнителя). Item-коммит
**`6ca3f859`**, bookkeeping-коммит — следом за этой перезаписью. Ноль LLM-вызовов, докс-only item.

Доки сметного домена приведены к факту дерева после P8.1–P8.6:

- **`docs/specs/v2/08-estimate-extraction.md`** §3.2 (контракт `extract_rows` несёт `quality`) и
  §4.1 п.6 (валидатор больше не теряет построчные аномалии — они уходят в тот же носитель) —
  синхронизированы; §8.2/§8.4/§8.6 уже несли пометку до этого item'а (закрыты P8.6), сверены
  чтением файла целиком, не тронуты;
- **`docs/03_Dev/database.md`** — `prc_estimate_items.quality`/`prc_estimates.validation_notes`
  добавлены в карту таблиц с явным правилом «качество ≠ величина источника»;
- **`docs/03_Dev/document-pipeline.md`** — деградация видна на уровне позиции, не FSM документа;
  **`docs/02_Workflow/document-and-estimate-pipeline.md`** — тот же факт бизнес-языком;
- **`docs/03_Dev/admin-panel.md`** — новый ресурс `Estimates/EstimateResource` (P8.5) в карте
  Filament-ресурсов;
- **`docs/04_Operators/how-to/check-marked-positions.md`** (новый) — операторский сценарий
  «что можно и чего НЕЛЬЗЯ» с помеченными позициями (правки нет, OQ-10), подключён в `_index.md`;
  **`docs/04_Operators/reference/statuses.md`** — расшифровка трёх причин пометки по-русски;
- **оба глоссария** (`.ai/guidelines/glossary.md` техническая запись, `docs/02_Workflow/
  glossary.md` бизнес-языковая) — термин пометки неопределённости в каноне;
- **скилл `estimate-extraction-dev`** — раздел `6a)` про деградированный источник (контрактная
  форма ≠ форма хранения, пишется всегда включая явный `NULL`, ловушка `AsCollection::class` на
  ЛЮБОЙ nullable jsonb-колонке проекта, eval мерит `fill_rate` не F1);
- **`CLAUDE.md` НЕ правился** — раздел AI-подомена и так делегирует детали `.ai/guidelines/
  glossary.md` без инлайна, добавленная запись покрывается существующей ссылкой.

Приёмочный прогон фазы (на item-коммите `6ca3f859`): `--no-tia` **1122 passed / 6 failed / 1
skipped** (4950 assertions) — все шесть падений предсуществующие и НЕ от диффа этого докс-only
item'а (`CoreKnowsNoDocumentTypeTest` ×2 — задокументировано P8.4/P8.5; `PromptApplyTest` ×2 —
`Permission denied` на запись в `resources/**`, права volume контейнера; `GeminiProxyRoutingTest`
— недетерминированный Faker-пароль без фиксации seed; `TextGridRendererTest` — memory_limit
средовая гонка). `phpstan analyse` → `[OK] No errors`, baseline не рос. `pint --test` → 12 issues,
ни один вне `Files` этого item'а (docs/скилл не входят в периметр pint). `grep -rn "quality"
docs/03_Dev/database.md docs/specs/v2/08-estimate-extraction.md` → 7 совпадений.

**Remaining:** — фазы P1–P8 терминальны (счётчики Status Board: P1 4/10, P2 5/13, P3 1/5, P4
6/16, P5 3/8, P6 5/6, P7 4/8, P8 4/7 — все счётчики считают только `🟢`, часть items закрыта
`🟠 Done with deviations`, это тоже терминальный статус). Работы execution-уровня по плану не
осталось — следующий шаг протокола (`plan-protocol`) для плана со всеми терминальными фазами —
`plan-close` целиком плана, не `plan-exec`/`plan-design` очередного item'а.

**Sources of truth:**

- `plan.md` §4 Status Board — все восемь фаз терминальны (`🟢`/`🟠`), §6 Update Log — полная
  хронология 8 фаз.
- `phases/P8.md` — Phase Status (7/7 закрыто) и Phase Handoff фазы (только что переписан этим
  item'ом): P8 как целое терминальна, следующих items внутри неё нет.
- Три Pending Work этого item'а (ниже, «Open risks») — предсуществующие находки среды/теста, не
  адресуют предмет плана (деградированный источник смет), решение по follow-up — за владельцем.

**Open risks:**

- **`PromptApplyTest` ×2 падают на `Permission denied`** при записи в `resources/ai/prompts/**`/
  `resources/estimates/ai/prompts/**` внутри `agelto_app` — права volume dev-окружения, не код
  приложения. Фикс-кандидат: `chown`/права на пользователя PHP-FPM в `docker/php/entrypoint`
  или dev-compose — вне Scope этого плана, найдено вживую при приёмочном прогоне P8.7.
- **`GeminiProxyRoutingTest` недетерминирован** — `fake()->password()` без фиксации seed
  непредсказуемо генерирует зарезервированные URL-символы, percent-encoding теста и продакшен-кода
  расходятся на части прогонов. Фикс-кандидат: `Str::random()` вместо `fake()->password()` либо
  явный `rawurlencode()` с обеих сторон сравнения в самом тесте.
- **`TextGridRendererTest` — средовая гонка по memory_limit**: `ini_set('memory_limit', '128M')`
  предполагает низкий стартовый расход процесса, на дереве после полного прогона остальных ~1120
  тестов в одном Paratest-воркере это условие не всегда верно. Фикс-кандидат: поднять лимит теста
  или уменьшить тестовую XLSX-фикстуру.
- **`CoreKnowsNoDocumentTypeTest`** — пред-существующее падение (периметр 101/98, литерал
  `Estimate` в `PreflightDecision.php`/`WorkingCopyProvisioner.php`), задокументировано P8.4/P8.5,
  вне Scope этого плана; воспроизводится независимо от диффа P8.7.
- Открытые риски P8.1–P8.6 (генератор схемы не сужает enum; стоимость формы `response_schema`
  +9.6%; `pdf_ocr` не отдаётся для голых сканов; run-level агрегат прогона не несёт fill-rate;
  `estimate:eval:diff --format=md` не печатает `predicted_quality`; И-Б непроверяема до первого
  платного прогона промтом редакции P8.3; null-unsafety `AsCollection::class` на других nullable
  jsonb-колонках проекта) — без изменений, за владельцем/следующим планом.
- Чужой незакоммиченный диф, НЕ ТРОНУТ (`plan.md` §2 Execution Rules — называть, не трогать):
  `M .env.example`, `M docker-compose.yml` (жёсткое `MINIO_ROOT_PASSWORD:?…` без дефолта —
  `docker compose` CLI падает на интерполяции ДО любой команды; рабочий обход —
  `docker exec agelto_app …`), `M docs/03_Dev/process/testing.md`, untracked
  `.swissknife.json`/`.swissknife/`/`.swissknifeman/`/`config/cms.php`/`database/seeders/User/`/
  `docker/php/conf.d/pcov-dev.ini`/`estimates/gemini-analys.md`.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* команды идут `docker exec agelto_app …` вместо `docker compose exec app …` —
  вынужденный обход чужого дифа `docker-compose.yml`.
- *Deferred:* пересмотр `ai.representations.policy` для PDF-сканов; режим bench «без gold»
  (D151, скан-эталон); OQ-10 (контур операторских правок позиции); fill-rate в агрегате прогона и
  колонка пометки в md-рендере diff (Pending Work P8.6); три найденных вживую фикса тестовой
  среды P8.7 (см. Open risks выше); OQ-5 (вынесен в отдельный план — сверка при `plan-close`).
- *Open questions:* OQ-1…OQ-9 — закрыты по ходу плана (D69–D134, см. `plan.md` §5); OQ-10 —
  открыт (контур операторских правок позиции); OQ-7 (дедупликация текстов промтов P2.12, чужой
  долг).
