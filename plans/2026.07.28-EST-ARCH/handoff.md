# HANDOFF — 2026-07-30 — after P3.6

**Next:** `/task:plan-close 2026.07.28-EST-ARCH P3` — фаза P3 стала терминальной (6/6 items
🟢/🟠), Phase Handoff `phases/P3.md` не заполнен — закрытие фазы через `plan-close` (свой пин
Sonnet/low).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — закрытие терминальной фазы, реконсиляция по git-фактам, без решений |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P3 (Phase Handoff, доска, `plan-lint`); следующий предметный item — P4.1, но он `Exec=manual` и запускается отдельно от `plan-exec` |

```
/task:plan-close 2026.07.28-EST-ARCH P3
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). Фаза P2 закрыта 🟠 (5/7 🟢, 2/7 🟠) — терминальна.
**Фаза P3 закрыта 🟠 (3/6 🟢, 3/6 🟠) — терминальна.** P3.1 🟢 (`143d587`) — карта сервисного слоя
утверждена владельцем. P3.2 🟠 (`befeee9`) — шесть кросс-документных команд на сервисы.
P3.3 🟠 (`35d8a7b`) — двенадцать сметных команд `git mv` + переименование `ai:* → estimate:*`.
P3.4 🟢 (`2a140b9`) — пять сметных групп файлового канона промтов в `resources/estimates/ai/prompts/**`.
P3.5 🟢 (`f2a4f75`) — DTO/репозиторий AI-подомена разложены по D31, `AiRouteResolver` →
`LlmRouteResolver`, OQ-13 закрыт D113. **P3.6 🟠 (`75092ee`)** — `.ai/guidelines/**`,
`.claude/rules/agelto-reviewer-overrides.md`, три локальных доменных скила, `docs/03_Dev/**`
(включая два ADR по D65) и нормативная спека `08-estimate-extraction.md` синхронизированы с
именами `estimate:*` (включая `estimate:prompts:suggest`, D108), `llm_*`, `LlmRouteResolver`,
`Dto\Procurement\Analyzers\Estimate`; два файла сверх заявленного `Files`
(`docs/03_Dev/architecture.md`, `.claude/skills/testing-rules/SKILL.md`) добавлены по факту грепа
на тот же класс FQN — материальное отклонение, детали см. Known Deviations `phases/P3.md` P3.6.
Полный сюит зелёный: **791 passed, 1 skipped (3214 assertions)**, без изменений (item
документарный). `plan-lint --baseline HEAD` — 0 ERROR/0 WARN.

**Remaining:** 17 items ⬜ (P4 ×4 → P5 ×6 → P6 ×3 → P7 ×4). Гейт владельца впереди один — P4.2.
Батчи B1 (P4.3+P4.4), B2 (P7.1+P7.2) — `roadmap.md`. P4.1 — `Exec=manual` (Routing §3), запускается
отдельно от `plan-exec` (`/model opus` + `/effort high` + `/task:plan-run` либо ручной прогон
голого промпта item'а).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения), §3 Routing, §5
(**D1–D113**) · **ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`,
`root/document-contour.md`, `root/document-analyzer-contract.md`, `root/service-layer-map.md`** —
правятся ТОЛЬКО новой D-записью со `supersedes` · `findings/commands-and-layout-2026-07-29.md` ·
`roadmap.md` · `open-questions.md` (OQ-13 закрыт) · `phases/P3.md` (терминальна, ждёт `plan-close`) ·
`phases/P4.md`.

**Якоря фактов этого захода** (item-коммит `75092ee`, база `9bba3d0`):
`grep -rn "ai:eval\|ai:gold\|ai:fixtures\|ai:bench\|ai:dataset" docs/ .ai/ .claude/` → пусто ·
`grep -rn "Dto/Procurement/Ai/\(Estimate\|Gsfx\|Gold\|Bench\|Fixture\|Diff\)\|Dto\\Procurement\\Ai\\\(Estimate\|Gsfx\|Gold\|Bench\|Fixture\|Diff\)" docs/ .ai/ .claude/` → пусто ·
`grep -rn "prc_ai_providers\|prc_ai_routes\|prc_ai_prompts\|prc_ai_llm_calls\|prc_document_types\|has_extractor" docs/03_Dev/ .ai/` → пусто ·
`grep -n "estimate:" docs/03_Dev/commands.md` → 3 строки ·
`git diff --stat 9bba3d0 -- app/ tests/ database/ resources/` → пусто ·
`git diff --stat 9bba3d0 -- plans/2026.07.26-EST-TRAIN/` → пусто ·
`docker exec agelto_app php artisan test` → **791 passed, 1 skipped (3214 assertions)** (без
изменений, item документарный) ·
`python3 plan-lint.py plans/2026.07.28-EST-ARCH --baseline HEAD` → **OK, 0 ERROR/0 WARN, новых от
рабочего дерева: 0**.

**Open risks:**
- **P3.6 добавила два файла сверх `Files`** (`docs/03_Dev/architecture.md`,
  `.claude/skills/testing-rules/SKILL.md`) — материальное отклонение по `status-rule`, детали —
  Known Deviations `phases/P3.md` P3.6.
- **`app/Pulse/Cards/LlmSpendCard.php:17` цитирует устаревший FQN
  `App\Console\Commands\Ai\CostReportCommand`** — НЕ починено P3.6 (файл в `app/`, вне Scope
  документарного item'а, Validation 5 требует пустой диффа кода); кандидат следующей правке кода
  (P6/P7 или отдельный fix-item).
- **`App\Exceptions\Procurement` вне периметра §6.2** (D113) — легально, не открытый вопрос.
- **Периметр ядра §6.2 — счётчик-канарейка, не список руками:** 83 на `f2a4f75`. P3.6 код не
  трогала — счётчик не менялся (документарный item).
- **`ai:storage:lifecycle` и `ai:tools:doctor` — логически спорные имена**, решение — оставить,
  кандидат в P7.3/P7.4.
- **`PromptCritic` вне списка ядра §6.2** — инвариант «шаг анализатора = стадия извлечения» arch-тестом
  не защищён.
- **Ось типа документа у анализа ИЗВЛЕЧЕНИЯ пуста в боевом контуре** — долг, кандидат за P3.5.
- **Форма Validation «`config:cache` + `artisan test`» не имеет зелёного исхода** в этом проекте.
- **`--env=testing` НЕ переключает `DB_DATABASE`** — префиксовать `-e DB_DATABASE=agelto_test`.
- **`docker compose exec app` недоступен в этой среде** — рабочий путь `docker exec agelto_app`.
- **`PYTHONPATH=packages/task/lib` не резолвится** — рабочий путь
  `PYTHONPATH=/home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.9.0/lib`.
- **Grep-гейты на литеральный `\`-разделитель FQN** — см. дисциплину эскейпинга P3.5 open risks.
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм**.
- **Предпросмотр склеенного промта ДО прогона** — сознательно не введено, кандидат P5.5/P5.7.
- **Форма Validation-строк 2/2а/4а P3.3 шире факта** — детали Known Deviations P3.3.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 · строки Pending Work
  про `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: без изменений относительно P3.5 (см. предыдущий handoff в истории git) — item
  документарный, новых workaround'ов не породил.
- deferred: без изменений относительно P3.5.
- open_questions: нет открытых в этой фазе. Остался один гейт владельца — P4.2. Вне плана:
  P7.6 плана `2026.07.26-EST-TRAIN`.
