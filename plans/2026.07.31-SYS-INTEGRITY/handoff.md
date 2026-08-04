# HANDOFF — 2026-08-04 — after P5.7

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/low

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — доки и приёмочный прогон фазы, новых решений нет (§3 Routing P5.8) |
| Context | continue (/clear) — ручной item |
| Суть | P5.8 — канон сметной модели в доках (`docs/03_Dev/database.md`, `docs/specs/v2/08-estimate-extraction.md` §8.2) под фактическое состояние P5.1–P5.7 и приёмочный прогон фазы |
| Команда | — |

Код-блок ниже несёт полную D18-форму:

```
/model sonnet
/effort low
/task:plan-run 2026.07.31-SYS-INTEGRITY P5.8
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-04).** P5.7 закрыт 🟠 Done with
deviations, item-коммит `cbfc8c5` (2 файла — `resources/estimates/ai/prompts/extraction-passport/
0-extraction-passport.md` + `plans/2026.07.31-SYS-INTEGRITY/findings/P5-eval-run.md`, оба из
`Files` item'а):

- **Промт `extraction-passport` version 2→3**: блок «Нормативная база сметы» — три реквизита формы
  421/пр (`regulatory_base`/`passport.regulatory_amendments`/`passport.index_letter`), явное
  разграничение с `passport.reference`. Остальные три файла (`rows`/`totals`/`global`) не тронуты —
  правило не сквозное. `ai:prompts:sync` применён, `--check` чист, `--filter=PromptComposition` —
  12 passed.
- **Находка ДО прогона**: `EvalRun #22` (baseline P5.4) и `prc_ai_dataset_examples` пропали из
  dev-БД (`migrate:fresh` вне Scope между закрытием P5.6 и стартом P5.7) — восстановлено
  `estimate:gold:import --prepare` (24 документа, 6 extraction + 11 screening). Числа baseline
  целиком сохранены в `findings/P5-eval-baseline.md` — сравнение этого item'а вело ручным повтором
  формулы `EvalRunComparator`.
- **Контрольный прогон — два платных захода** (владелец одобрил второй заход поверх D45-цикла):
  заход 1 (`EvalRun #2`) — 3/6, holdout-подвыборка пуста целиком (оба holdout-эталона упали по
  `cURL error 28`, timeout 150 с), $0.5366; заход 2 (`EvalRun #3`, решающий) — 5/6,
  `EST-XLSX-KS2-001` снова упал тем же timeout, `EST-XLSX-LSR-001` (целевой эталон правки)
  обработан успешно. Фактический расход: **$1.1643**.
- **Вердикт непрегрессии: `reject`** — все три причины отказа сводятся к ОДНОМУ факту:
  `EST-XLSX-KS2-001` не ответил за 150 с в ОБОИХ заходах (в baseline этот же эталон прошёл без
  единого timeout на 44 вызовах) — инфраструктурный сбой, не связанный с диффом item'а. На всех
  пяти эталонах, обработанных ОБОИМИ прогонами, candidate f1 равен или выше baseline; целевой
  эталон правки вырос на +0.0303 f1. Правка НЕ откатывается — тот же приём, что M2/M3c P2.5.
- **Измеримость — достигнута**: на `EST-XLSX-LSR-001` все три новых поля непусты и БУКВАЛЬНО
  совпадают с документом; `passport.reference` — `null`, не спутан с нормативной базой.
- **Task Contract**: `pre_mutation: allow` · `pre_final: allow` (route `implementation/high`,
  точное совпадение с Routing — не перебор).

Полные числа, формула и разбор — `findings/P5-eval-run.md`.

**Status:** 🟠 Done with deviations. Known Deviations — три (Validation-клауза `estimate:eval:
compare` выполнена не буквально из-за пропажи `EvalRun #22`; вердикт `reject` без отката диффа,
причина изолирована; два платных захода вместо одного).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5.1** 🟠, **P5.2** 🟠, **P5.3** 🟢, **P5.4** 🟢, **P5.5** 🟠, **P5.6** 🟢, **P5.7** 🟠 — фаза
  P5 **не терминальна** (1 item впереди: P5.8).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Внутри P5 порядок items совпадает с
  нумерацией: следующий — **P5.8** (доки + приёмочный прогон фазы, `implementation/low plan-exec`,
  новых решений нет).
