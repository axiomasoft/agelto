# 04 — Карта носителей находок аудита P3 (заход `plan-design P4`, 2026-07-30)

**Вход (слой 1/отчёт аудита):** `phases/P3.md` → блок `## Audit P3 — 2026-07-30` (вердикт
ATTENTION, находки A1–A10, автор — `plan-audit` opus/xhigh).
**Якорь дерева:** `5d8b4f3` (`git log --oneline -1`), рабочее дерево несёт незакоммиченными сам
блок аудита (`phases/P3.md`) и его строку журнала.
**Что делает этот синтез:** каждой находке аудита назначает НОСИТЕЛЯ — item, D-запись или правку
секции плана — и фиксирует команду, которой факт находки перепроверен ЭТИМ заходом (аудит не
принимается на веру: `plan-protocol` §16 требует восстановимой цепочки «сырьё → синтез → решение»).

## 1. Таблица носителей

| Находка | Класс | Носитель | Перепроверка факта этим заходом (на `5d8b4f3`) |
|:--|:--|:--|:--|
| **A1** — ложная аттестация агрегата Known Deviations в `## Phase Handoff` P3 и в `handoff.md` | бухгалтерия фазы | правка `## Phase Handoff` P3 + перезапись `handoff.md` этим заходом; правило — **D118** | `grep -c` по полям items: P3.2 — 3 записи, P3.3 — 6, P3.6 — 1 (одним абзацем), P3.1/P3.4/P3.5 — «—». Агрегат на `5d8b4f3` называл по P3.3 три записи и утверждал «`ai:prompts:suggest` не переименована», тогда как `docker exec agelto_app php artisan list --format=json` даёт `estimate:prompts:suggest` живой командой |
| **A2** — `pint --dirty --test` не гейт на закоммиченном дереве; `EvalRunCommand.php:168` красен | форма гейта + остаток кода | item **P4.6** (снятие докблока) + правило **D115** (§2 Execution Rules) | `docker exec agelto_app ./vendor/bin/pint --test app/Console/Commands/Procurement/Analyzers/Estimate/EvalRunCommand.php` → `FAIL 1 file, 1 style issue` (`no_superfluous_phpdoc_tags`); строка 168 — `@param  Collection  $rows` при том же типе в сигнатуре :170 |
| **A3** — `.ai/guidelines/architecture.md` устарел по умолчанию: путь модели промта неверен, каталогов анализаторов и команд не знает | AI-контекстный канон | item **P4.5** (носитель назван впервые — ни в P4, ни в P7.4 его не было) | `sed -n 15p .ai/guidelines/architecture.md` → «предметные модели анализа/**промтов**/датасета — `app/Models/Procurement/Ai/*`», при `ls app/Models/Llm/` → `Prompt.php` · `grep -n "Analyzers\|Console/Commands" .ai/guidelines/architecture.md` → пусто · `grep -n architecture.md` в `Files` P7.4 → только `navigation.md` и `CLAUDE.md` |
| **A4** — P3.5 закрыт без построчного отчёта по своей Validation, прогон на `<base>` не напечатан | исполнение, не дизайн | носителя НЕТ намеренно: правило уже существует — **D89** («гейт прогоняется на `<base>` и печатается рядом с выводом “после”») датирован 2026-07-29, то есть ДО исполнения P3.5 (2026-07-30). Новое правило дублировало бы старое; закрытый item не правится (история сдачи) → фиксируется открытым риском исполнения в `handoff.md` | `grep -c "Validation" phases/P3.md` в Completion Notes P3.5: поимённо отчитаны 5, 6а, 8, 9, 10, 12 из 13 строк |
| **A5** — `docs/03_Dev/admin-panel.md:35` говорит о каноне промтов как об ОДНОМ корне после D32 | документарный факт | item **P4.5** (второй файл того же item'а) | `sed -n 35p docs/03_Dev/admin-panel.md` → «сидовые промты из `resources/ai/prompts/**` read-only»; `docs/03_Dev/adr/0005-protected-prompts.md:17` уже несёт форму «обоих корней канона (`resources/ai/prompts/**` + `resources/<домен>/ai/prompts/**`, D32)» — образец формулировки существует |
| **A6** — шесть строк Update Log про P3.1–P3.6 без хешей item-коммитов; «пять Known Deviations» при шести | бухгалтерия плана | точечная правка §6 этим заходом; правило и обоснование append-only — **D119** | `grep -c "143d587\|befeee9\|35d8a7b\|2a140b9\|f2a4f75\|75092ee" plan.md` в §6 → 0; строка P3.3 §6 говорит «пять Known Deviations», фактических — 6 |
| **A7** — P3.6 исполнен `sonnet/medium` при предписанном Routing `implementation/low`; тир `low` под вопросом | Routing / тир | **D117** (разбор + пол команды `plan-exec`) + примечание под таблицей §3. Тир P7.3/P7.4 ПЕРЕПРОВЕРЕН: они предписаны `implementation/high`, вывод аудита к ним не применим | §3 Routing: строки P7.3 и P7.4 — `implementation/high`; `journal.jsonl` P3.6 → `"actor": "sonnet/medium"`; `plan-protocol` §9 — «`task:plan-exec` минимум — implementation/medium», то есть строка `low` была НИЖЕ пола команды |
| **A8** — хелпер `aiRouteResolver()` сохранил старое имя; греп-гейт регистро-зависим | остаток переименования + форма гейта | item **P4.6** (переименование) + правило **D116** (`-i`/camelCase в гейте остаточного имени) | `grep -rn "aiRouteResolver" tests/ app/` → **8** вхождений, все в `tests/Feature/Llm/LlmRouteResolverTest.php` (объявление :13 + семь вызовов) |
| **A9** — `estimates/reports/` не игнорируется; прогон сюита пачкает рабочее дерево | репо-гигиена | item **P4.6** (строка в `.gitignore`) | `git check-ignore -v estimates/reports/` → пусто, код возврата 1; `.gitignore` заканчивается строкой `/estimates/files/` БЕЗ завершающего перевода строки (`tail -c 60 .gitignore \| od -c`); `git status --short` → `?? estimates/reports/` |
| **A10** — мёртвый FQN `App\Console\Commands\Ai\CostReportCommand` в `app/Pulse/Cards/LlmSpendCard.php:17` | остаток переименования | Validation **P4.2** (файл уже стоит в его `Files`; новый item не заводится) | `sed -n 17p app/Pulse/Cards/LlmSpendCard.php` → FQN на месте; `ls app/Console/Commands/` → `Procurement`, `Reference`, `MaintainPartitions.php` — каталога `Ai` на верхнем уровне нет, класс живёт в `app/Console/Commands/Procurement/Ai/CostReportCommand.php` |

## 2. Почему носители легли в фазу P4, а не в новую фазу и не в реопен P3

Три варианта, взвешенные при выборе (провенанс решения — **D114**):

- **Реопен P3** — прямо исключён владельцем и протоколом захода: закрытые items P3.1–P3.6 несут
  историю сдачи, а находки A2/A3/A5/A8/A9 — не дефекты их исполнения, а либо остатки, у которых в
  границах закрытых `Scope` не было достижимого зелёного исхода (A5, A10 — класс D89 «в»), либо
  дефекты ФОРМЫ гейта, унаследованной из ТЗ (A2, A8).
- **Новая фаза P8** — отложила бы правку AI-контекстного канона до конца плана: `.ai/guidelines/
  architecture.md` читают агенты СЕЙЧАС, включая исполнителей самой P4, и он посылает их за моделью
  промта не туда. Отложенный документарный фикс — та же цена, что и отсутствующий.
- **Items в фазе P4 (выбрано)** — фаза ⬜ Not started, её порядок ещё не начат, а два новых item'а
  дешёвы и независимы от «денежных» items фазы. Плата названа честно: `Scope Included` фазы
  расширяется за пределы «учёт расхода» ровно на два remediation-item'а, и `Границы` контракт-блока
  получают явный carve-out.

## 3. Что этот заход НЕ трогает (анти-скоуп владельца)

Статусы, ТЗ, `Scope`, `Files` и `Validation` закрытых items P3.1–P3.6 · код и тесты (это шаг
проектирования — правит их исполнение P4.5/P4.6) · замороженные `root/*.md` (новых `supersedes`
D-записей по ним этот заход не заводит) · `plans/2026.07.26-EST-TRAIN/**` (D2/D9) · `CLAUDE.md`
(P7.4, D60/D64) · фазы P5–P7. Следствие последнего пункта: тексты Validation items P5–P7,
цитирующие `pint --dirty --test`, НЕ правятся построчно — их накрывает правило §2/D115, а
выравнивание литерального текста произойдёт при следующем заходе дизайна над этими фазами (названо
в D115 `**Consequences:**`).
