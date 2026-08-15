---
id: D41
date: 2026-08-09
status: accepted
item: P11.3
items: [P11.2, P11.3, P11.4, P11.5, P11.6, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Test isolation имеет три fail-closed линии: forced `phpunit.xml` + pre-autoload bootstrap; exact
booted-config guard для serial `agelto_test` либо `agelto_test_<active Laravel token>`; локальные
process-scoped roots для всех app-owned writable disks/log/temp carriers. `Storage::fake()` остаётся
тестовым инструментом, но не safety boundary. Guard доказывается sabotage-тестами на несуществующей
non-test DB и forbidden remote/out-of-root disk до любой мутации. Laravel PAO остаётся единственным
agent-output wrapper; Docker/Make передаёт все детекторы установленного `laravel/agent-detector`,
включая Codex, а human output не меняется. RAG:✅ 2026-08-09 official Laravel parallel-testing and
Laravel PAO docs; repo carrier analysis — `findings/P11-pest-ci-recon-2026-08-09.md`.

## Почему

Suffix-only DB check не различает канонический tokenized database и произвольное имя, а per-test
fake не ловит забытый filesystem stub. Текущий Make marker list не активирует PAO внутри Codex
container run, поэтому agent output остаётся шумным, хотя пакет уже установлен.

## Consequences

Parallel mode нельзя принять до успешного negative/positive guard corpus. Все локальные/cloud disk
имена сохраняются для behavior tests, но в testing environment их реальные adapters/roots обязаны
быть локальными и process-scoped; тест, которому нужен S3 protocol, использует fake/emulator явно.
Второй formatter package и ослабление DB/media guards запрещены.
