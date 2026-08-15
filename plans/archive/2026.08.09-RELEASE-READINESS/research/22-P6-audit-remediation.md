# P6 audit remediation — bounded execution design

## Вход и неизменяемые границы

Источник — `phases/P6/P6.md` → `## Audit P6 — 2026-08-12` и owner scope-payload от
2026-08-12. Исполнение ограничено F1–F5: один P6.7 исправляет GSFX attachment seam и stale
typed-contract tests, затем на одном committed candidate повторяет focused/full no-TIA evidence и
append-only reconciles P6.3–P6.6. D30/D31 не supersede-ятся; semantic taxonomy P7, чужие фазы,
test DB/media guards и generated state/bundle/status carriers остаются неизменяемыми вручную.
RAG:— (repo-grounded: audit scope-payload and current P6 source/tests).

## Сведение F1–F5 в один slice

| Находка | Обязательный результат P6.7 |
|:--|:--|
| F1 | `GsfxStrategyTest`, `ScanStrategyTest` и `SpreadsheetStrategyTest` проверяют `PreparationResult` и registry/inspector admission вместо удалённых boolean `process()`/`canProcess()`; focused suites и полный no-TIA suite зелёные на одном candidate SHA. |
| F2 | GSFX получает полную ZIP listing через `ArchiveExtractor`, до фильтрации `Attachments/**` прогоняет её через `ArchiveEntryValidator`, bounded извлекает все выбранные file entries до первой child mutation и создаёт children только через `ExpandArchiveEntryAction`; blind `extractTo()`, private child persistence и silent unsafe-entry skip удалены. |
| F3 | P6.3/P6.5/P6.6 получают factual append-only actual/prescribed route, exact product/correction anchors, canonical `write-site` codes и current downstream records. Historical P6.3/P6.5 undershoot остаётся `block:ROUTE_UNDERSHOOT / n/a`, а не ретроактивным `allow`; новый P6.7 anchor несёт собственные `allow/allow`. |
| F4 | P6.4 correction явно называет out-of-Files `tests/Feature/Procurement/Pipeline/StuckCheckTest.php`, commit `89b4e793` и ограниченный impact status-consumer alignment; spec/history не переписываются. |
| F5 | Phase Handoff и rolling handoff указывают P6.7, затем Audit P6, заход 2; generated projections создаются только Task generators, отрицательный workflow-триггер удалён. |

RAG:— (repo-grounded: P6 audit F1–F5, product commits `91b18770`/`89b4e793`/
`c6bff21f`/`5045cc85` and current Task projections).

## Product seam и порядок mutation

1. `GsfxStrategy` читает parent через существующий `FileLocationResolver` и сохраняет GSFX
   representations по текущему contract; semantic GSFX outcome остаётся
   `PreparationResult::ReadyForScreening`.
2. `ArchiveExtractor::listEntries($localZip, 'zip')` создаёт одну полную metadata listing.
   `ArchiveEntryValidator::validate(..., ArchiveBudget::fromConfig())` применяется ко всей listing,
   включая non-attachment metadata; только после GREEN выбираются normalized non-directory
   `Attachments/**` entries. Это сохраняет D31 count/size/path/type/encryption/collision/ratio gates.
3. Все выбранные entries bounded извлекаются по одному через `ArchiveExtractor::extractEntry()` во
   временное дерево с общим actual-byte accumulator. До успешного извлечения всего attachment set
   `ExpandArchiveEntryAction` не вызывается, поэтому preflight/extraction reject оставляет zero
   children/events/jobs.
4. После полного extraction каждый entry передаётся `ExpandArchiveEntryAction::execute()` с
   normalized `archive_entry_path`; DB uniqueness остаётся authority, а обычный
   `PrepareDocumentJob` регистрируется Action-ом after commit. Собственный GSFX `indexChild()` и
   blind `ZipArchive::extractTo()` удаляются.
5. `ArchivePasswordProtectedException`, `ArchiveUnsupportedException` и
   `ArchiveValidationException` отображаются в те же machine-readable terminal reasons, что общий
   archive path; unsafe entry не может быть silently skipped. Текущий D30/D31 result/reason keyset
   не расширяется.

## Evidence topology

- Один `docker/tests/p6.7-release-remediation.sh` является item-owned executable carrier: isolated
  PostgreSQL/test-media preflight, focused GSFX/archive/validator/action/registry/three stale suites,
  затем полный `php artisan test --no-tia`, scoped Pint, static no-bypass search, citations, Task
  checks и `git diff --check`.
- Product/evidence commit фиксирует full candidate SHA и exact commands/exits в
  `findings/P6-remediation-evidence.md`. Correction events старых items append-ятся только после
  GREEN candidate и не переписывают прежние события.
- P6.3/P6.5 corrections печатают доказанный historical `block:ROUTE_UNDERSHOOT · n/a`, current
  downstream result и ссылку на P6.7 remediation anchor. P6.6 correction печатает вычисленные по
  actor/route codes, признаёт старое full-suite утверждение невоспроизводимым и связывает новый
  GREEN full-suite с P6.7, не с `5045cc85`.
- P6.4 остаётся terminal deviation с точным out-of-Files path/impact. P6.7 закрывается только со
  своим `write-site: pre_mutation: allow · pre_final: allow`, byte-fresh projections и handoff на
  независимый Audit P6, заход 2.

## DoR verdict

Новых product-развилок нет: D30/D31 и доказанный audit фиксируют architecture и acceptance.
P6.7 — один последовательный security/evidence slice, orchestration не требуется. Routing —
`implementation/high`, `manual`, `Review=full`; scope, anti-scope, exact carriers, тестовый порядок
и truthful append-only reconciliation заданы, уточняющих маркеров нет.
