---
id: D5
date: 2026-08-09
status: accepted
item: P0.6
items: [P0.6]
supersedes: []
superseded_by: null
---
## Решение

Coupling surface фиксируется заранее: Makefile/docker/Compose затрагивают P1–P3/P11–P12;
composer.json — P2/P3/P11; env/secrets — P1–P5/P12; docs — все implementation-фазы;
Filament/navigation/auth — P3–P5/P7–P9/P12; document schema/pipeline — P6–P8/P10–P12;
test/CI commands — P1–P12. Каждая поздняя фаза обязана перечитать итог предыдущего владельца.
RAG:— (repo-grounded: brief/00-brief.md и research/00-user-intent.md).

## Почему

Эти carriers легко перезаписать последующей фазой и получить локально зелёный, но сквозно
несогласованный результат.
