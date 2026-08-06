# HANDOFF — 2026-08-06 — after P8.6

**Next:** `/task:plan-exec 2026.07.31-SYS-INTEGRITY P8.7`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — синхронизация доков по принятым решениям |
| Context | continue (/clear) — ручной item |
| Суть | Доки сметного домена + оба глоссария + скилл, приёмочный прогон фазы `--no-tia` с атрибуцией падений, `## Phase Handoff` P8 |

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P8.7
```

**Done:** P8.6 закрыт **🟢 2026-08-06** заходом `plan-run` (сессия **opus/high**; Routing предписывал
`implementation/high` — перебор тира, недобора нет, запись в Known Deviations). Item-коммит
**`c88f91a`**, bookkeeping-коммит — следом за этой перезаписью. Ноль LLM-вызовов.

Пометка неопределённости стала представимой в эталоне и измеримой:

- **gold-контракт** принимает `quality` в КОНТРАКТНОЙ форме (`{"reason","fields"}`) — `DatasetImporter`
  НЕ правился: гейт уже несёт `EstimateRow::validateAndCreate()` (P8.3), второй носитель правила
  заводить запрещено. Доказано тестами импорта реального JSON: эталон с пометкой доезжает, эталон без
  пометки валиден, четыре формы битой пометки отклоняются поимённо;
- **`EvalMetrics::compare()['quality']`** — `marked_rows`/`item_rows`/`fill_rate`/`by_reason` по
  позициям (`row_type=item`) предсказания. `FIELD_MAP`/`ROW_NUMERIC_FIELDS` не тронуты (D139) и
  охраняются сторож-тестом; ветки провенанса «валидатор» в разбивке нет (D148) — `marked_rows` = сумме
  `by_reason` всегда;
- **`DiffEntry::$predictedQuality`** (седьмое необязательное свойство, D149) — пометка как КОНТЕКСТ
  расхождения: только на `field_mismatch` и только когда адресует ИМЕННО это поле; своей записи не
  порождает, на `verdict` не влияет;
- **вердикт измеримости** — `research/12-uncertainty-model.md` §9 (форма D46/P5.6): мерится
  заполненностью, не F1. **Число на `41d3fc7a`: 6 эталонов · 214 позиций · 0 помеченных ·
  `fill_rate` 0.0** — И-А (ложных пометок нет) измеримо и совпадает с нормой; **И-Б (пометки на
  деградированном есть) сегодня не измеримо** — gold у скан-эталона нет, последний платный прогон шёл
  старым промтом. Условие пересмотра структурное (§9.2).

Validation зелёная целиком: `--filter=EvalMetrics --no-tia` 27 passed · `--filter=DatasetImport
--no-tia` 11 passed · `--filter=EstimateDiffReport --no-tia` 13 passed · `estimate:eval:run --dry-run`
без единого вызова · `pint --dirty --test` passed · `phpstan analyse` `[OK] No errors`, baseline не
рос. Потребители формы агрегата проверены отдельно: `EvalDiff`/`EvalRun`/`EvalCompare`/`BenchFormats`
— 3/11/14/9 passed.

**Remaining:**

- **Фаза P8** — остался один item **P8.7** (доки + приёмочный прогон фазы). Счёт 3/7 — счётчик считает
  только `🟢`, P8.3–P8.5 закрыты `🟠`.
- Фазы P1–P7 терминальны (`🟠`) — работы по ним не осталось.

**Sources of truth:**

- `research/12-uncertainty-model.md` §9 — вердикт измеримости, число и структурное условие пересмотра
  (единственный носитель вердикта; P8.7 его пересказывает, а не переписывает).
- `phases/P8.md` P8.6 — Completion Notes/Pending Work (закрыт); P8.7 — Scope Included готово, читать
  целиком; `## Phase Status` фазы (3/7 по `🟢`).
- `plan.md` — §4 Status Board (P8 `🟡` 3/7), §6 Update Log, §3 Routing (P8.7 — `implementation/low`,
  `Exec = plan-exec`, `Review = light`).
- `app/Services/Procurement/Analyzers/Estimate/EvalMetrics.php` — `qualityCoverage()`, единственный
  носитель правила «как считается заполненность пометок».
- `docs/specs/v2/08-estimate-extraction.md` §8.2/§8.4/§8.6 — синхронизированы этим item'ом; §3.2
  (контракт строки несёт `quality`) — **ещё НЕ синхронизирована**, это Scope Included 1 P8.7.

**Open risks:**

- **Run-level агрегат прогона не несёт fill-rate** (Pending Work P8.6 п.1): `ExtractionEvalRunner::
  aggregate()` усредняет только `overall`/`row_coverage`, число живёт в `prc_ai_eval_results.details`
  на пример. Дрейф «модель перестала помечать» не виден в сводке прогона. Фикс — среднее `fill_rate` +
  сумма `by_reason` в `aggregate()`; решение о заведении follow-up item'а за владельцем.
- **`estimate:eval:diff --format=md` не печатает `predicted_quality`** (Pending Work P8.6 п.2) —
  контекст виден только в `--format=json`; фикс — седьмая колонка md-рендера.
- **И-Б непроверяема до первого платного прогона** с промтом редакции P8.3 — «канал работает»
  утверждать нельзя; это вход следующего платного шага, не долг кода.
- Найденный дефект NULL-unsafety `AsCollection::class` на других nullable jsonb-колонках проекта
  (Pending Work P8.4) — без изменений, не блокер.
- Открытые риски P8.1–P8.3 (генератор схемы не сужает enum; стоимость формы `response_schema` +9.6%;
  `pdf_ocr` не отдаётся для голых сканов) — без изменений.
- Стало неверно ещё при закрытии P8.1, не правлено (вне Scope): абзац под Status Board `plan.md` §4
  утверждает «До exec фазы остаётся один шаг — `plan-audit design`».
- Чужой незакоммиченный диф, НЕ ТРОНУТ (`plan.md` §2 Execution Rules — называть, не трогать):
  `M .env.example`, `M docker-compose.yml` (жёсткое `MINIO_ROOT_PASSWORD:?…` без дефолта —
  `docker compose` CLI падает на интерполяции ДО любой команды; рабочий обход —
  `docker exec agelto_app …`), `M docs/03_Dev/process/testing.md`, untracked
  `.swissknife.json`/`.swissknife/`/`.swissknifeman/`/`config/cms.php`/`database/seeders/User/`/
  `docker/php/conf.d/pcov-dev.ini`/`estimates/gemini-analys.md`.
- `CoreKnowsNoDocumentTypeTest` — пред-существующее падение, вне Scope этого плана (зафиксировано
  P8.4/P8.5); приёмочный прогон P8.7 обязан атрибутировать его именно так.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* команды идут `docker exec agelto_app …` вместо `docker compose exec app …` —
  вынужденный обход чужого дифа `docker-compose.yml`.
- *Deferred:* пересмотр `ai.representations.policy` для PDF-сканов; режим bench «без gold» (D151,
  скан-эталон); OQ-10 (контур операторских правок позиции, D137); fill-rate в агрегате прогона и
  колонка пометки в md-рендере diff (Pending Work P8.6).
- *Open questions:* OQ-7 (дедупликация текстов промтов P2.12, чужой долг); D148 закрыт P8.6 —
  провенанс на eval-пути не заводится, граница зафиксирована тестом.
