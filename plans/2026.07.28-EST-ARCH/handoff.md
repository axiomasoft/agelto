# HANDOFF — 2026-07-30 — after P4.6

**Next:** редизайн эскалации P4.5: task:plan-design 2026.07.28-EST-ARCH P4.5

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item |
| Суть | Снять эскалацию P4.5: Task Contract pre-final даёт структурный ложноположительный `block:ACCEPTANCE_NOT_PUBLIC` на уже исполненной и закоммиченной работе (`eeb519d`) — нужно решение владельца/plan-design: переформулировать Validation 4 без плейсхолдера `<домен>` либо принять D#-запись |

```
/task:plan-design 2026.07.28-EST-ARCH P4.5
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢). Фаза P2 закрыта 🟠 (5/7 🟢, 2/7 🟠). Фаза P3 закрыта 🟠
(3/6 🟢, 3/6 🟠), проаудирована (ATTENTION, 10 находок). Фаза P4 идёт (1/6 🟢, P4.5 заблокирован):

- **P4.6 закрыт 🟢 (`af5475d`)**: три остатка P3 сняты — докблок `EvalRunCommand.php`
  (`@param Collection $rows` убран, `pint --test` зелёный), тест-хелпер `aiRouteResolver()` →
  `llmRouteResolver()` (8 вхождений, все вызовы обновлены), `.gitignore` получил `/estimates/reports/`
  с комментарием (D111). Task Contract `pre_mutation: allow · pre_final: allow`. Полный сюит —
  **791 passed, 1 skipped, 3214 assertions**; `phpstan` — 0 ошибок, `phpstan-baseline.neon` не тронут.
- **P4.5 → 🔴 Blocked, эскалация ПОСЛЕ полного исполнения** (см. Open risks и `phases/P4.md` P4.5).
  Оба файла AI-контекстного канона (`.ai/guidelines/architecture.md`, `docs/03_Dev/admin-panel.md`)
  правлены и закоммичены (`eeb519d`), все 8 Validation зелёные на item-коммите/красные на `<base>`
  (D89 исход «а» подтверждён вручную). Закрытие остановлено ТОЛЬКО механикой Task Contract
  (`pre_final: block:ACCEPTANCE_NOT_PUBLIC`) — прецедент F1 этого плана запрещает закрывать item на
  незелёной границе с одной лишь припиской в Completion Notes (реопен P1.5–P1.8 уже показал цену
  этого шортката).

**Remaining:** 17 items ⬜ (P4.5 — 🔴 отдельно, не входит в счёт ⬜) — P4 ×4 (P4.1 → P4.2 →
B1 = P4.3+P4.4), P5 ×6, P6 ×3, P7 ×4. Гейт владельца впереди один — P4.2. Батчи: **B1**
(P4.3+P4.4, `plan-exec`), **B2** (P7.1+P7.2, `plan-run`) — `roadmap.md`. **P4.1 не запускается, пока
P4.5 не разрешён** — P4.5 не в зависимостях P4.1 по данным, но фаза не терминальна, пока в ней есть
🔴; `plan-exec`/`plan-run` следующего item'а фазы P4 сначала проверит это состояние.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2, §3 Routing, §5 (D1–D119) · ЗАМОРОЖЕНЫ:
`root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`, `root/service-layer-map.md` · `phases/P4.md` (P4.5 эскалация,
P4.6 Completion Notes) · `roadmap.md` · `open-questions.md` (открытых внутри плана нет).

**Якоря фактов этого захода:**
P4.5 (item-коммит `eeb519d`, `<base>` = `5d8b4f3`): `git diff --name-only 5d8b4f3..HEAD --
. ':(exclude)plans'` → ровно два пути. Task Contract → `{'pre_mutation': 'allow', 'pre_final':
'block:ACCEPTANCE_NOT_PUBLIC'}`.
P4.6 (item-коммит `af5475d`, `<base>` = `1358d75`): `pint --test <оба файла>` → PASS 2 files (на
`<base>` — FAIL 2 files, 1 style issue); `grep -rni "airouteresolver" app/ tests/ database/ config/
resources/` → пусто (на `<base>` — 8); `git check-ignore -v estimates/reports/gold-corpus-report.md`
→ `.gitignore:105:/estimates/reports/` (на `<base>` — пусто, rc 1); `php artisan test` → 791
passed, 1 skipped, 3214 assertions; `phpstan analyse --memory-limit=1G` → `[OK] No errors`. Task
Contract → `{'pre_mutation': 'allow', 'pre_final': 'allow'}`.

**Open risks:**
- **P4.5 эскалирован — Task Contract структурный ложноположительный.** Компилятор `task_contract.build`
  (плагин `swissknifeman/task@0.9.0`) не разрезает нумерованный `Validation` (сплиттер режет только по
  `·`) и ложно классифицирует всю клаузу `internal` из-за плейсхолдера `<домен>` в Validation 4
  (`resources/<домен>/ai/prompts`, читается как абсолютный путь `/ai/prompts`). Разбор — `phases/P4.md`
  P4.5 Completion Notes. Владелец компилятора — пакет `swissknifeman/task`, не этот план;
  реопен-кандидат туда. Внутри ЭТОГО плана — решение `plan-design`: переформулировать Validation 4
  БЕЗ угловых плейсхолдеров (например `resources/{домен}/ai/prompts` или `resources/<DOMAIN>/ai/prompts`
  — заглавными, без риска коллизии с `PORTABILITY_PATH_RE`), затем повторно прогнать Task Contract на
  уже существующем коммите `eeb519d` (переисполнять правку не нужно).
- **Тот же класс ошибки вероятен на будущих Validation-строках с угловыми плейсхолдерами** (`<файл>`,
  `<имя runbook'а>` и т. п.) или ведущим `/` в цитате пути — при `ACCEPTANCE_NOT_PUBLIC` сначала
  проверить эту гипотезу, не расширять периметр вручную.
- **A4 аудита P3 — исполнители пропускают построчный отчёт по Validation** (наследуется).
- **Тексты Validation items P5–P7 цитируют `pint --dirty --test`** — форма отменена D115.
- **`app/Pulse/Cards/LlmSpendCard.php:17`** — мёртвый FQN ещё жив; закрывает P4.2 (Validation 6а).
- **`CLAUDE.md` несёт то же ложное утверждение про путь модели промта**, что чинил P4.5 в
  `.ai/guidelines/architecture.md` — закрывает P7.4 (D60/D64).
- **Посторонние untracked-пути в дереве** — `.swissknife/`, `estimates/gemini-analys.md`,
  `plans/2026.07.28-EST-ARCH/.swissknife/`: не входят ни в один `Files`, не коммитить, не удалять (§6).
- Наследуются из P1/P3: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 · боевой прогон P5.2 —
  за владельцем (D94) · `verify-citations.py` недоступен в этом репозитории.

**Workarounds/Deferred/Open questions:**
- workarounds: —.
- deferred: выравнивание литерального текста гейтов стиля в фазах P5–P7 (D115); ревизия имён
  `ai:storage:lifecycle`/`ai:tools:doctor` — P7.3/P7.4.
- open_questions: открытых внутри плана нет. Остался один гейт владельца — P4.2, и одна эскалация
  закрытия — P4.5.
