# HANDOFF — 2026-07-28 — after P6.2 (🔴 ESCALATION — доступная линейка моделей ≠ линейка методики)

**Next:** /task:plan-design 2026.07.26-EST-TRAIN P6.2

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — открытое design-решение (переутвердить методику под 3.x или чинить доступ к 2.5) |
| Context | NEW SESSION — шаг-не-item |
| Суть | Решить, на какой линейке моделей боевой прогон P6.2 вообще возможен, и синхронизировать методику P6.1 (`research/11-format-bench-method.md`) с решением, ПЕРЕД повторной попыткой P6.2 |

```
/task:plan-design 2026.07.26-EST-TRAIN P6.2
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; **P6.2 🔴
Blocked — см. ESCALATION ниже**; P6.3 `⬬ Not started`, ждёт P6.2). **P7.1–P7.5 — 🟠 Done with
deviations** (терминальны), P7.6 ждёт терминальности P6 (phase-first). P8 (0/5), P9 (0/6) — не начаты.

**P6.2 (заход 2026-07-28, sonnet/medium, plan-run) — хронология этой сессии:**
1. Цены и лимиты Gemini перепроверены на 2026-07-28 (ai.google.dev): `gemini-2.5-flash`
   input/output/cache — `0.30`/`2.50`/`0.03` $ за 1M токенов, страница PDF — `258` токенов,
   лимит `50 МБ`/`1000` страниц, EOL линейки 2.5 — `2026-10-16`. Совпало с
   `config('ai.capabilities')` и `research/11-format-bench-method.md` — расхождений нет.
2. Апрув цены получен: владелец (Dmitry Vostrikov) подтвердил предсказанную нижнюю границу
   input-объёма **534 118 микро-$ ≈ $0.53** (таблица «ИТОГО предсказано»,
   `research/11-format-bench-method.md`), с пониманием, что это НЕ полная цена прогона.
3. Гейт доступа СНАЧАЛА не прошёл: минимальный боевой вызов через
   `PrismGeminiProvider::complete()` из `docker exec agelto_app` дважды упал `cURL error 28:
   Connection timed out` на POST `generateContent` (при быстром `GET /` того же хоста) — сетевая
   проблема dev-контейнера (не РФ-блокировка как таковая: с голого хоста тот же POST проходил
   стабильно). Починено владельцем — поднят authenticated HTTP CONNECT proxy (`3proxy`) на его VDS
   в Хельсинки, прописан `AI_GEMINI_PROXY` в `.env`. После починки минимальный боевой вызов проходит.
4. **НОВАЯ находка, эскалация.** Минимальный боевой вызов на `gemini-2.5-flash` вернул `404
   NOT_FOUND: This model models/gemini-2.5-flash is no longer available to new users`. Проверка по
   всей линейке 2.5 дала тот же результат: `gemini-2.5-flash-lite` — 404 (та же причина),
   `gemini-2.5-pro` — 404 (та же причина). `ListModels` при этом ЛИСТИТ `gemini-2.5-flash` в числе
   доступных имён — расхождение между листингом и фактическим `generateContent` специфично
   аккаунту/ключу («new users» ограничение Google, не баг клиента). Проверены модели-преемники:
   `gemini-3.5-flash` — `200 OK`, `gemini-3.1-flash-lite` — `200 OK`. Методика P6.1
   (`research/11-format-bench-method.md`) и предсказание цены посчитаны ЦЕЛИКОМ под
   `gemini-2.5-flash` (маршрут `estimate`/`extract_rows`, priority 0) — этим ключом она физически
   не исполнима. Смена модели маршрута — вне Scope P6.2 (EST-AI D4, маршрутизация моделей; Code
   Guidance P6.2 прямо запрещает переключать модель самовольно) и не косметическая правка: OQ-4 уже
   предупреждал, что на линейке 3.x меняются САМИ ПРАВИЛА тарификации PDF (нативный текст из PDF не
   тарифицируется как страничные токены) — то есть предсказание `page_count × 258` для
   PDF-подобных вариантов методики НЕ переносится на 3.x механическим переименованием модели, а
   должно быть пересчитано и, возможно, само относительное преимущество представлений input
   изменит знак. Item переведён в `🔴 Blocked`, `Escalation Needed: yes` (`phases/P6.md` P6.2 —
   blockquote эскалации, Completion Notes, Pending Work). Ни одна ячейка `ai:bench:formats` не
   запускалась — деньги владельца потрачены только на диагностические вызовы (`maxTokens=16`/`0`,
   копеечные, не приближаются к апрувленной сумме).

**Remaining:** P6.2 ждёт `plan-design` (пересчёт методики P6.1 под доступную линейку моделей ИЛИ
решение владельца о доступе к 2.5-линейке — другой ключ/проект/тариф Google). P6.3 ждёт P6.2. P7.6
ждёт терминальности P6 (phase-first). P8 (0/5), P9 (0/6) — не начаты.

**Open risks:**
- **🔴 БЛОКЕР (эта эскалация): методика P6.1 привязана к недоступной модели.**
  `gemini-2.5-flash`/`-lite`/`-pro` недоступны активному ключу владельца («no longer available to
  new users», проверено боевым вызовом 2026-07-28), доступны только 3.x-модели
  (`gemini-3.5-flash`, `gemini-3.1-flash-lite`). До решения `plan-design` P6.2/P6.3/P7.6 не
  исполнимы по текущему ТЗ.
- **D122 («доступ локальный, без РФ-egress проблемы») требовал сетевого фикса на практике** —
  POST на `generateContent` из dev-контейнера таймаутился (не с голого хоста); исправлено проксёй
  на VDS владельца (`AI_GEMINI_PROXY`). Инфраструктурный факт, не дефект плана — упомянуто для
  памяти следующего исполнителя (сеть теперь рабочая, это НЕ текущий блокер).
- **`holdout` из двух кодов — сигнализация, а не статистика (D121).** `overall.f1` на нём на 71 %
  определяется одним эталоном (`EST-XLSX-LSR-001`, 155 из 219 строк), регресс мелких эталонов ловит
  порог (г), а не (б); `holdout` крупнее `tune` по строкам (219 против 199); два крупнейших
  `xlsx`-эталона критик не увидит. Лечение — рост числа эталонов с ДЕТЕРМИНИРОВАННЫМ gold (путь
  D42/D117), это работа вне P7.5 с гейтом владельца и календарным окном. Раздел «Ограничения
  разбиения» (`research/14-prompt-tuning-protocol.md` §5) обязателен к прочтению ПЕРЕД P7.6/P9.
- **`research/10-gold-corpus-report.md` дискредитирован как источник состава пула:** отчёт снят на
  ТЕСТОВОЙ БД и показывает два несуществующих кода. Источник истины по пулу — реестр
  `prc_ai_fixtures`. Перегенерация отчёта на живой БД владельца не назначена — кандидат в
  follow-up item.
- **dev-контейнер: bind-mount не даёт `www-data` право записи в `resources/ai/prompts/**`**
  (владелец файлов — хостовый uid 1000; прод-образ делает `chown -R www-data:www-data
  /var/www/html` при сборке). `www-data` добавлен в группу `hostgrp` ВНУТРИ уже запущенного
  контейнера `agelto_app` — не переживёт пересборку.
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
  добавил ещё 13 записей известного паттерна, долг тот же по классу.
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 (доступ) — формально `Resolved → D122`, сетевой
гейт починен `AI_GEMINI_PROXY`. **НОВОЕ:** линейка 2.5 недоступна ключу владельца — кандидат в
новый OQ, решается `plan-design` (см. эскалацию P6.2 выше), не переоткрыт как правка D122
самовольно. OQ-4 (переход на 3.x-моделей) — из «Exploration» фактически стал БЛОКЕРОМ прямо сейчас,
не будущим риском: линейка 2.5 недоступна УЖЕ сегодня, а не с 2026-10-16 EOL.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D122, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P6.md` (P6.2 — ESCALATION, Completion Notes, Pending Work) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.5 Completion Notes/Pending Work; P7.6
детализирован до DoR) · `plans/2026.07.26-EST-TRAIN/research/11-format-bench-method.md` (методика,
критерий, «ИТОГО предсказано» — требует пересчёта под доступную линейку) ·
`plans/2026.07.26-EST-TRAIN/research/14-prompt-tuning-protocol.md` ·
`plans/2026.07.26-EST-TRAIN/research/18-holdout-split-rule.md` ·
`plans/2026.07.26-EST-TRAIN/findings/P7.5-splits-corpus-fact-2026-07-28.md` ·
`estimates/eval-splits.json` · `docs/specs/v2/08-estimate-extraction.md` §5.1/§6.1/§8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3 закрыт → D122, сеть починена; OQ-4 фактически
эскалирован находкой P6.2 2026-07-28) · `plans/2026.07.26-EST-TRAIN/roadmap.md` ·
`plans/2026.07.26-EST-TRAIN/journal.jsonl`.
