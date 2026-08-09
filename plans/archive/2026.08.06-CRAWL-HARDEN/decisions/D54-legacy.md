---
id: D54
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Канал уведомления — только Telegram, переиспользуя существующий `App\Notifications\PipelineAlert`; e-mail не заводится.** **Consequences:** почта из брифа («e-mail или Telegram») в плане не появится; если владелец захочет письма, это отдельный item с настройкой SMTP и списком получателей

## Почему

Транспорт уже написан, работает у `pipeline:stuck-check` и несёт per-finding антиспам; почта фактически не настроена — драйвер `log`, ни одного `Mailable` в дереве, то есть письмо ушло бы в лог и создало ЛОЖНОЕ ощущение работающего уведомления. Решение владельца 2026-08-06. `RAG:— (repo-grounded: app/Notifications/PipelineAlert.php:31-90; config/mail.php:17; .env.example:48; grep "Mailable\
