# Sol: ремедиация финального аудита корпуса 2025

## Задача и полномочия

Владелец запросил финальный аудит crawling/parsing для полного сбора закупок 2025 и
работы 24/7. Условие: немного дефектов — исправить сразу, много — передать новой сессии Sol.
Подтверждено 15 групп продуктовых дефектов, поэтому выбран второй путь.

Исполнитель: **Sol**. Пользовательский выбор модели имеет приоритет над дефолтным routing
навыков. Исправить C01–C15 и D01 из
`research/2026-09-12-final-corpus-audit.md`, восстановить два падающих тестовых контракта,
проверить результат. Предварительная оценка сделана; не начинать ещё один общий аудит
вместо реализации. Каждую находку перед правкой коротко сверить с текущим кодом.

### Уточнение владельца от 2026-09-12

Обрабатывается фиксированный корпус завершённых закупок за весь 2025 год по дате по
возрастанию. Изменения исторической выдачи после фиксации корпуса не учитываются. Цель —
завершить длительный архивный проход без пропусков, сохраняя durable-прогресс и
возобновляя работу после сбоев. Это уточнение является частью задания и определяет
семантику checkpoint/completeness для C01–C05.

## Исходное состояние

HEAD `941ade6d743628acca72564cd31afabd1bb9bd26`, но **реальная база — dirty worktree**.
В нём уже находятся исправления предыдущих F01–F10, включая новую target policy,
streaming downloader, lease tokens, SQL recovery, screening continuation и temp helper.
Они принадлежат владельцу. Не reset/checkout/stash всего дерева и не перезаписывать
исправления старой версией HEAD. Перед правками проверить git status/diff/ignore.

В этой аудитной сессии product tree не менялся. Добавлены только новый отчёт, этот handoff
и standalone diagnostic probes. Планы не переписывались. Исторический отчёт
`research/2026-09-12-production-audit/remediation-handoff.md` утверждает Fixed для F01–F10;
новый отчёт уточняет оставшиеся ветки, не разрешает откатывать полезные исправления.

## Очерёдность исполнения

1. **C01: corpus date/partition/retention.** Начать с красной регрессии January2025,
   затем все месяцы и maintenance. Нужны согласованные schema/config изменения;
   нельзя просто premake старые партиции, которые завтра снова удалит retention.
   Исторические даты не заменять текущими. Использовать новую forward migration.
2. **C02–C05: crawl lifecycle/completeness.** Bounded archive checkpoint и durable recovery,
   operator debt без busy loop, deadline/attempt accounting деталей, supplier tab failure
   отдельно от законного empty/not-applicable. Фактические HTTP retries учитываются квотой.
3. **C06–C10: ownership/terminal states/budgets.** Атомарный fencing всех effect paths;
   extraction finalization/repair stranded rows; lease exhaustion terminalization;
   временный timeout не становится unprocessable; реально передаваемые transport deadlines.
4. **C11–C15: formats/storage/temp.** Solid 7z, pre-inflate GSFX limits, bounded XLSX
   metadata/grid, corrupted object recovery с concurrent writer safety, cleanup каталогов.
5. **D01 и тестовые контракты.** Runbook; BudgetExhaustionTest fixture и
   FetchProcurementDetailJobTest asynchronous chain. Не ослаблять guards/защиты и не
   удалять поведенческие assertions вместо корректной проверки новой цепочки.

Связанные изменения объединять по контракту; не делать один непросматриваемый patch на всё.
Если используется исполняемый план — создать новую работу через native Task plan workflow,
не редактировать статусы завершённых планов задним числом. Task checks выполнять в порядке
AGENTS.md. Этот handoff — задание, а не автоматически созданный исполняемый plan.

## Навыки и источники

Применить подходящий task development workflow и проектные skills по участку:
`.claude/skills/procurement-pipeline-dev/SKILL.md`, `testing-rules`, `estimate-extraction-dev`,
`llm-layer-dev`; production-readiness, database/migration, testing isolation и style skills.
Читать нужные инструкции до работы, generated bundles не менять.

Главные документы: `docs/02_Workflow/crawling-and-discovery.md`,
`docs/02_Workflow/document-and-estimate-pipeline.md`, `docs/03_Dev/runbooks/archive-run.md`.
Главный перечень сценариев/строк/приёмки — новый audit report.

## Верификация

- Сначала детерминированная regression по одному контракту, затем реализация.
- Вне штатного suite лежит `research/FinalCorpusAuditProbeTest.php`: его нынешние ожидаемые
  результаты подтверждают **ошибки**. Не принимать GREEN этого файла за готовность.
  Перенести исправленные сценарии в Pest `test('<русский intent>', ...)` с factories.
- Перед каждым DB прогоном — `make test-preflight`, только `agelto_test`, serial либо
  существующая доказанная parallel isolation. Не использовать dev/prod DB для проб.
- Resource/OOM/timeout probes — отдельные процессы, временные изолированные каталоги,
  локальный/fake HTTP и FakeLlmProvider. Общий Horizon не убивать ради unit test.
- C02/C04/C06/C08/C10 требуют реальных worker/concurrency seams; одного прямого handle()
  и config arithmetic недостаточно. C01 — maintenance с сохранением данных 2025.
- Финально повторить расширенный профиль из audit report и полный no-TIA suite проекта,
  PHPStan, Pint по изменённым PHP, diff check; документировать точные команды и exit/results.
  Не объявлять весь suite зелёным по успешной узкой выборке.

## Граница результата

Сдать remediation report с C01–C15/D01 → изменения → тест → остаточные ограничения.
Найденный ложный positive можно закрыть только конкретным воспроизведением/кодовым
доказательством. Сбой инфраструктуры не считать зелёной проверкой.

Не запускать массовый сбор 2025, production migration/deploy или платные LLM вызовы
как часть локального исправления. Не обещать 24/7 по коротким fake тестам. После локальной
ремедиации подготовить конкретный ограниченный rollout/soak: реальная выборка каждого
месяца/статуса/формата, проверка полноты, очередь и её возраст, retries/permanent failures,
RSS/disk/inodes, request budget, recovery после worker restart, ETA годового корпуса.
Production target и окно длительного прогона в текущей сессии не предоставлены.

## Текст для запуска новой сессии Sol

```text
Выполни ремедиацию по research/2026-09-12-final-corpus-audit/sol-handoff.md.
Используй Sol; исправь C01–C15 и D01, восстанови два падающих тестовых контракта,
проведи указанные проверки и запиши итоговый remediation report.
Сохрани существующие dirty изменения предыдущего аудита. Продолжай до завершения
локальной реализации; production deploy, массовый crawl и платные LLM не запускай.
```
