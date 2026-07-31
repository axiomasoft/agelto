# HANDOFF — 2026-07-31 — after P7

**Next:** план закрыт.

**Done:** фаза **P7 закрыта 🟠 Done with deviations (6/6 items терминальны: 5 🟢, 1 🟠)** —
последняя фаза плана `2026.07.28-EST-ARCH`. Реконсиляция `plan-close P7`: Status Board ↔ Phase
Status сведены без расхождений; `## Phase Handoff` в `phases/P7.md` заполнен (сдача, агрегат Known
Deviations items P7.1/P7.3 механически из полей, docs-sync — «обновлено», что дальше). **Все 7 фаз
плана (P1–P7) теперь терминальны** — план закрыт ЦЕЛИКОМ: `plans/ACTIVE.md` сброшен на `—`, `Status`
в `## 0. Meta` — агрегат `🟠 Done with deviations` (есть 🟠-фазы: P1–P7 все несут хотя бы один
известный отклонившийся item).

Состав фазы P7 (сдано ранее, реконсилировано этой сессией):
- **P7.1 🟠** — `estimate:dataset:export` доведён до training-ready: реальный текст представления
  вместо ссылки (`DatasetInputBuilder`), основание доверия, `--split`/`--source`/`--dpo-schema`,
  DPO-резолв предшественника через сохранённый `analysis_id`. Отклонение — дифф вне буквального
  `Files` (единственные фактические носители логики после P3.3).
- **P7.2 🟢** — предсказание D41 подтверждено: `WorkingCopyProvisioner` — единственный сейм рабочей
  копии эталона между `estimate:gold:import` и действием «Прогнать».
- **P7.3 🟢** — нормативная спека `docs/specs/v2/**` сверена против финальной структуры, снесены
  голые ссылки на items чужих планов, добавлен §8.7 «Экспорт под локальную LLM».
- **P7.4 🟢** — `training-loop.md`/`prompts.md` переписаны в `living`, два новых + три обновлённых
  операторских SOP, ADR перенесён (`docs/03_Dev/adr/0010-input-representation.md`), дрейф хаба сведён
  в `CLAUDE.md` (четыре строки, не три — найдена P2.5).
- **P7.5 🟢** / **P7.6 🟢** (remediation по аудиту P6, D137/D138) — §2 спеки приведён к факту
  объявления словарей (вопрос расширения → OQ-18); таблица распределения `identifier` в
  `static-analysis.md` дополнена и сходится (240/74).

**Remaining:** ничего внутри протокола — план терминален. Следующий шаг вне протокола:
`/task:plan-close archive 2026.07.28-EST-ARCH`, когда владелец решит перенести план в архив.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (Execution Rules, D140), §3 Routing, §5
(D1–D140) · ЗАМОРОЖЕНЫ: `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`, `root/service-layer-map.md`, `root/usage-source-contract.md`,
`root/naming-canon.md` · `phases/P1.md`…`phases/P7.md` (все терминальны, все Phase Handoff заполнены)
· `docs/03_Dev/ai/{training-loop,prompts}.md` (`living`) · `docs/03_Dev/adr/0010-input-representation.md`
(новый канон) · `docs/04_Operators/**` (5 обновлённых + 2 новых SOP) ·
`CLAUDE.md`/`.ai/guidelines/{architecture,glossary,navigation}.md` (синхронизированы) ·
`docs/specs/v2/08-estimate-extraction.md` (ревизован P7.3, обратная ссылка ADR обновлена P7.4) ·
`research/01-target-architecture.md` §8 (coupling-таблица, D15) ·
`findings/inherited-debts-2026-07-29.md` §2 · `roadmap.md` · `open-questions.md` (OQ-15, OQ-18).

**Open risks (наследуются в архив):**
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
- **Реконсилирующее закрытие фазы P4 не выполнено ранее** — механически сведено этим `plan-close`
  проходом по фазовым таблицам P4 (Status Board уже отражал 🟠, расхождений не найдено); отдельного
  прохода `plan-close P4` эта сессия не запускала — фаза и так терминальна и сведена.
- **Предсуществующий ERROR `plan-lint`: `phases/P4.md:481`** — P4.5 закрыт 🟢 без строки
  `write-site: …`. Атрибуция ниже (Lint).
- Дефект компилятора Task Contract (OQ-14) — наследуется, workaround: резолв узла
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98).
- **Посторонний дифф в дереве (не мой, не тронут):** массовое удаление файлов каталога `Old/`
  (легаси v1-приложение) — по-прежнему unstaged `D`; изменённый `.env.example`; неотслеживаемые
  `config/cms.php`, `database/seeders/User/`. Требует решения владельца ДО следующего коммита; не
  затрагивает Files фазы P7 (`git status --short -- plans/2026.07.28-EST-ARCH` пуст).

**Workarounds/Deferred/Open questions:**
- workarounds: `docker exec agelto_app` вместо голого `docker compose exec app` (compose здесь не
  поднимается из-за отсутствующей `MINIO_ROOT_PASSWORD`); резолв `task.task_contract`/
  `task.route_evidence`/`plan-lint.py`/`journal.py` через плагин-кэш
  `~/.claude/plugins/cache/swissknifeman/task/0.9.0/lib` (D98); ADR-ссылки на неархивированный
  чужой план — раздельная форма «план `<ID>`, `путь`» вместо литерального пути (P7.3/P7.4).
- deferred: пере-замер F1 (P7.6 EST-TRAIN); расширение объявления словарей на четыре шага (OQ-18);
  сведение двух точек валидации профиля (OQ-15); чистка `AiRoutePermission`↔`LlmRouteResource`;
  Policy-покрытие каталога прав под `--strict`; предсуществующий `plan-lint` ERROR на
  `phases/P4.md:481`; таблица сопоставления в markdown-выводе `estimate:eval:diff`; дефект
  `Section::section_number` (P6.3); классифицированный остаток `phpstan-baseline.neon`; бюджет
  `PromptCompositionTest` (F5); первая непустая DPO/SFT-выгрузка (P7.1); объединение
  `estimate:dataset:import` с сеймом рабочей копии (P7.2/D57).
- open_questions: OQ-18 без изменений (`Decision pending`, владелец); OQ-15 без изменений (`Decision
  pending`); вне плана — OQ-14 (дефект компилятора Task Contract) и PROMOTE-CANDIDATE D140 в
  `plan-protocol`.

**Lint:** `plan-lint --baseline HEAD` на итоговом дереве (после перезаписи этого `handoff.md`) —
**1 ERROR / 0 WARN, новых от закрытия: K = 0** (предсуществующий `phases/P4.md:481` — P4.5 закрыт
🟢 без строки `write-site: …`, известен из предыдущих handoff'ов). `verify-citations.py` в этом
дереве отсутствует (`plans/verify-citations.py` не найден) — шаг пропущен, предупреждение зафиксировано
здесь, а не молча.
