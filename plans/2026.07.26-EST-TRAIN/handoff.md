# HANDOFF — 2026-07-28 — after P7.5

**Next:** waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P7.6

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписано Routing (P7.6, implementation/medium) |
| Context | continue (/clear) — ручной item |
| Суть | Гейт заблокирован OQ-3 (доступ к реальному провайдеру + деньги владельца) — item не запускается `plan-exec`/`plan-run` до разрешения гейта владельцем |

```
waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P7.6
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; P6.2/P6.3
ждут OQ-3, D53). **P7.1–P7.5 — 🟠 Done with deviations** (терминальны). P7 итого: 0/6 🟢, фаза 🟡
In progress — единственный незакрытый item фазы `P7.6`, гейтированный OQ-3.

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

**Remaining:** P7.6 — гейт OQ-3 + два апрува владельца (цена цикла, конкретный патч), ждёт P7.5
(готово) и разрешения OQ-3. P6.2/P6.3 — тот же гейт OQ-3. P8 (0/5), P9 (0/6) — не начаты.

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
- **`waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P6.2`** (D53, Decision pending у
  владельца) — P6.2/P6.3/P7.6 не исполняются.
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

**Workarounds / Deferred / Open questions:** OQ-3 — единственный гейт владельца P6/P7.6. OQ-4
(переход на 3.x-модели) — Exploration, не блокирует. compose-CLI сессии падает на интерполяции
`MINIO_ROOT_PASSWORD` — команды гонялись `docker exec agelto_app …` (тот же контейнер).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D121, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.5 Completion Notes/Pending Work; P7.6
детализирован до DoR) · `plans/2026.07.26-EST-TRAIN/research/14-prompt-tuning-protocol.md` ·
`plans/2026.07.26-EST-TRAIN/research/18-holdout-split-rule.md` ·
`plans/2026.07.26-EST-TRAIN/findings/P7.5-splits-corpus-fact-2026-07-28.md` ·
`estimates/eval-splits.json` · `docs/specs/v2/08-estimate-extraction.md` §6.1/§8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3/OQ-4) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
