---
id: D28
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Остановка прогона — кооперативная, через запрос в БД** (`prc_crawler_runs.stop_requested_at`/`stop_kind`/`stop_requested_by`), читаемый процессорами в той точке цикла, где они УЖЕ делают `$crawler->refresh()`. Отвергнуты: средства Horizon (`horizon:clear` удаляет только не начатые job'ы) и флаг в кэше (не виден в админке, не переживает перезапуск Redis). **Consequences:** остановка отложена на один блок (D26) и требует, чтобы КАЖДЫЙ новый цикл обхода не забыл спросить гейт — это обязанность, которую следующий режим обхода легко пропустит; страховки в виде «убить job снаружи» у нас нет и не будет

## Почему

Остановить выполняющийся job средствами Laravel/Horizon нельзя: `horizon:clear`/`horizon:forget` работают с очередью и упавшими, отмена есть только у батчей и лишь ПРОПУСКАЕТ не начатые job'ы батча. `RAG:✅ 2026-08-06 (laravel-boost search-docs: laravel/framework@13.x queues.md «Cancelling Batches»; laravel/horizon horizon.md «Clearing Jobs From Queues», «Deleting Failed Jobs»)`. Точка проверки бесплатна — обращение к БД в ней уже есть. `RAG:— (repo-grounded: FreshRunProcessor.php:46; ArchiveWindowScanner.php:53)`
