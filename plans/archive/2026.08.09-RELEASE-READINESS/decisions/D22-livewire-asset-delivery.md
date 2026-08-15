---
id: D22
date: 2026-08-09
status: accepted
item: P3.2
items: [P3.1, P3.2, P3.3, P3.5, P12.4, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Locked Livewire 4 dynamic hashed routes остаются обязательным supported path; Nginx пропускает
отсутствующие static-looking Livewire JS/CSS к Laravel front controller, но любой неизвестный asset
остаётся настоящим 404. Filament assets получают один явный sync/check/repair carrier, который
используют Composer/image build и operator flow. Публикация Livewire в `public/vendor/livewire`
не становится вторым обязательным carrier без terminal P0.4 verdict и superseding D-record.
RAG:— (repo-grounded: `findings/P3-livewire-readiness-recon-2026-08-09.md`, locked Livewire/Filament source).

## Почему

Текущий 404 согласуется с Nginx static regex без fallback, тогда как Livewire 4 регистрирует core и
component assets как application routes. «Всегда publish» скрывает дефект для core script, но не
закрывает component JS/CSS routes и создаёт риск stale public manifest.

## Consequences

Часть asset requests обслуживается PHP; HTTP contract tests обязаны контролировать cache/MIME/status.
Если P0.4 докажет обязательность published Livewire assets для выбранного production mode, P3
останавливается и supersedes D22 до изменения реализации.
