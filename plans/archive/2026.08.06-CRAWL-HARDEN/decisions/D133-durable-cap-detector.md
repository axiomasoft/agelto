---
id: D133
date: 2026-08-09
status: accepted
item: P10.2
items: [P10.2]
supersedes: []
superseded_by: null
---
## Решение

**`ListingCursor` становится полным носителем cap-detector state: `lastDedupSignature` и
`blocksInInterval` сериализуются вместе с позицией. После ingest exact-repeat проверяется раньше
общего `rowsNew=0 && rowsUpdated=0`; счётчик archive сбрасывается только при drain/split/pop
текущего интервала.**

## Почему

Реальный cap ЕИС — повтор предыдущего блока; после первого ingest повтор закономерно выглядит
неизменившимся. Локальные переменные исчезают на checkpoint и позволяют обходить safety cap
одним запросом на continuation. **Consequences:** старые cursor JSON остаются читаемыми через
default values; exact same-row fixture обязателен; fresh и archive доказывают ветвление с
checkpoint между исходным и повторным блоком.

