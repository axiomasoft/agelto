# HANDOFF — 2026-07-28 — after P7.5

**Next:** ЗАПУСК ВРУЧНУЮ: sonnet/medium — P6.2 (первый item, гейтированный доступом к провайдеру;
OQ-3 снят D122, но P6.2 несёт СВОЙ отдельный гейт — апрув владельцем предсказанной цены прогона,
первый шаг Implementation Rules item'а)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписано Routing (P6.2, implementation/medium, Review full) |
| Context | continue (/clear) — ручной item |
| Суть | Боевой прогон `ai:bench:formats` на подвыборке P6.1 через реальный провайдер (OQ-3 больше не блокирует), гейт цены — первым шагом item'а |

```
Исполни P6.2 плана 2026.07.26-EST-TRAIN («Боевой прогон на подвыборке и отчёт с цифрами») строго
по его ТЗ в plans/2026.07.26-EST-TRAIN/phases/P6.md.

ПРЕДУСЛОВИЕ (изменилось только оно): OQ-3 разрешён владельцем 2026-07-28 (D122,
plan.md §5 и open-questions.md — закрытый блок) — `GEMINI_API_KEY` в `.env` заполнен, прокси
(`AI_GEMINI_PROXY`) не требуется. Первый шаг Implementation Rules item'а (проверка доступа) можно
считать пройденным ключом; ВТОРОЙ гейт item'а — апрув владельцем предсказанной цены КОНКРЕТНОГО
прогона (таблица «ИТОГО предсказано» из research/11-format-bench-method.md) — D122 НЕ снимает,
его нужно получить явно ПЕРЕД первым боевым вызовом, как и предписано ТЗ.

ВХОД (порядок чтения): 1) plans/2026.07.26-EST-TRAIN/plan.md — Context, Execution Rules, §3
Routing (строка P6.2), Status Board 2) plans/2026.07.26-EST-TRAIN/handoff.md (этот файл)
3) plans/2026.07.26-EST-TRAIN/phases/P6.md → Phase Context + item P6.2 целиком 4) Required Reads
P6.2 в указанном там порядке (research/11-format-bench-method.md целиком; plan.md §5 D3/D53/D54;
open-questions.md; findings/rag-P6-tokens-2026-07-27.md и findings/rag-2026-07-26.md;
docs/specs/v2/08-estimate-extraction.md §5.1).

СКОУП — строго Scope Included P6.2 (перепроверка цен/лимитов на дату прогона, апрув цены,
боевой прогон `ai:bench:formats` по ЗАФИКСИРОВАННОЙ P6.1 подвыборке, машинный артефакт, отчёт
research/12-format-benchmark.md с паспортом прогона). НЕ ТРОГАТЬ: тексты промтов (P7), критерий
победителя (зафиксирован P6.1 — расхождение = новый D#), `config('ai.capabilities')` (D26),
harness (доработка = дефект P6.1). Апрув цены не получен → item НЕ начинается, handoff остаётся
`waiting:`.
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; P6.2/P6.3
`⬜ Not started` — OQ-3 снят D122, ждут только гейта цены + запуска). **P7.1–P7.5 — 🟠 Done with
deviations** (терминальны). P7 итого: 0/6 🟢, фаза 🟡 In progress — единственный незакрытый item
фазы `P7.6`, тот же остаток гейта (цена конкретного прогона + два апрува D8), ждёт терминальности
P6 (phase-first).

P7.5 закрыт по ОБНОВЛЁННОМУ ТЗ (эскалация 2026-07-28 снята заходом `plan-design` — D119–D121):
`estimates/eval-splits.json` сгенерирован `ai:eval:run --write-splits` на живой БД, состав совпал
байт-в-байт с закреплённым (`holdout = [EST-XLSX-KS2-001, EST-XLSX-LSR-001]`, `tune` — четыре
кода), три инварианта Validation #4 подтверждены; `prompt_version_set` прогона собирается из
фактических `prc_ai_llm_calls`; `--split=tune|holdout|all` — семантика дополнения (D120), дефолт
`all` не меняет прежнее поведение (тест); `ai:eval:compare` + `EvalRunComparator` — критерий (а)–(г)
D63 дословно, отказ сравнивать несравнимые прогоны; `research/14-prompt-tuning-protocol.md`
написан целиком, включая раздел «Ограничения разбиения» (D121). Все девять обязательных
Pest-кейсов Code Guidance зелёные + Validation #5 (`ai:eval:compare` через artisan). Item закрыт
🟠 Done with deviations — материальное отклонение: диф вне `Files` (`config/ai.php` +
`ai.fixtures.splits_path`, `phpstan-baseline.neon` +13 записей известного паттерна отсутствующих
`@property` на `EvalRun`/`EvalResult`), детали см. `phases/P7.md` P7.5 Completion Notes/Known
Deviations.

**Remaining:** P6.2 — следующий item (гейт OQ-3 снят, остаётся апрув цены конкретного прогона). P6.3
— ждёт P6.2. P7.6 — гейт цены + два апрува владельца (цена цикла, конкретный патч), ждёт
терминальности P6 (phase-first) — Scope готов (P7.1–P7.5 закрыты). P8 (0/5), P9 (0/6) — не начаты.

**Open risks:**
- **`holdout` из двух кодов — сигнализация, а не статистика (D121).** `overall.f1` на нём на 71 %
  определяется одним эталоном (`EST-XLSX-LSR-001`, 155 из 219 строк), регресс мелких эталонов ловит
  порог (г), а не (б); `holdout` крупнее `tune` по строкам (219 против 199); два крупнейших
  `xlsx`-эталона критик не увидит. Лечение — рост числа эталонов с ДЕТЕРМИНИРОВАННЫМ gold (путь
  D42/D117: разметка профиля переводит `llm_assisted` → `deterministic`), это работа вне P7.5 с
  гейтом владельца и календарным окном. Раздел «Ограничения разбиения»
  (`research/14-prompt-tuning-protocol.md` §5) обязателен к прочтению ПЕРЕД P7.6/P9 —трактовать
  вердикт `accept` как «качество выросло» без него — ошибка.
- **`research/10-gold-corpus-report.md` дискредитирован как источник состава пула:** отчёт снят на
  ТЕСТОВОЙ БД и показывает два несуществующих кода (`EST-XLSX-GOOD-001`, `EST-XLSX-BROKEN-001`).
  Источник истины по пулу — реестр `prc_ai_fixtures`. Перегенерация отчёта на живой БД владельца не
  назначена — кандидат в follow-up item.
- **OQ-3 разрешён (D122), но P6.2/P7.6 несут ОТДЕЛЬНЫЙ гейт** — апрув владельцем предсказанной
  цены КОНКРЕТНОГО прогона, первый шаг Implementation Rules каждого item'а; наличие ключа не
  заменяет апрув суммы.
- **dev-контейнер: bind-mount не даёт `www-data` право записи в `resources/ai/prompts/**`**
  (владелец файлов — хостовый uid 1000; прод-образ делает `chown -R www-data:www-data
  /var/www/html` при сборке — там `ai:prompts:apply` пишет штатно). `www-data` добавлен в группу
  `hostgrp` (gid 1000) ВНУТРИ уже запущенного контейнера `agelto_app` — не переживёт пересборку.
- **Долг: `PromptsSuggestCommand` дублирует резолв `Analysis`/gold-файла из `EvalDiffCommand`**
  (~40 строк) — кандидат в отдельный item/`plan-design`.
- **Долг D54 (P7.1+P7.2):** версии восьми промтов подняты при несуществующем ADR — строка «набор
  промтов изменился → перезамер победителя» обязана появиться в ADR при его создании (P6.3).
- **Словари `#[Alias]` не доезжают до модели на боевых вызовах** (Pending P7.1 №2) — fix-item через
  `plan-design` вне Scope P7.1/P7.2.
- **Словарь `ModifierKind` не сделан** (Pending P7.1 №1) — ре-дизайн follow-up.
- **Позиционное сопоставление смет (`ordinal`) — граница `EvalMetrics`/`EstimateDiffReport`**
  (Pending P7.3) — не реализовано.
- Семантический дрейф `row_index` ↔ `source_position_number` в `EstimateHeaderMap` (v1-алиас).
- **БЛОКЕР `doc_type` (P5.4, унаследован):** `false_positive_rate` скрининга не измерим.
- **P3 OOM на `--prepare` (P5.5, унаследован).**
- `soffice` PATH-квирк (P5.5, унаследован) — в текущем контейнере работает.
- Посторонний незакоммиченный дифф в дереве (не мой, не трогал): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` растёт вне items (унаследованный кандидат в отдельный item, P5.3) — P7.5
  добавил ещё 13 записей известного паттерна (см. Known Deviations выше), долг тот же по классу.
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 — `Resolved → D122` (2026-07-28, см.
`open-questions.md` закрытый блок): доступ к провайдеру больше не гейт; апрув цены прогона —
операционный гейт P6.2/P7.6, остаётся в силе. OQ-4 (переход на 3.x-модели) — Exploration, не
блокирует. compose-CLI сессии падает на интерполяции `MINIO_ROOT_PASSWORD` — команды гонялись
`docker exec agelto_app …` (тот же контейнер).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D122, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.5 Completion Notes/Pending Work; P7.6
детализирован до DoR) · `plans/2026.07.26-EST-TRAIN/phases/P6.md` (P6.2 — следующий item) ·
`plans/2026.07.26-EST-TRAIN/research/14-prompt-tuning-protocol.md` ·
`plans/2026.07.26-EST-TRAIN/research/18-holdout-split-rule.md` ·
`plans/2026.07.26-EST-TRAIN/findings/P7.5-splits-corpus-fact-2026-07-28.md` ·
`estimates/eval-splits.json` · `docs/specs/v2/08-estimate-extraction.md` §6.1/§8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3 закрыт → D122; OQ-4 открыт) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