- **P5.8 предусловие**: Completion Notes P5.1–P5.7 (все закрыты).
- **`## Phase Handoff` фазы P2 не собран** («—») — висящий bookkeeping-хвост:
  `/task:plan-close 2026.07.31-SYS-INTEGRITY P2`, продолжение P5 не блокирует.
- **Прочие follow-up без изменений**: `ExtractionOrchestrator` теряет семь старых полей паспорта
  (P5.5, владелец не назначен); `EstimateValidator::bcadd()` научная нотация (P5.4);
  `RerunRangeAction` не вызывает `replaceResources()` (P5.1/P5.2); пять колонок `source_*` без
  писателя (B-4); `RerunRangeAction` work_type_id preserve-ветка без отдельного теста (P5.5);
  систематический timeout `EST-XLSX-KS2-001` на 150-секундном лимите (P5.7, новое).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/06-estimate-completeness.md` — утверждённое
  распределение: таблицы A/B/C, индексы, отложенные, вердикты D46, точный вход P5.5 и P5.6.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-eval-baseline.md` — числа baseline (`EvalRun #22`,
  запись сохранена, сам прогон в БД больше не существует), критерий приёмки P5.7.
- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-eval-run.md` — контрольный прогон P5.7: числа, ручной
  повтор формулы непрегрессии, вердикт, расход, измеримость.
- `phases/P5.md` — Phase Status, Completion Notes P5.1–P5.7.
- `roadmap.md` — очередь фаз (D63) и гейты владельца по items.

**Open risks:**

- **Систематический (дважды подряд) timeout `EST-XLSX-KS2-001`** на 150-секундном лимите
  `AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (найдено P5.7) — эталон, прошедший baseline без единого
  timeout, упал в обоих заходах P5.7. Кандидат отдельного fix-item (персистентно поднять лимит или
  специфично для тяжёлых row-range окон), владелец не назначен.
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (найдено
  P5.7, тот же класс, что уже известный workaround про `prc_ai_fixtures`, но шире) — любой item,
  гоняющий `migrate:fresh --seed` и затем `estimate:eval:run`/`estimate:gold:import`, обязан
  восстановить датасет `estimate:gold:import --prepare` между ними.
- **`ExtractionOrchestrator` теряет семь старых полей паспорта** (находка P5.5) — в бою
  `calc_method`/`labor_hours_*`/`document_*`/`period_*` всегда `null`; кандидат отдельного
  fix-item, владелец не назначен.
- **`EstimateValidator::bcadd()` не нормализует научную нотацию** (найдено P5.4) — падает на любом
  документе, где LLM выдаст `total_price_curr`/аналог в форме `NeM`.
- **Пять колонок `prc_estimates.source_*` остаются без писателя** — донор назначил его item'ам,
  которые закрылись без реализации; в этом плане владельца нет (P6 — про диски файлов).
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife*`, `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений; состав снимать
  `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …`; для tinker-прогонов на `agelto_test` нужны явные
  `-e DB_DATABASE=agelto_test -e DB_USERNAME=agelto -e DB_PASSWORD=agelto_dev_local`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** те же, что в предыдущем handoff'е (см. Open risks) + новый —
  `estimate:gold:import --prepare` после `migrate:fresh` на dev-БД (восстанавливает
  `prc_ai_dataset_examples`, шире прежнего `estimate:fixtures:import`).
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close`); писатель `verified_at`/`verified_by`
  (долг вне плана, D89 донора); писатель пяти колонок `source_*` (кандидат отдельного fix-item);
  прокидка семи старых полей паспорта в `ExtractionOrchestrator` (найдено P5.5); материализация
  не-`Item` строк (решение владельца «не материализовать»); тип нормы аксессором из `code`
  (потребитель не назван); типизированный носитель `RowType` (OQ-6); переписывание текстов промтов
  сверх дедупликации (OQ-7); партиционирование `prc_events`/`llm_calls`; `RerunRangeAction`
  `replaceResources()`; нормализация научной нотации в `EstimateValidator::bcadd()` (P5.4);
  тест на preserve-ветку `work_type_id` в `RerunRangeAction` (P5.5); персистентный подъём
  `AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (P5.7, новое).
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
