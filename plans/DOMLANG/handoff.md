# HANDOFF — 2026-07-10 — after P3.3

**Next:** Фаза P3 терминальна (3/3 🟢) — это единственный phase-гейт плана (D10,
`approvals["after:P3"]`). Дальнейшее продвижение в P4 требует явного подтверждения владельца;
до подтверждения `plans/DOMLANG/workflow.js` не переисполняется автоматически поверх этого гейта.
После подтверждения P4 — ещё скелет (0/2), первый шаг — `/task:plan-design DOMLANG P4`, не
`plan-exec` (P4.1 не имеет заполненного ТЗ).

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — детализация research-item'а P4.1 (провенанс, доказательная база + потребитель, ADR-0008); может потребовать RAG |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Владелец подтверждает phase-гейт `after:P3`, затем `/task:plan-design DOMLANG P4` — детализация P4.1 (research: провенанс-поля с proof-таблицей) и P4.2 |

```
/task:plan-design DOMLANG P4
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). **P3 терминальна (3/3 🟢):**
- 🟢 P3.1 — preflight impact-карты: все 6 gate-счётчиков сошлись, drift не найден.
- 🟢 P3.2 — атомарный ренейм: `source_number`→`registry_number`, `source_guid`→`registry_guid`,
  `source_status`→`source_stage` (`prc_procurements`, `prc_ignored_items` D19, `prc_status_mappings`
  D18). Коммит `83acb83`.
- 🟢 P3.3 — zero-residue верификация (7/7 Validation зелёные), фикстуры этапа ЕИС и мёртвые имена
  `plt_*` приведены к реальности (D21/D22), `etp_number` пинён round-trip-тестом (D13), карта имён
  `findings/P1-actual-names.md` актуализирована под P3.2. 469 тестов зелёные (baseline 467 + 2
  новых). Коммит `01cae79`.

**Remaining:** Phase-гейт `approvals["after:P3"]` (владелец) → 17 items в P4–P9 (скелеты, каждая
фаза перед exec требует `/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Status Board (P1–P3 терминальны) · §5 D-лог (D18–D22 — решения P3).
- `plans/DOMLANG/phases/P3.md` — Phase Handoff, все три item'а закрыты с Completion Notes.
- `plans/DOMLANG/findings/P1-actual-names.md` — канон имён таблиц/колонок для P4–P5, актуален
  после P3.2/P3.3 (§1/§3 переписаны; §4 provenance намеренно пуст — предмет P4.1, D15).
- `database/migrations/**` — источник истины на уровне схемы.
- `plans/DOMLANG/open-questions.md` — Q4 (для P4.1), Q7 (`Exploration`). Q1/Q2/Q3/Q5/Q6 — `Resolved`.
- `docs/03_Dev/adr/0001-0008` — несущие решения (0006/0007/0008 — DOMLANG P1.2, 0008 прямо
  определяет критерий P4.1).

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён.
- Phase-гейт `after:P3` — единственный во всём плане (D10); пропустить его нельзя, `workflow.js`
  на нём остановится сам, но exec-агент обязан явно дождаться подтверждения, а не продолжать в P4.
- `prc_protocols.source_number` (18 вхождений, 8 файлов группы K) — намеренно не переименован
  (D20), защищён machine-gate-счётчиком в §5 карты; будущий токен-левел find-replace его сломает.
- `etp_number` остаётся без реального продюсера (223-ФЗ) — round-trip-тест пинит write-path, но
  не заменяет появления парсера; провенанс-колонки (ADR-0008, D15) — отдельный предмет P4.1, не
  путать с `etp_number`.
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → используй `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список.
- В рабочем дереве остаются незакоммиченные доменные (не P3-scope) правки `plans/DOMLANG/workflow.js`
  (7 строк, существовали до P3.1) — вне скоупа P3, не трогать без отдельного решения владельца.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в shell).
- Deferred: —.
- Open questions (`open-questions.md`): Q4 (конфликтные поля provenance → P4.1), Q7
  (автопровижининг маппингов при создании реестра → онбординг второй площадки). Owner-гейтов нет
  на Q4/Q7 — они разрешатся исполнением P4.1/будущим онбордингом.
