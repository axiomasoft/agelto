---
id: D90
date: 2026-08-15
status: accepted
item: P12
items: [P12, P12.2, P12.3, P12.4, P12.5, P12.6, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Владелец требует выполнить `B12-PREP` по принципу complete-max: последовательно завершить весь
локально достижимый scope P12.2–P12.7, подкрепить его максимально полным доступным исследованием
по первичным источникам и довести каждый локальный validation carrier до зелёного результата.
Отсутствие dedicated server, DNS, credentials, GitHub/registry/CI enrollment, TLS либо runtime
evidence не является причиной для `Blocked`, `Pending Work`, deviation, waiver, остановки batch
или запроса владельцу. Эти факты остаются только явно названными входами отдельного future
operator rehearsal и не снижают current preparation verdict.

Локальный RED также не превращается в terminal block: исполнитель сначала диагностирует и
repair-forward исправляет весь дефект в запечатанных Files текущего item, повторяет carrier и
продолжает цепь. Если обнаружен настоящий cross-phase/frozen-contract конфликт, исполнитель
сохраняет точное исследование и additive decision boundary, выбирает максимально безопасную
локальную реализацию в рамках D89 и продолжает доступную часть batch; он не выдаёт fabricated
runtime proof и не ослабляет security/test guards. RAG:— (repo-grounded: owner clarification
2026-08-15 and D89).

## Почему

Задача текущего P12 — не констатировать отсутствие внешней среды, а снизить неопределённость и
стоимость будущей репетиции настолько, насколько это возможно в репозитории. Явный complete-max
контракт исключает прежнюю ошибку, при которой недоступный внешний факт парковал item вместо
создания применимого локального operator package.

## Alternatives

1. Оставить возможность terminal `Blocked` при отсутствии внешнего состояния — отклонено: это
   противоречит D89 и не производит локальный результат.
2. Считать локальные fixtures production evidence — отклонено: это fabricated proof.
3. Закрывать максимальный локальный scope, чинить локальный RED и выносить только реальные runtime
   measurements в future rehearsal — принято.

## Consequences

P12 phase/specs, roadmap и handoff обязаны явно повторять complete-max/no-external-block правило.
Непосредственный successor design-pass — `task:plan-run ... P12.2-P12.7`; дополнительный `finish`
между готовым design и execution не требуется. P12.7 может выдать `PREPARATION_NOT_READY` только
из-за фактически красного локального contract и остаётся repair-forward до его устранения; пустые
runtime evidence fields, перечисленные как future rehearsal inputs, сами по себе не делают verdict
красным.
