<!-- plan-projection
projection: decision-current
projection_version: v1
source_scope: decisions/
through: 2026-09-11/D12
inputs_sha256: 9b5bb1c6b4e559284217282d604b7d3010b1139c97cd626d121aca7648595e4d
generated_by: task plan-views decisions
-->

# Текущие решения

> Не править руками: это генерируемый вид.

| ID | Date | Status | Items | Решение |
|:--|:--|:--|:--|:--|
| D1 | 2026-09-10 | accepted | P0.1, P1.1, P1.2, P1.3, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3, P3.4, P3.5, P4.1, P4.2, P4.3, P4.4 | Сохранить текущую PostgreSQL/Horizon архитектуру и исправить A1–A9, B1, NEW-1/NEW-2 в пяти зависимых фазах. Терминальный `2026.08.06-CRAWL-HARDEN` используется как provenance и не переоткрывается. Design-сессия меняет только документацию и carriers нового плана. |
| D5 | 2026-09-10 | accepted | P4.3, P4.4 | B3 выполняется отдельным P4.4 после controlled-production decision и не является prerequisite P4.3. До P4.4 текущие raw paths нельзя называть immutable audit trail. |
| D7 | 2026-09-10 | accepted | P2.2, P4.2 | `prc_purchase_items` и `prc_procurement_parties` остаются current projections по смыслу D3: P2.2 реализует атомарный reconcile только по complete snapshot, а P4.2 независимо принимает B1. P4.1 не несёт B1-обязанностей. |
| D8 | 2026-09-10 | accepted | P2.3, P4.1, P4.2, P4.3 | Условный B2 contract D4 исполняется в точном порядке: P2.3 запечатывает fail-closed protocol; owner-authorized P4.1 собирает live revision-pair evidence и при недостаточном evidence внедряет bounded revalidation; P4.2 независимо принимает результат; P4.3 не стартует без terminal P4.1 с resolved B2 и GREEN P4.2. |
| D9 | 2026-09-10 | accepted | P0.1, P1.1, P1.2, P1.3, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3, P3.4, P3.5, P4.1, P4.2, P4.3, P4.4 | Порядок фаз D2 сохраняется, а внутренний порядок P4 уточняется по детальным ТЗ: partitions → crawling completeness/concurrency → trusted snapshots/current lifecycle → recovery/storage/timeouts → live B2 verification/remediation → independent acceptance → owner-authorized controlled production → nonblocking B3 observability. |
| D11 | 2026-09-10 | accepted | P1.1, P1.2, P1.3, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3, P3.4, P3.5, P4.1, P4.2, P4.3, P4.4 | Ремедиация design audit #1 не меняет D8/D10, а доводит их ownership до исполняемых carriers. Каждый `Inputs` P1.1–P4.4 называет точный path либо D-record; predecessor terminality не пересказывается прозой, а потребляет state/phase-closure carrier. Будущий predecessor output остаётся явным путём, а его bundle отложен до появления и terminal commit производителя; пустые placeholder files запрещены. P4.1 — единственный owner любого live ЕИС traffic до controlled production: он собирает A1 boundary-day/page evidence и B2 revision-pair evidence, исполняет обе D8 ветви и производит digest-linked `artifacts/ → findings/ → research/`. P4.2 не делает сетевых запросов, потребляет terminal P4.1 state и оба machine-readable live artifact, пишет только acceptance/evidence/synthesis carriers. P4.3 применяет D10 только после terminal P4.1, GREEN P4.2 и digest-bound owner authorization. RAG:— (repo-grounded: `handoff.md`, Design audit #1; `research/01-remediation-architecture.md`; D8; D10). |
| D12 | 2026-09-11 | accepted | P4.3 | Владелец явно отказал в production-доступе и в 72-часовом окне. P4.3 выполняет максимально безопасный local-only scope и закрывается терминально как `🟠 Done with deviations`. Отсутствие production-замера остаётся явным отклонением и не становится production GO, SLA или waiver технического факта. |
