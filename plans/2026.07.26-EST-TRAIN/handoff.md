# HANDOFF — 2026-07-28 — after P6.1

**Next:** ЗАПУСК ВРУЧНУЮ: sonnet/medium — P6.2 (гейт доступа больше не блокер; апрув цены получен под
СТАРУЮ модель и НЕДЕЙСТВИТЕЛЕН — нужен новый апрув на $2.67 перед первым боевым вызовом)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписано Routing (P6.2, implementation/medium, Review full) |
| Context | continue (/clear) — ручной item |
| Суть | Боевой прогон `ai:bench:formats` на подвыборке P6.1 через `gemini-3.5-flash` (маршрут переключён D123) — первый шаг: получить у владельца НОВЫЙ апрув цены ($2.67, `research/11` «ИТОГО предсказано»), затем прогнать |

```
Исполни P6.2 плана 2026.07.26-EST-TRAIN («Боевой прогон на подвыборке и отчёт с цифрами») строго
по его ТЗ в plans/2026.07.26-EST-TRAIN/phases/P6.md.

ПРЕДУСЛОВИЕ (изменилось с прошлого захода): реопен P6.1 (D123, 2026-07-28) переключил активный
маршрут estimate/extract_rows с недоступной линейки gemini-2.5 на gemini-3.5-flash
(AiRoutingSeeder + config('ai.capabilities'), применено к БД). Методика
research/11-format-bench-method.md пересчитана под новую модель — «ИТОГО предсказано» теперь
2 670 543 микро-$ ≈ $2.67 (было $0.53 под 2.5-flash). Гейт доступа больше не блокер (прокси
владельца на VDS работает, боевой вызов на 3.x-моделях подтверждён 200 OK). ВТОРОЙ гейт item'а —
апрув владельцем НОВОЙ предсказанной цены ($2.67) — это ОТДЕЛЬНЫЙ, новый апрув: старый апрув на
$0.53 относился к другой модели и в силе не остаётся. Также учти предупреждение в самой методике:
предсказание для PDF-подобных вариантов (`page_count × 258`) — консервативный верхний потолок под
2.5-специфичную константу, харнесс НЕ пересчитан под 3.x-тарификацию PDF (нативный текст бесплатен
на 3.x) — реальные цифры даст только сам боевой прогон.

ВХОД (порядок чтения): 1) plans/2026.07.26-EST-TRAIN/plan.md — Context, Execution Rules, §3
Routing (строка P6.2), Status Board, D123 (§5) 2) plans/2026.07.26-EST-TRAIN/handoff.md (этот файл)
3) plans/2026.07.26-EST-TRAIN/phases/P6.md → Phase Context + item P6.2 целиком (включая
blockquote о разрешённой эскалации) 4) Required Reads P6.2 в указанном там порядке
(research/11-format-bench-method.md ЦЕЛИКОМ, включая пересчитанный раздел «Предсказанный объём и
цена»; plan.md §5 D3/D53/D54/D123; open-questions.md; findings/rag-P6-tokens-2026-07-27.md и
findings/rag-2026-07-26.md; docs/specs/v2/08-estimate-extraction.md §5.1).

СКОУП — строго Scope Included P6.2 (перепроверка цен/лимитов на дату прогона — сверить ЕЩЁ РАЗ,
если сессия P6.2 идёт другим днём, чем этот реопен; апрув НОВОЙ цены; боевой прогон
`ai:bench:formats` по ЗАФИКСИРОВАННОЙ P6.1 подвыборке — состав шести эталонов НЕ менялся; машинный
артефакт; отчёт research/12-format-benchmark.md с паспортом прогона, включая модель gemini-3.5-flash
в паспорте). НЕ ТРОГАТЬ: тексты промтов (P7), критерий победителя (зафиксирован P6.1, не менялся
реопеном), harness (доработка формулы под 3.x-тарификацию PDF — Pending Work P6.1, отдельный
follow-up, НЕ делать по месту в P6.2). Апрув НОВОЙ цены не получен → item НЕ начинается, handoff
остаётся waiting:.
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 0/3 (P6.1 🟠 Done with
deviations — реопенен и пересчитан под gemini-3.x, D123; P6.2 `⬜ Not started`, P6.3 ждёт P6.2).
**P7.1–P7.5 — 🟠 Done with deviations** (терминальны), P7.6 ждёт терминальности P6 (phase-first).
P8 (0/5), P9 (0/6) — не начаты.

**P6.1 (реопен 2026-07-28, opus/high, plan-design, D123):** Эскалация P6.2 вскрыла: линейка
`gemini-2.5-*` недоступна ключу владельца целиком («404 no longer available to new users» на
`flash`/`flash-lite`/`pro` — боевой вызов подтвердил после починки сетевого гейта; ListModels при
этом её листит — расхождение метаданных с фактом; RAG:✅ Perplexity/ai.google.dev 2026-07-28:
признанный Google-баг для НОВЫХ GCP-проектов, не зависит от типа ключа/пересоздания проекта).
Владелец решил в чате: переходить на 3.x сейчас (не ждать EOL 2026-10-16) и чинить ВЕСЬ боевой
маршрут (не только P6) тем же заходом. Сделано: `AiRoutingSeeder` переключён на преемников,
УЖЕ задокументированных D27/config (`extract_*` → `gemini-3.5-flash`, `screening`/`discovery`/
`prompt_critic` → `gemini-3.1-flash-lite`, эскалация → `gemini-3.1-pro-preview`), применено к БД
(`db:seed --class=AiRoutingSeeder`); `config('ai.capabilities')` — три новые записи, цены сверены
`ai.google.dev/gemini-api/docs/pricing` 2026-07-28; повторный сухой прогон
(`bench-formats-dry-2026-07-28-v2.json`, 42 ячейки, `LlmCall::count()` 0/0);
`research/11-format-bench-method.md` пересчитан — «ИТОГО предсказано» теперь $2.67 (было $0.53),
состав подвыборки/критерий победителя НЕ менялись. Регресс зелёный: `AiRoutingSeeder`,
`BenchFormats`, `Extraction`, `Screening`, `AiRouteResolver`, `CostReport`, `EvalRun`,
`EvalCompare`, `PromptSuggest` — все PASS; `phpstan`/`pint` — чисто. Item закрыт 🟠 Done with
deviations: материальное отклонение — харнесс (`FormatBenchRunner::258`/страница) НЕ пересчитан под
3.x-тарификацию PDF (нативный текст на 3.x бесплатен, тарифицируется только IMAGE-модальность по
тайл-формуле) — предсказание для PDF-вариантов теперь консервативный верхний потолок, не
калиброванное число; точная калибровка — Pending Work / follow-up item, вне бюджета реопена.
Коммиты: `3aedd8a` (код: `AiRoutingSeeder`, `config/ai.php`, `AiRoutingSeederTest`).

**Remaining:** P6.2 — следующий item, гейт доступа снят, нужен НОВЫЙ апрув цены ($2.67). P6.3 —
ждёт P6.2. P7.6 — ждёт терминальности P6 (phase-first), Scope готов. Follow-up вне этого плана
(кандидат в отдельный item/`plan-design`): откалибровать `FormatBenchRunner` под tile-based
image-тарификацию PDF на 3.x (не блокер P6.2 — D3 всё равно требует боевого измерения, не догадки).

**Open risks:**
- **Follow-up: `FormatBenchRunner` не откалиброван под 3.x-тарификацию PDF** (см. P6.1 Known
  Deviations/Pending Work выше) — предсказание PDF-вариантов в `research/11` завышено, не точное.
  Не блокирует P6.2 (боевой прогон даст реальные цифры), но искажает раздел «расхождение
  предсказано ↔ фактически» будущего отчёта `research/12` — учесть при интерпретации.
- **Экономика маршрута выросла ~5× по input-цене** (`gemini-2.5-flash` 0.30 → `gemini-3.5-flash`
  1.50 $/1M) — это касается не только бенчмарка, а ВСЕГО боевого потока extraction. Владелец в
  курсе (решение о переходе принято явно), но стоит держать в уме при следующих `ai:cost-report`.
- **`holdout` из двух кодов — сигнализация, а не статистика (D121).** См. предыдущие заходы,
  `research/14-prompt-tuning-protocol.md` §5 — обязательно к прочтению ПЕРЕД P7.6/P9.
- **`research/10-gold-corpus-report.md` дискредитирован как источник состава пула** — источник
  истины по пулу — реестр `prc_ai_fixtures`. Перегенерация на живой БД не назначена.
- **dev-контейнер: bind-mount не даёт `www-data` право записи в `resources/ai/prompts/**`** —
  временный фикс группы `hostgrp` внутри контейнера не переживёт пересборку.
- **Долг: `PromptsSuggestCommand` дублирует резолв `Analysis`/gold-файла из `EvalDiffCommand`**
  (~40 строк) — кандидат в отдельный item/`plan-design`.
- **Долг D54 (P7.1+P7.2):** версии восьми промтов подняты при несуществующем ADR — строка «набор
  промтов изменился → перезамер победителя» обязана появиться в ADR при его создании (P6.3).
- **Словари `#[Alias]` не доезжают до модели на боевых вызовах** (Pending P7.1 №2) — fix-item.
- **Словарь `ModifierKind` не сделан** (Pending P7.1 №1) — ре-дизайн follow-up.
- **Позиционное сопоставление смет (`ordinal`)** (Pending P7.3) — не реализовано.
- Семантический дрейф `row_index` ↔ `source_position_number` в `EstimateHeaderMap` (v1-алиас).
- **БЛОКЕР `doc_type` (P5.4, унаследован):** `false_positive_rate` скрининга не измерим.
- **P3 OOM на `--prepare` (P5.5, унаследован).**
- `soffice` PATH-квирк (P5.5, унаследован) — в текущем контейнере работает.
- Посторонний незакоммиченный дифф в дереве (не мой, не трогал): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` растёт вне items — долг унаследован, не новый от этого захода.
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (доступ) — `Resolved → D122`, сеть починена
(прокси владельца). **Кандидат в новый OQ (не заведён формально — операционный факт, не design):**
линейка 2.5 может однажды снова заработать (Google обещал откат бага) — если это случится, вопрос
«возвращаться ли на 2.5 или остаться на 3.x» решает владелец новым D#, не тихим откатом сида
(закомментированные строки 2.5 в `AiRoutingSeeder` — точка отката). OQ-4 (переход на 3.x) —
фактически ЗАКРЫТ этим реопеном (переход уже произошёл, раньше EOL, а не после него).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D123, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P6.md` (P6.1 реопен/Completion Notes, P6.2 — следующий item) ·
`plans/2026.07.26-EST-TRAIN/research/11-format-bench-method.md` (пересчитанная методика/предсказание) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.5 Completion Notes/Pending Work; P7.6 до DoR) ·
`plans/2026.07.26-EST-TRAIN/research/14-prompt-tuning-protocol.md` ·
`plans/2026.07.26-EST-TRAIN/research/18-holdout-split-rule.md` ·
`estimates/eval-splits.json` · `docs/specs/v2/08-estimate-extraction.md` §5.1/§6.1/§8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` · `plans/2026.07.26-EST-TRAIN/roadmap.md` ·
`plans/2026.07.26-EST-TRAIN/journal.jsonl`.
