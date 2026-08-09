<!-- plan-projection
projection: decision-index
projection_version: v1
source_scope: decisions/
through: 2026-08-09/D9
inputs_sha256: 7a4ab6333efe651a63dd1954d91289e388a76490f26137011f80087fd1ea96ce
generated_by: task plan-views decisions
-->

# Индекс решений

> Не править руками: это генерируемый вид.

| ID | Date | Status | Items | Решение |
|:--|:--|:--|:--|:--|
| D1 | 2026-08-09 | accepted | — | Исходный голосовой бриф сохранён без потери мыслей в brief/00-brief.md; последующая трассировка требования к фазе живёт в research/00-user-intent.md. RAG:— (repo-grounded: brief/00-brief.md). |
| D10 | 2026-08-09 | accepted | P1.1, P1.2, P1.3, P1.4, P1.5 | Текущий Makefile и docker/Makefile — requirements/reference, не compatibility freeze. Целевой интерфейс можно пересобрать после inventory; сохранение старой цели требует явного решения и теста, а не инерции. RAG:— (repo-grounded: brief/00-brief.md). |
| D11 | 2026-08-09 | accepted | P0.6 | Supersedes D2 только в арифметике skeleton: точный объём — 13 фаз / 74 items. Layout v2, contract-first, Design Passes 1/3 и запрет исполнения до полной детализации остаются без изменений. RAG:— (repo-grounded: status.md). |
| D12 | 2026-08-09 | accepted | — | Supersedes D3 в части отложенного действия: 2026.08.06-CRAWL-HARDEN уже перенесён в plans/archive, его root-материалы мигрированы в project docs, а новый plan ACTIVE. Paused By по-прежнему не требуется. RAG:— (repo-grounded: findings/bootstrap-recon-2026-08-09.md). |
| D2 | 2026-08-09 | superseded | P0.6 | План создаётся сразу в Layout v2, contract-first, с Design Passes 1/3 и запретом исполнения до детализации всех фаз и finish/audit-design. RAG:— (repo-grounded: AGENTS.md и plan.md). |
| D3 | 2026-08-09 | superseded | — | ACTIVE переключается на новый план без Paused By у 2026.08.06-CRAWL-HARDEN. Физическая архивация прежнего плана остаётся отдельной командой plan-close archive. RAG:— (repo-grounded: brief/01-refinements.md). |
| D4 | 2026-08-09 | accepted | P0.6, P12.7 | P0 замораживает факты и contracts до product changes; P12 является единственным финальным production gate и повторно трассирует весь бриф. RAG:— (repo-grounded: research/00-user-intent.md). |
| D5 | 2026-08-09 | accepted | P0.6 | Coupling surface фиксируется заранее: Makefile/docker/Compose затрагивают P1–P3/P11–P12; composer.json — P2/P3/P11; env/secrets — P1–P5/P12; docs — все implementation-фазы; Filament/navigation/auth — P3–P5/P7–P9/P12; document schema/pipeline — P6–P8/P10–P12; test/CI commands — P1–P12. Каждая поздняя фаза обязана перечитать итог предыдущего владельца. RAG:— (repo-grounded: brief/00-brief.md и research/00-user-intent.md). |
| D6 | 2026-08-09 | accepted | P2.1, P2.4, P12.2 | Target carrier private package — отдельный nested clone local/vendors/azguard, не Git submodule и не содержимое основного репозитория; основной repo хранит только пустую директорию. Exact remote, branch/tag и Composer subpaths верифицирует P0/P2 до правки. RAG:— (repo-grounded: brief/00-brief.md и composer.json). |
| D7 | 2026-08-09 | accepted | P4.1, P4.2, P4.3, P4.4, P4.5 | Production documentation build не лежит в public; HTML и assets проходят через одну authorization policy, path containment и explicit cache policy. Конкретный generator/serving mechanism выбирается после P0/P4 RAG и threat model. RAG:— (repo-grounded: brief/00-brief.md). |
| D8 | 2026-08-09 | accepted | P5.1, P5.2, P5.3, P5.4, P5.5 | Runtime precedence фиксируется как profile proxy → optional default proxy → direct. PLAYWRIGHT_MCP_PROXY_SERVER остаётся отдельным tooling input и не участвует в runtime resolve. ProxyResolver/ProxyScope и разделение global/per-target health сохраняются. RAG:— (repo-grounded: brief/00-brief.md и AGENTS.md). |
| D9 | 2026-08-09 | accepted | P3.4, P12.6, P12.7 | Финальная приёмка обязательно начинается с чистого clone и пустых persistent volumes/DB, а production rehearsal включает private package, secrets, migrations, base seeds, admin, assets, docs, queues, storage, doctor и browser smoke. RAG:— (repo-grounded: brief/00-brief.md). |
