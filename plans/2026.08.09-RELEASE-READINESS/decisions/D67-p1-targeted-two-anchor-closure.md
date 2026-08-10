---
id: D67
date: 2026-08-10
status: accepted
item: P1.7
items: [P1.1, P1.2, P1.3, P1.4, P1.5, P1.6, P1.7]
supersedes: [D66]
superseded_by: null
---
## Решение

Владелец отклоняет ложную развилку AUDIT-CAP «принять долг F11–F14 либо убить фазу» и
разрешает один targeted closure без нового audit. D66 superseded в части single-anchor topology,
повторного audit-gate и активного deviation; исторические product/journal/audit facts остаются
append-only provenance.

P1 закрывается двумя ролями evidence. `7f203014` остаётся product-origin commit удаления aliases.
Отдельный более поздний committed closure anchor обязан содержать P1.7 spec, D66/D67, bundles и
audit provenance; product paths на нём byte-identical `7f203014`. На closure anchor полный
`docker/tests/p1-release-contract.sh`, Task views, strict citations и lint обязаны быть GREEN.
Только после этого разрешены append-only correction events P1.1–P1.7 и green phase close.

## Почему

P1.7 включил изменяемые product-файлы в digest bundle и потребовал свежесть этого bundle на том же
product commit. Любая mutation меняет digest и самоинвалидирует single anchor. Кроме того,
`7f203014` предшествует своей spec и не может быть portable Task Contract anchor. Повторять тот же
carrier ещё одним audit/remediation слоем бессмысленно; разделение product provenance и closure
validation устраняет оба дефекта без нового product change.

## Alternatives

1. Принять F11–F14 как долг и открыть P2 — отклонено владельцем.
2. Убить P1 при доказанном product результате — отклонено владельцем.
3. Создать ещё один audit/remediation-item — отклонено: повторяет symptom loop.
4. Один committed closure anchor поверх неизменного product tree и append-only corrections — выбрано.

## Consequences

Product code, Make/Compose/Vite semantics и `7f203014` не меняются. Исторические RED audit blocks и
journal events не удаляются. После GREEN closure carrier P1.1–P1.7 получают точные correction
anchors, P1 становится `🟢 Done`, material Known Deviations/Pending Work пусты и P2.1 можно запускать
сразу после `task:plan-close ... P1`; новый audit P1 запрещён этим owner-directed closure.
