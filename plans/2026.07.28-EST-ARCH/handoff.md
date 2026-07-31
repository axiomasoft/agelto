# HANDOFF — 2026-07-31 — after P7.4

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — `task:plan-close` пинит Sonnet/low, фаза P7 терминальна |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P7 (5/6, 🟠 Done with deviations) плана `2026.07.28-EST-ARCH`: таблицы, Phase Handoff, lint. Это ПОСЛЕДНЯЯ фаза плана — после закрытия план готов к архивации |

```
/task:plan-close 2026.07.28-EST-ARCH P7
```

**Done:** фазы P1–P6 закрыты как в предыдущем handoff'е (все `🟠 Done with deviations`, P6
терминальна и аудирована — вердикт ATTENTION, продукт цел). Фаза **P7 — 6/6 items закрыты** (батч
B4 🟢🟢, батч B2 🟠🟢, P7.3 🟢, **P7.4 закрыт этой сессией 🟢** — все шесть items фазы терминальны,
фаза `🟠 Done with deviations` на уровне фазы (несёт 🟠 P7.1)):

- **P7.4 (`88c8caf`+`65c7c7d`) 🟢.** `docs/03_Dev/ai/training-loop.md` переписан целиком
  (`status: living`, был `draft`) — маршрут человека по финальной структуре плана: эталон/тип-профиль
  (D18) → запуск прогона («Прогнать», P5.2) → история/результат (P5.3/P5.4, эталон рядом с
  предсказанием, D48) → расход (слепок P4.1 + дашборд P4.3 + сверка P4.4) → eval/diff (P6.2) →
  предложение патча и апрув (P5.6, D8/D64, «одобрено — не применено») → измерение tune/holdout (D63)
  → экспорт под локальную модель (P7.1); mermaid `flowchart TD` с именами реальных классов/команд;
  раздел «Известные долги». `docs/03_Dev/ai/prompts.md` (`draft`→`living`) дополнен разделом «Очередь
  предложений и критик промта». Два новых операторских SOP (`run-fixture-analysis.md`,
  `read-spend-dashboard.md`) + три обновлённых (`manage-prompts.md` — **право решения по очереди
  предложений только у роли «Суперадмин»**, не «Оператора», проверено фактом
  `app/Guards/Admin/Roles/*Role.php`, а не текстом ТЗ item'а; `check-ai-analysis.md`/
  `review-estimate.md` — кросс-ссылка на показ эталона без правки). `docs/04_Operators/_index.md` —
  роутер + мини-матрица прав с точными правами. ADR перенесён —
  `docs/03_Dev/adr/0010-input-representation.md` (содержание дословно, шапка/относительные ссылки
  переоформлены — раздельная форма «план `2026.07.26-EST-TRAIN`, `research/NN-*.md`» вместо
  литерального пути, чтобы удовлетворить Validation 11 «grep по литералу плана — пусто»; источник несёт
  маркер переноса, не удалён; обратная ссылка §5.4 спеки переведена на канонический путь). Дрейф хаба
  (D60) сведён в `CLAUDE.md` дословно из уже правленных `.ai/guidelines/{architecture,glossary}.md`
  — **четыре строки, а не три** (P1.5 — домен `Llm`/префикс `llm_`; P4.5 — путь модели промта +
  каталоги `Analyzers/**`/`Console/Commands/{Ai,Analyzers/Estimate}/`, одной парой строк с P1.5;
  P3.6 — те же строки Domain Hub + канон `Ai\Prompt` два корня; **P2.5 — найдена построчной сверкой
  сверх изначально ожидаемых трёх**: `PromptStep`/`LlmPurpose` как интерфейсы, не плоские enum'ы).
  P1.7's заявленный Pending Work item закрыт КОНСТАТАЦИЕЙ отсутствия факта: его переименования
  (`AiCallStoreRepository`→`LlmCallStoreRepository` и т.д.) фактически не коснулись ни одного из двух
  глоссариев (`git log` подтверждает) — источника для переноса нет. Все 12 Validation зелёные
  (детали, числа и якоря — `phases/P7.md` P7.4 Completion Notes); `plan-lint` — см. ниже.

**Remaining:** **Фаза P7 терминальна (6/6, 🟠 Done with deviations на уровне фазы).** Весь план
`2026.07.28-EST-ARCH` — **7/7 фаз терминальны** (P1–P6 закрыты ранее, все 🟠; P7 закрыта этой сессией).
Формальное закрытие фазы P7 (таблицы Phase Index, Phase Handoff-блок, финальный `plan-lint` на
итоговом дереве) — задача `task:plan-close`, не выполнено этой сессией (её топология — item-закрытие,
не фазовое). После `plan-close P7` план готов к архивации (`task:plan-close archive` или отдельный
проход). Реконсилирующее закрытие фазы P4 (наследуемый риск, названо в handoff'ах с P4) всё ещё не
выполнено — не блокирует P7/архивацию, но должно быть учтено при архивации плана целиком.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules, D140), §3 Routing, §5
(D1–D140) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`, `root/service-layer-map.md`, `root/usage-source-contract.md`,
`root/naming-canon.md` · `phases/P1.md`…`phases/P6.md` (терминальны) · `phases/P7.md` (все шесть
items Completion Notes, фаза 6/6) · `docs/03_Dev/ai/{training-loop,prompts}.md` (`living`) ·
`docs/03_Dev/adr/0010-input-representation.md` (новый канон) · `docs/04_Operators/**` (5 обновлённых
+ 2 новых SOP) · `CLAUDE.md`/`.ai/guidelines/{architecture,glossary,navigation}.md` (синхронизированы)
· `docs/specs/v2/08-estimate-extraction.md` (ревизован P7.3, обратная ссылка ADR обновлена P7.4) ·
`research/01-target-architecture.md` §8 (coupling-таблица, D15) ·
`findings/inherited-debts-2026-07-29.md` §2 · `roadmap.md` · `open-questions.md` (OQ-15, OQ-18).

**Open risks:**
- **PROMOTE-CANDIDATE владельцу — правило `Files` ⊇ `Validation` в скилл `plan-protocol`** (D140).
- **Остаточная дупликация `estimate:dataset:import`** (`DatasetImporter::importPair()`, D57) —
  собственная копия создания рабочей копии, НЕ сведённая к сейму `WorkingCopyProvisioner`; названа
  явно в `08` §8.3 и в `training-loop.md` «Известные долги».
- **F1 EST-ARCH P6.1 не перемерян боевым прогоном** (D52) — боевой замер — P7.6 плана
  `2026.07.26-EST-TRAIN`, гейт владельца.
- **OQ-18 — расширять ли словари §2.1/§2.2 на четыре шага извлечения** (открыт D137, текстом закрыт
  P7.5). Решается числом `estimate:eval:compare` на holdout; ни один item плана от решения не зависит.
- **Форма ссылки на неархивированный чужой план** (Known Deviations P7.3; форма ADR-переноса P7.4
  использует ту же дисциплину) — если `EST-AI`/`2026.07.26-EST-TRAIN` когда-нибудь переедут в
  `plans/archive/`, ссылки можно (не обязательно) привести к буквальной архивной форме.
- **`EvalDiffCommand::renderMarkdown()` не показывает таблицу сопоставления** (P6.2).
- **Живой дефект `Section::$section_number`** (найден P6.3, НЕ починен) — follow-up item.
- **Первая непустая DPO/SFT-выгрузка не продемонстрирована** (P7.1 Pending Work) — корпус пуст в
  этой среде; снимается отдельным прогоном после первого approve через админку.
- **ACL каталогов, создаваемых контейнером** — `docker exec … chgrp 1000 && chmod g+w` (с P5.4/P5.5).
- **`guard:catalog:validate --strict` системно красный** — вне плана.
- **Расхождение `AiRoutePermission`↔`LlmRouteResource`** — отдельный item.
- **Кнопка прогона требует `AI_SCREENING_ENABLED`** (дефолт `false`) — с P5.2.
- Боевой прогон кнопки на реальном провайдере — гейт владельца (D94).
- Две точки проверки формы профиля — принятый долг D132, адресат OQ-15.
- **Реконсилирующее закрытие фазы P4 не выполнено** — не блокирует P7, учесть при архивации плана.
- **Предсуществующий ERROR `plan-lint`: `phases/P4.md:481`** — P4.5 закрыт 🟢 без строки
  `write-site: …`. Чинится при `plan-close` P4 (или реконсиляции архивации).
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Посторонний дифф в дереве (не мой, не тронут):** массовое удаление файлов каталога `Old/`
  (легаси v1-приложение) — по-прежнему unstaged `D`; изменённый `.env.example`; неотслеживаемые
  `.swissknife/`, `estimates/gemini-analys.md`, `config/cms.php`,
  `database/seeders/User/AdminUserSeeder.php`. Требует решения владельца ДО следующего коммита.

**Workarounds/Deferred/Open questions:**
- workarounds: `docker exec agelto_app` вместо голого `docker compose exec app` (compose здесь не
  поднимается из-за отсутствующей `MINIO_ROOT_PASSWORD`); резолв `task.task_contract`/
  `task.route_evidence`/`plan-lint.py`/`journal.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98); ADR-ссылки на неархивированный
  чужой план — раздельная форма «план `<ID>`, `путь`» вместо литерального пути (P7.3/P7.4).
- deferred: пере-замер F1 (P7.6 EST-TRAIN); расширение объявления словарей на четыре шага (OQ-18);
  сведение двух точек валидации профиля (OQ-15); чистка `AiRoutePermission`↔`LlmRouteResource`;
  Policy-покрытие каталога прав под `--strict`; предсуществующий `plan-lint` ERROR на
  `phases/P4.md:481`; реконсилирующее закрытие фазы P4; таблица сопоставления в markdown-выводе
  `estimate:eval:diff`; дефект `Section::section_number` (P6.3); классифицированный остаток
  `phpstan-baseline.neon`; бюджет `PromptCompositionTest` (F5); первая непустая DPO/SFT-выгрузка
  (P7.1); объединение `estimate:dataset:import` с сеймом рабочей копии (P7.2/D57).
- open_questions: OQ-18 без изменений (`Decision pending`, владелец); OQ-15 без изменений (`Decision
  pending`); вне плана — OQ-14 (дефект компилятора Task Contract) и PROMOTE-CANDIDATE D140 в
  `plan-protocol`.
