# Уточнения брифа

## 2026-09-10 — design pass 1

- Владелец явно потребовал точные file-level ТЗ каждого item и ровно пять названных фаз; поэтому
  все фазы детализируются в одном `new`-заходе, несмотря на штатную рекомендацию разносить 5+
  фаз по отдельным design-сессиям.
- B1 закрывается исправлением current projection, а не исключением items/parties из pilot scope.
- B2 не превращается в недоказанную гарантию: live gate имеет fail-closed ветвь bounded
  revalidation и честную документационную оговорку.
- B3 не является prerequisite P4.3 и планируется после controlled-production decision.

## 2026-09-10 — design pass 2 (`finish`)

- P4 ownership сверено с детальными ТЗ: P4.1 владеет owner-authorized live B2 probe и
  conditional bounded revalidation; P4.2 — fresh independent acceptance; P4.3 — 72-hour gate.
- Stale связи D2–D4/D6 не переписаны задним числом: D7–D10 supersede их и сохраняют
  первоначальные phase/B1/B2/controlled-production semantics при точной item ownership и порядке P4.
- Required Reads P3.3/P4.2/P4.4 доведены до конечных путей; future artifacts остаются
  predecessor-produced inputs и не подменяются пустыми файлами в design.

## 2026-09-10 — repair по Design audit #1

- Владелец задал узкий repair: заменить abstract `Inputs` P1.1–P4.4 на digest-qualified
  carriers, исправить archived path, довести D8/D10 ownership и P4.1 branches, запечатать
  P4.2 evidence/live/Files и knowledge layers; application/tests/config в этом design run не меняются.
- D2/D3/D4/D6 остаются immutable superseded provenance. D11 additive доводит ownership до
  carrier boundaries, не отменяя product decisions D8/D10.
- P4.1 единолично владеет owner-authorized live A1/B2; P4.2 строго offline, потребляет
  terminal P4.1 state и machine-readable A1/B2 evidence, а его `Files` ограничены acceptance carriers.
- После material SHA change план возвращается ровно на один whole-plan design audit;
  implementation до GREEN запрещена.
