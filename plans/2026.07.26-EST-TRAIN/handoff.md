# HANDOFF — 2026-07-28 — after P7.1

**Next:** ЗАПУСК ВРУЧНУЮ: frontier/high

| Параметр | Значение |
|:--|:--|
| Model | fable\|opus |
| Thinking | high — тексты пяти пошаговых промтов против корпуса, открытые решения по формулировкам |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P7.1 закрыт 🟠 (сквозные промты v2, словари, research/13 «Сквозные правила»). Следующий активный item — **P7.2** (`Exec = manual`, `frontier/high`, `plan.md` §3): пошаговые промты screening:classify / extraction:passport / rows / totals / discovery против корпуса и расширенного контракта P4.5 |

```
Открой plans/2026.07.26-EST-TRAIN/phases/P7.md, раздел ### P7.2 — Пошаговые промты: скрининг,
паспорт, строки, итоги, discovery. Исполни item строго по его Intent/Scope Included/Files/
Implementation Rules/Validation/Deliverables. Прочитай Required Reads item'а (включая
research/13-prompt-corpus-rules.md — что уже сказано сквозными правилами P7.1) и Phase Context P7
перед началом. По завершении — Completion Notes, Pending Work, Known Deviations, Escalation
Needed, гейт закрытия (plan-lint --baseline HEAD, счёт с атрибуцией) и запись в journal.jsonl
(--command plan-run --actor frontier/high --result <статус>), затем перезапиши handoff.md по
канону `plan-protocol` §8.
```

Альтернатива (владелец разрешил OQ-3 первым): `/task:plan-run 2026.07.26-EST-TRAIN P6.2` под
`implementation/medium` — см. `waiting:`-строку в Open risks ниже.

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; P6.2/P6.3
ждут OQ-3, D53). **P7.1 — 🟠 Done with deviations** (P7: 0/6 🟢, 1 item терминален): три сквозных
промта доведены против корпуса (`version` 1→2: global — десятичная запятая; extraction:global —
7 правил форм реального документооборота; screening:global — титул КС-2, скан, блок безопасности
§7); +14 синонимов `#[Alias]` (`EstimateHeaderMap`/`ResourceType`) синхронно §2.1/§2.2 спеки;
свидетельский свод `research/13-prompt-corpus-rules.md` (11 правил + 15 синонимов, прочерков в
колонке свидетеля 0). Validation: PromptComposition 4 passed / PromptSeeder 5 passed / EvalMetrics
6 passed; `ai:prompts:sync --check` — дрейфа нет; phpstan OK; pint PASS. Item-коммит `9e4ce2e`.

**Remaining:** P7.2–P7.6 (P7.2–P7.4 — manual; P7.5 — plan-exec; P7.6 гейт OQ-3 + два апрува
владельца). P6.2/P6.3 — гейт OQ-3. P8 (0/5), P9 (0/6) — не начаты.

**Open risks:**
- **`waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P6.2`** (D53, Decision pending у
  владельца) — P6.2/P6.3/P7.6 не исполняются; план законно продолжается P7.2–P7.5 на базовой
  политике представлений.
- **Словари `#[Alias]` не доезжают до модели на боевых вызовах** (находка P7.1, Pending Work п.2):
  `compose()` получает только Response-класс шага — исходники `EstimateHeaderMap`/`ResourceType`/
  вложенных DTO в системную инструкцию не включаются (`RowRangeChunkSource.php:145`,
  `WindowedChunkSource.php:224`, `ScreeningProcessor.php:148`); посылка D58 «синоним УЖЕ доезжает»
  на текущем коде неверна. Нужен fix-item через `plan-design` (механика вне Scope P7.1/P7.2).
- **Словарь `ModifierKind` не сделан** (research/08 §3 адресует P7.1, но enum/§2.3 вне Files
  item'а) — ре-дизайн P7.1 follow-up либо D# с включением в другой item.
- Семантический дрейф `row_index` ↔ `source_position_number` в `EstimateHeaderMap` (v1-алиас
  «№ п/п» противоречит синтетической семантике row_index; состав полей — P4.5/D33).
- **БЛОКЕР `doc_type` (P5.4, унаследован):** 13/13 негативов корпуса без `doc_type` —
  `false_positive_rate` скрининга не измерим на реальных данных; решение владельца.
- **P3 OOM на `--prepare` (P5.5, унаследован):** `EST-XLS-KS2-001`/`EST-XLSX-LSR-002` крашат
  `SpreadsheetStrategy` полным прогоном; поэлементный `--code` — обход.
- `soffice` PATH-квирк (P5.5, унаследован) — в текущем контейнере работает (P6.1).
- Посторонний незакоммиченный дифф в дереве (не мой, не трогал): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` растёт вне items (унаследованный кандидат в отдельный item, P5.3).
- plan-lint: 0 ERROR / 10 WARN; 2 WARN новые от перехода P7 в 🟡 — ложноположительные срабатывания
  линтера того же класса, что у P5/P6 (literal маркера в DoR-чекбоксе `phases/P7.md:108`, слово
  «workflow» в пункте «оркестрация не заявляется»); не чинились правкой ТЗ фазы сознательно.
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 — единственный гейт владельца P6/P7.6
(доступ к провайдеру + апрув цены). OQ-4 (переход на 3.x-модели) — Exploration, не блокирует.
Validation #4 P7.1 в буквальном прочтении недостижима для коротких v1-синонимов-подстрок
(`поз`⊂«позиций», `ФССЦ`⊂«ФССЦпг», `НР`/`СП` в v1-текстах) — зафиксировано в Known Deviations
P7.1; для P7.2 та же проверка читается по добавленным формулировкам.

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D118, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1 Completion Notes/Pending Work; P7.2–P7.6
детализированы до DoR) · `plans/2026.07.26-EST-TRAIN/research/13-prompt-corpus-rules.md` ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3/OQ-4) ·
`docs/specs/v2/08-estimate-extraction.md` §2/§6/§7 · `plans/2026.07.26-EST-TRAIN/roadmap.md` ·
`plans/2026.07.26-EST-TRAIN/journal.jsonl`.
