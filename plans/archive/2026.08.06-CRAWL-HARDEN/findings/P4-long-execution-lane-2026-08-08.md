# Findings — long execution lane для архивного профиля

Дата: 2026-08-08 · Основание: owner gate после третьего аудита P3.

## Verdict

P3 принимается `🟠 Done with deviations`; F1 аудита не является «бюджетом всего crawl».
Проблема — пятиминутный lease одной `CrawlRunJob`, который archive-процессор не умеет добровольно
завершить и продолжить. Исполнимый follow-up — P4.7 перед остальными items P4.

## Решение

| Контур | Профиль | Connection / queue | Timeout | Retry after |
|:--|:--|:--|--:|--:|
| `standard` | default для всех профилей | `redis` / `crawl` | 300 с | 360 с |
| `long` | временный архив 2025 с явными датами | `redis_crawl_long` / `crawl_long` | 3600 с | 3900 с |

Профиль хранит enum lane, а не секунды. Старт, рестарт, resume и continuation автоматически идут
по lane профиля. Детальные карточки и сметы не переводятся в long. Отдельный Redis не требуется;
отдельные logical connection и supervisor обязательны из-за разного `retry_after`.

## Долг после решения

- F2: устаревший docblock `ArchiveWindowScanner` исправляется в P4.7.
- F3: агрегат Phase Handoff P3 синхронизирован решением владельца; историческое поле P3.9 не
  переписывается.
- F4: недостающие числа Validation P3.10 уже перепроверены аудитом; это отчётный, не продуктовый
  дефект. Нового реопена нет.
- Универсальный checkpoint-requeue archive/delta/backlog по секундомеру не реализуется P4.7 и
  остаётся осознанным отклонением. Временный архивный CSV-профиль закрывается отдельным long lane.

`RAG:— (repo-grounded: plans/2026.08.06-CRAWL-HARDEN/phases/P3.md Audit P3 2026-08-08;
research/12-p4.7-long-execution-lane.md; config/horizon.php; config/queue.php;
app/Jobs/Procurement/CrawlRunJob.php)`
