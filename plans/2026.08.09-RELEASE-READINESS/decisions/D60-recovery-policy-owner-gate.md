---
id: D60
date: 2026-08-10
status: proposed
item: P0.6
items: [P0.6, P12.3, P12.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

До закрытия P12.3 владелец фиксирует RPO, RTO, retention, off-host destination и restore cadence.
P12.5 доказывает выбранную policy isolated restore receipt и delivery test; P12.7 не выдаёт READY
без принятого либо superseding D-record. RAG:✅ 2026-08-10 для backup→restore mechanics и
[UNVERIFIED] для конкретных operational values (`findings/P0-rag-infrastructure.md`, INF-09).

## Почему

Технические inputs подтверждают restore mechanics, но не дают права изобрести бизнес-значения
RPO/RTO/retention или выбрать off-host provider; эти значения принадлежат владельцу.

## Alternatives

1. Только local backup на production host. Цена: простота, но потеря host/volume уничтожает и
   рабочие данные, и recovery copy.
2. Local + off-host copy с owner-defined retention/RPO/RTO. Цена: credentials, storage cost,
   monitoring и регулярные restore rehearsals.
3. Provider-managed backup как единственный carrier. Цена: vendor-specific guarantees и restore
   API должны быть отдельно подтверждены; текущие inputs не называют provider.

## Owner gate

Owner выбирает policy и значения до P12.3. P0.6 не принимает их самовольно; до решения frozen
contract остаётся fail-closed по READY, но implementation P1–P11 не блокируется.

## Consequences

Предпочтительная evidence-backed форма — альтернатива 2, но статус остаётся `proposed` до owner
decision. Backup artifact без успешного isolated restore не считается recovery evidence.
