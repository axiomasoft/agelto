<!-- plan-projection
projection: decision-archive
projection_version: v1
source_scope: decisions/
through: 2026-09-11/D12
inputs_sha256: 9b5bb1c6b4e559284217282d604b7d3010b1139c97cd626d121aca7648595e4d
generated_by: task plan-views decisions
-->

# Архив решений

> Не править руками: это генерируемый вид.

| ID | Date | Status | Items | Решение |
|:--|:--|:--|:--|:--|
| D2 | 2026-09-10 | superseded | P0.1, P1.1, P1.2, P1.3, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3, P3.4, P3.5, P4.1, P4.2, P4.3, P4.4 | Порядок фаз фиксирован: partitions → crawling completeness/concurrency → trusted snapshots/current lifecycle → recovery/storage/timeouts → independent acceptance/live/controlled-production. |
| D3 | 2026-09-10 | superseded | P2.2, P4.1 | `prc_purchase_items` и `prc_procurement_parties` считаются current projections и синхронизируются с последним подтверждённым полным snapshot: отсутствующие строки удаляются/деактивируются в той же transaction; incomplete/failed snapshot не меняет active set. B1 не исключается из pilot scope. |
| D4 | 2026-09-10 | superseded | P2.3, P4.2, P4.3 | Не считать ЕИС `uid` гарантией неизменности bytes. До controlled production P4.2 проверяет все доступные attachment revision pairs выбранного live-scope. Любой same-uid/different-bytes факт обязывает bounded revalidation до P4.3. Если подходящих replacements нет или evidence недостаточно, гейт тоже fail-closed к bounded revalidation; только наблюдённые changed-uid pairs допускают pilot с явной документационной оговоркой, но не создают универсальной гарантии. |
| D6 | 2026-09-10 | superseded | P4.1, P4.2, P4.3 | Controlled-production gate — owner-authorized 72-часовое ограниченное окно после GREEN P4.1 и resolved P4.2. Закрытие требует непрерывного evidence по run completeness, retries, orphan states, storage truth, backlog age и ручной сверке всех выбранных контрольных изменений; необъяснённый разрыв, растущий хвост или ложный lifecycle возвращает verdict RED/ATTENTION, не waiver. |
| D10 | 2026-09-10 | superseded | P4.1, P4.2, P4.3 | Controlled-production gate остаётся owner-authorized 72-часовым ограниченным окном D6, но точные prerequisites таковы: P4.1 terminal с resolved D8/B2 verdict, P4.2 GREEN свежим независимым actor/context, затем durable owner authorization на environment/profile/scope, request ceilings и reversible stop action. |
