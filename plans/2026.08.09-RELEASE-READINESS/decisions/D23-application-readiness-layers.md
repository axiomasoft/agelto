---
id: D23
date: 2026-08-09
status: accepted
item: P3.3
items: [P3.3, P3.4, P3.5, P11.5, P12.4, P12.7]
supersedes: []
superseded_by: null
---
## Решение

`/up` остаётся lightweight liveness/application-boot endpoint и app container healthcheck. Active
DB/cache/storage/assets/migrations/base/admin/Horizon/scheduler readiness реализуется одной typed
service с agent/JSON command carrier; P3 Doctor diagnostics делегируют ей, а deploy вызывает aggregate
gate явно. Тяжёлые checks не запускаются на каждый HTTP probe. RAG:— (repo-grounded:
`research/05-P3-execution-design.md`, D21, current `bootstrap/app.php` and Compose healthchecks).

## Почему

Привязка liveness к внешним dependencies создаёт restart churn и циклы готовности: Horizon/scheduler
уже зависят от healthy app. Дублирование логики между shell, Doctor и HTTP даст разные вердикты.

## Consequences

Load balancer не получает full-stack verdict только из `/up`; operator/deploy/CI обязаны вызвать
`app:readiness`/Doctor aggregate. Коды, timeouts, cleanup и redaction становятся публичным внутренним
контрактом P3 и должны быть покрыты fixture tests.
