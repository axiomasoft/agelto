---
audience: dev
type: runbook
status: draft
---

# Runbook: анализ завис

## Symptoms

Документ/анализ не двигается по статусам (`document-pipeline.md` Д1) дольше ожидаемого: висит на
`Prepared`/`Screened` без перехода в терминальный статус, `EstimateExtractionJob` в очереди
`ai_extraction`, а `analysis.progress` не обновляется.

## Triage

1. `make artisan ARGS="pipeline:status"` — общий срез по статусам документов/анализов.
2. `make artisan ARGS="pipeline:stuck-check"` (`PipelineStuckCheck`) — целевые находки: зависшие
   `Screening`/`Extraction` (пороги `analysis_stuck_minutes`/`checkpoint_stuck_minutes`,
   `config('ai.pipeline.*')` — **не откалиброваны на живом трафике**, открытый вопрос плана),
   зависшие документы, всплеск `unknown`/`unprocessable`, EOL-модели маршрута, backlog-спайк,
   рост доли failures, «нет завершений за окно». Находки агрегируются в одно Telegram-сообщение
   (`PipelineAlert`, антиспам раз в час на находку).
3. Проверить lease: зависший анализ обычно означает, что предыдущий worker упал, не освободив
   lease/не откатив статус (падение контейнера, OOM, SIGKILL раньше `stop_grace_period` — см.
   [`horizon-backlog.md`](horizon-backlog.md)). Read-only команда сама НЕ чинит состояние — только
   сигналит (docblock `PipelineStuckCheck`).

## Fix

- Технический сбой (временный) — `pipeline:retry-failed [--limit=]`: ретраит `Failed` по
  backoff-расписанию (`next_retry_at`), НЕ трогает `Unprocessable` (это не сбой, а константация —
  см. `../document-pipeline.md` §Статусы).
- Зависшая `EstimateExtractionJob` (lease не освобождён, но job физически не выполняется) — после
  подтверждения, что worker действительно мёртв (не просто долгий legit checkpoint-цикл), диспатч
  вручную через `make artisan` для конкретного документа/анализа (точный сценарий зависит от
  причины — сверить `analysis.progress` перед ручным вмешательством, чтобы не потерять чекпоинт).

## Rollback

Ручное вмешательство в статус документа/анализа — крайняя мера: предпочтителен штатный ретрай
(`pipeline:retry-failed`) вместо прямого `UPDATE`, чтобы не сломать инварианты идемпотентности
(`../document-pipeline.md` §Инварианты идемпотентности).

## Escalation

Находки `pipeline:stuck-check` повторяются после `retry-failed`/рестарта worker'ов, ИЛИ порог явно
некалиброван и генерирует шум — эскалация разработчику (пороги в `config/ai.php`, ключ `pipeline.*`
— калибровка вне scope этого runbook'а, открытый вопрос плана EST-AI).
