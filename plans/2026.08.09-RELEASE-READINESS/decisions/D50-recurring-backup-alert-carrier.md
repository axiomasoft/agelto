---
id: D50
date: 2026-08-09
status: accepted
item: P12.3
items: [P12.3, P12.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

P12.3 владеет не только первым backup, но и recurring production carrier: owner-approved
systemd service+timer с `Persistent=true`, backup/verify/off-host/retention в одном fail-closed
run и `OnFailure` на fixed alert script. Production profile задаёт schedule/max-age, retention,
disk thresholds и root-only webhook endpoint file. Закрытие требует реального scheduled run,
безопасного prune после verified copy, overdue/disk/failure negative fixtures и delivery test с
correlation ID на test receiver; logging-only alert или delivery failure не принимаются.
RAG:— (repo-grounded: D44, P12.3 and audit finding F3).

## Почему

Разовый restored backup доказывает формат recovery set, но не соблюдение RPO после go-live.
Без timer/watchdog/delivery carrier backup может перестать создаваться или заполнить disk молча,
а P12.7 всё равно увидит один свежий ручной set.

## Consequences

P12.3 добавляет systemd units, fixed alert carrier, profile fields и fail-closed contract tests.
Owner предоставляет policy и endpoint secret вне плана; недоступная delivery либо stale last-success
блокирует readiness. P12.5 продолжает владеть restore, но потребляет уже recurring verified sets.
