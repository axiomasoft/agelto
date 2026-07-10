# HANDOFF — 2026-07-10 — after P3.3

**Next:** `/task:plan-design DOMLANG P3.3` — разрешить конфликт Scope/Validation в item'е P3.3
(см. `phases/P3.md` P3.3 blockquote), затем `/task:plan-exec DOMLANG P3.3` заново.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — несущее решение по Scope (расширять ли хирургию фикстур на 5 доп. файлов) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Разрешить конфликт правил P3.3: Scope Included называет 4 вхождения `'Размещение извещения'`, Validation требует 0 по всему `tests/`, фактически их 9 в 8 файлах |

```
/task:plan-design DOMLANG P3.3
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 в работе (2/3, P3.3 заблокирован):
- 🟢 P3.1 — preflight impact-карты: все 6 gate-счётчиков сошлись, drift не найден.
- 🟢 P3.2 — атомарный ренейм: `source_number`→`registry_number`, `source_guid`→`registry_guid`,
  `source_status`→`source_stage` (`prc_procurements`, `prc_ignored_items` D19, `prc_status_mappings`
  D18) по списку `findings/P3-rename-impact.md` §3.1 edit-in-place. Коммит `83acb83`.
- 🔴 P3.3 — эскалирован ДО написания кода (Scope Included vs Validation конфликт), правок не вносил.

**Remaining:** P3.3 требует plan-design (разрешение конфликта), затем повторный exec; после — phase-гейт
`after:P3`; 17 items в P4–P9 (P4–P9 — скелеты, каждая фаза перед exec требует `/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/phases/P3.md` P3.3 — blockquote эскалации с точным перечнем 5 незапланированных файлов.
- `plans/DOMLANG/findings/P3-rename-impact.md` ← источник скоупа P3 (§3.1 — выполнен P3.2; §3.2 — группа K не трогать; §5 gate-числа для P3.3-верификации).
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён таблиц/колонок для P2–P5 — **устарел
  после P3.2** (ещё несёт старые `source_number`/`source_status`), актуализация — Scope P3.3 (не сделана, item заблокирован).
- `plans/DOMLANG/findings/P2-eis-stages.md` ← RAG-канон 4 этапов ЕИС (используется фикстурами P3.3).
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D20, §4 Status Board).
- `database/migrations/**` ← источник истины на уровне таблиц/колонок (после P3.2 актуальны).
- `plans/DOMLANG/open-questions.md` ← Q4 (для P4.1), Q7 (`Exploration`). Q1/Q2/Q3/Q5/Q6 — `Resolved`.
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 — DOMLANG P1.2).

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён.
- P3.3 заблокирован: конфликт Scope Included (4 вхождения) и Validation (0 по всему `tests/`) — факт: 9
  вхождений `'Размещение извещения'` в 8 файлах, 5 из них (`FreshRunProcessorTest.php:28`,
  `ArchiveWindowScannerTest.php:28`, `CsvBlockIngestorTest.php:30`, `ArchiveRunProcessorTest.php:28`,
  `EisCsvRowMapperTest.php:37`) не упомянуты ни в Scope Included, ни в Scope Excluded item'а.
  Design-сессия должна решить: расширить Scope на все 9 вхождений, или сузить Validation до 3
  заявленных мест (и объяснить, почему остальные 5 — легально незнакомая/иная фикстура).
- `etp_number` без продюсера (D13) — пин остаётся Scope P3.3 (не сделан); provenance без потребителя
  (ADR-0008, D15) — предмет P4.1, не путать с `etp_number`.
- Единственный гейт автопрогона — phase-гейт после P3 (`approvals["after:P3"]`); P3.3 — последний
  item перед ним, фаза P3 сейчас НЕ терминальна (P3.3 `🔴 Blocked`).
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список.
- В рабочем дереве незакоммичены доменные (не P3-scope) правки `plans/DOMLANG/workflow.js`
  (7 строк, существовали до P3.1) — не трогать при последующем exec P3.3, если не входят в его Scope.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в shell).
- Deferred: весь Scope P3.3 (zero-residue проверка, реализм фикстур, docblock `plt_status_mappings`,
  round-trip-тест `etp_number`, актуализация `findings/P1-actual-names.md`) — не выполнен, ждёт
  разрешения конфликта Scope/Validation через plan-design.
- Open questions (`open-questions.md`): Q4 (конфликтные поля provenance → P4.1), Q7
  (автопровижининг маппингов при создании реестра → онбординг второй площадки). Owner-гейтов нет.
