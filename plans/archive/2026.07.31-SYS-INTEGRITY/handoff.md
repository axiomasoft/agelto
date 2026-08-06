# HANDOFF — 2026-08-06 — after P8

**Next:** план закрыт

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — терминальный план, следующий шаг вне протокола |
| Context | NEW SESSION — шаг-не-item |
| Суть | Все восемь фаз плана (P1–P8) терминальны — план закрыт целиком; следующий шаг — `/task:plan-close archive 2026.07.31-SYS-INTEGRITY` либо вне протокола, решает владелец |

**Done:** Фаза **P8 закрыта 🟠 Done with deviations 2026-08-06** реконсиляцией `plan-close`:
все семь items терминальны (P8.1/P8.2/P8.6/P8.7 — 🟢, P8.3/P8.4/P8.5 — 🟠 с
process-отклонениями, задокументированными в их Known Deviations). Status Board (`plan.md` §4)
↔ Phase Status (`phases/P8.md`) сверены без расхождений: строка P8 `4/7 🟠 Done with deviations`
уже отражала фактическое состояние (счётчик считает только `🟢`). Docs-sync фазы закрыт P8.7
(доки/глоссарии/скилл синхронизированы). `plan-lint.py --baseline HEAD` →
`OK (0 ошибок, 0 предупреждений; новых от рабочего дерева: 0)`. `verify-citations.py` в `plans/`
этого репозитория нет (плановая заметка §2 Execution Rules) — шаг пропущен.

**Все восемь фаз плана (P1–P8) терминальны** (`🟢`/`🟠` в `plan.md` §4 Status Board) →
план закрыт ЦЕЛИКОМ: `Status` в `plan.md` §0 Meta переведён в `🟠 Done with deviations`
(агрегат — есть фазы с `🟠`), `plans/ACTIVE.md` сброшен на `**Active:** —`.

**Remaining:** работы execution-уровня по плану не осталось. Известные отклонения по фазам —
см. Phase Handoff каждой `phases/Pn.md` (P1–P8); открытые риски и деферренды — см. Open risks/
Deferred ниже, унаследованные из предыдущей записи этого файла (перезаписан этой реконсиляцией,
факты не устарели).

**Sources of truth:**

- `plan.md` §0 Meta (`Status: 🟠 Done with deviations`) и §4 Status Board — все восемь фаз
  терминальны.
- `plans/ACTIVE.md` — `Active: —`.
- `phases/P1.md`…`phases/P8.md` — Phase Handoff каждой фазы несёт сдачный текст и известные
  отклонения.

**Open risks:**

- **`PromptApplyTest` ×2** падают на `Permission denied` при записи в `resources/ai/prompts/**` —
  права volume dev-окружения, не код приложения; вне Scope плана.
- **`GeminiProxyRoutingTest`** недетерминирован (`fake()->password()` без фиксации seed) — фикс-
  кандидат `Str::random()` либо явный `rawurlencode()` с обеих сторон сравнения.
- **`TextGridRendererTest`** — средовая гонка по `memory_limit` в общем Paratest-воркере.
- **`CoreKnowsNoDocumentTypeTest`** — предсуществующее падение (периметр 101/98), задокументировано
  P8.4/P8.5, вне Scope плана.
- Открытые риски P8.1–P8.6 (генератор схемы не сужает enum; стоимость формы `response_schema`
  +9.6%; `pdf_ocr` не отдаётся для голых сканов; run-level агрегат прогона без fill-rate;
  `estimate:eval:diff --format=md` без `predicted_quality`; И-Б непроверяема до первого платного
  прогона промтом редакции P8.3; null-unsafety `AsCollection::class` на других nullable
  jsonb-колонках проекта) — за владельцем/следующим планом.
- Чужой незакоммиченный диф, НЕ ТРОНУТ (`plan.md` §2 Execution Rules): `M .env.example`,
  `M docker-compose.yml`, `M docs/03_Dev/process/testing.md`, untracked `.swissknife.json`,
  `.swissknife/`, `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`,
  `docker/php/conf.d/pcov-dev.ini`, `plans/archive/2026.07.31-SYS-INTEGRITY/artifacts/gemini-analys.md`.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* `docker exec agelto_app …` вместо `docker compose exec app …` — обход чужого
  дифа `docker-compose.yml`.
- *Deferred:* пересмотр `ai.representations.policy` для PDF-сканов; режим bench «без gold»
  (D151); OQ-10 (контур операторских правок позиции); fill-rate в агрегате прогона и колонка
  пометки в md-рендере diff; три фикса тестовой среды из Open risks выше; OQ-5 (вынесен в
  отдельный план — сверка при `plan-close`).
- *Open questions:* OQ-1…OQ-9 закрыты по ходу плана (D69–D134, см. `plan.md` §5); OQ-10 открыт
  (контур операторских правок позиции); OQ-7 (дедупликация текстов промтов P2.12, чужой долг).
