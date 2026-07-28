# HANDOFF — 2026-07-28 — after P7.5 (escalated)

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — Routing §3: `frontier/high` (design-решение, конфликт acceptance-критериев) |
| Context | NEW SESSION — шаг-не-item |
| Суть | Разрешить конфликт Validation #3 vs #4 в P7.5: правило отбора `holdout` (Implementation Rules) на факте `prc_ai_fixtures` (24 эталона, 23 кластера) даёт `holdout = []` (ни один кластер не несёт ≥2 `gold_source=deterministic`), а Validation #3 требует непустые `holdout`/`tune`. Нужно design-решение: другое правило отбора, снижение порога с явным D#, либо явное приятие пустого `holdout` на текущем корпусе с правкой Validation #3 |

```
/task:plan-design 2026.07.26-EST-TRAIN P7.5
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; P6.2/P6.3
ждут OQ-3, D53). **P7.1–P7.4 — 🟠 Done with deviations** (терминальны). **P7.5 — 🔴 Blocked**
(эскалация ДО начала реализации — конфликт ТЗ, кода/тестов не написано). P7 итого: 0/6 🟢, фаза
🟡 In progress (была бы 🔴, но нетерминальный item фазы уже стопорит phase-first — фаза НЕ
терминальна).

**Remaining:** P7.5 (design, следующий) — разрешить конфликт правила holdout. После этого — та же
задача (`/task:plan-exec 2026.07.26-EST-TRAIN P7.5`) с обновлённым ТЗ. P7.6 гейт OQ-3 + два апрува
владельца, ждёт P7.5. P6.2/P6.3 — гейт OQ-3. P8 (0/5), P9 (0/6) — не начаты.

**Open risks:**
- **НОВОЕ: P7.5 заблокирован конфликтом ТЗ.** Implementation Rules item'а: «в holdout уходит РОВНО
  ОДИН эталон из каждого кластера форм, у которого `gold_source=deterministic` — тот, чей код
  лексикографически меньше остальных ПОДХОДЯЩИХ в кластере; кластер, где такой эталон ровно один,
  целиком остаётся в tune». Validation #4 подтверждает эту же формулу буквально: «число кодов в
  holdout равно числу кластеров, имеющих ≥2 эталона с детерминированным gold». Проверено `tinker`
  на живой БД (`docker exec agelto_app php artisan tinker`): `prc_ai_fixtures` = 24 строки,
  `cluster_id` группирует их в 23 кластера; единственный кластер с >1 эталоном — `CL-01`
  (`EST-XLSX-KS2-001` deterministic + `EST-XLSX-KS2-002` llm_assisted) — несёт РОВНО ОДИН
  deterministic, не ≥2. Значит по правилу `holdout = []` — это напрямую противоречит Validation #3
  («непустые holdout и tune»). Implementation Rules и Code Guidance ЯВНО запрещают исполнителю
  «улучшать правило под удобство» (D22/D35/D42/D54-прецедент анти-подгонки) — самостоятельно
  расширять пул кандидатов (например, включать llm_assisted) или снижать порог нельзя. Решение —
  за `plan-design`: пороги/правило либо факт корпуса должны быть согласованы заново.
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
- `phpstan-baseline.neon` растёт вне items (унаследованный кандидат в отдельный item, P5.3).
- plan-lint не прогонялся этим item'ом (эскалация до кодовой мутации, нет диффа Scope для
  сверки — прогон бессмысленен до разрешения design-конфликта).
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 — единственный гейт владельца P6/P7.6. OQ-4
(переход на 3.x-модели) — Exploration, не блокирует. compose-CLI сессии падает на интерполяции
`MINIO_ROOT_PASSWORD` — команды гонялись `docker exec agelto_app …` (тот же контейнер).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.4 Completion Notes/Pending Work; P7.5 —
эскалация; P7.6 детализирован до DoR) · `docs/specs/v2/08-estimate-extraction.md` §6.1/§8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3/OQ-4) ·
`plans/2026.07.26-EST-TRAIN/roadmap.md` · `plans/2026.07.26-EST-TRAIN/journal.jsonl`.
