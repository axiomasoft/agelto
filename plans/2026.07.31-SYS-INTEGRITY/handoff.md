# HANDOFF — 2026-08-06 — after P8.5

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P8.6

| Параметр | Значение |
|:--|:--|
| Model class | manual — соседи по очереди (P8.6/P8.7) идут `manual/high`, батч не собирается (solo) |
| Effort | high |
| Context | continue (/clear) — ручной item |
| Суть | Gold и eval: представимость пометок в gold-контракте (расширение эталона под `quality`) и вердикт измеримости на eval-контуре (D148) |
| Команда | — (нет single-command эквивалента: Routing P8.6 = `manual`, `/task:plan-exec` пинит sonnet/medium и по гейту п.2 обязан отклонить этот item) |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P8.6
```

**Done:** P8.5 закрыт 🟠 2026-08-06 заходом `plan-exec` (sonnet/medium, совпадает с Routing
implementation/medium — без перебора тира). Item-коммит **`70811f3`**, bookkeeping-коммит — следом
за этой перезаписью.

Read-only витрина смет реализована полностью: `EstimateResource` (index + view, без
create/edit-страниц — read-only на уровне ресурса) + `EstimatesTable`/`EstimateInfolist`. Счётчик
«помечено N из M» — `EstimateResource::getEloquentQuery()` (`withCount` двумя алиасами), не выборка
позиций — подтверждено тестом на 3→200 позициях одной сметы (число запросов списка не растёт).
Таблица позиций — `ItemsRelationManager` (не секция инфолиста: `RepeatableEntry` не несёт
`->filters()`, а Scope Included требует интерактивный фильтр «только помеченные») с колонкой
пометки: провенанс различим двумя подписями («Не прочитано моделью» / «Нашла проверка сметы»,
формулировки §4 `research/12`), причины и имена полей — человекочитаемые (`RowQualityReason::label()`
— новый метод, единственный носитель перевода причины; статическая карта `FIELD_LABELS` в
`ItemsRelationManager` — единственный носитель перевода имени поля).

**Material-отклонение (🟠, не 🟢):** item-коммит несёт диф сверх буквального `Files` — три
дополнительных файла, функционально необходимых: `app/Guards/Admin/Permissions/EstimatePermission.php`
(без permission-enum'а `guard:sync-roles` не выдаёт `estimate.*` НИКОМУ — ресурс 403 у всех, включая
оператора, прямое противоречие Implementation Rules п.4 item'а «права — по образцу существующего
ресурса панели»), гранты `EstimatePermission::ViewAny`/`::View` в `OperatorRole`/`AnalystRole`
(`AnalystRole` докблок уже называл это наперёд), `RowQualityReason::label()` + `@property-read`
докблоки `items_count`/`marked_items_count` на `Estimate` (без них `phpstan analyse` красный).
Подробности — phases/P8.md P8.5 Completion Notes/Known Deviations.

**Remaining:**

- **Фаза P8** — items P8.6–P8.7 (счёт 2/7 — счётчик считает только `🟢`, P8.3–P8.5 закрыты `🟠`).
  Очередь по данным: P8.6 (gold/eval) → P8.7 (доки+приёмка фазы).
- Фазы P1–P7 терминальны (`🟠`) — работы по ним не осталось.

**Sources of truth:**

- `research/12-uncertainty-model.md` §2/§3/§4 — причины, форма носителя, провенанс (P8.5 закрыт по
  ним без отклонений формы).
- `phases/P8.md` P8.5 — Completion Notes/Known Deviations (закрыт); P8.6 — Scope Included готово,
  читать целиком; `## Phase Status` фазы (2/7 по `🟢`).
- `plan.md` — §4 Status Board (P8 `🟡` 2/7), §6 Update Log, §3 Routing (P8.6 — `manual/high`).
- `app/Filament/Resources/Procurement/Estimates/EstimateResource.php` — витрина смет, единственный
  read-only ресурс сметного домена.
- `app/Enums/Procurement/Estimate/RowQualityReason.php` — `label()` (`:24`), единственный носитель
  перевода причины в человекочитаемый текст.

**Open risks:**

- Найденный дефект NULL-unsafety `AsCollection::class` на других nullable jsonb-колонках проекта
  (Pending Work P8.4) — без изменений, не блокер.
- Открытые риски P8.1–P8.3 (генератор схемы не сужает enum; стоимость формы `response_schema`
  +9.6%; `pdf_ocr` не отдаётся для голых сканов; И-Б непроверяем сегодня) — без изменений.
- Стало неверно ещё при закрытии P8.1, не правлено (вне Scope): абзац под Status Board `plan.md`
  §4 утверждает «До exec фазы остаётся один шаг — `plan-audit design`».
- Чужой незакоммиченный диф, НЕ ТРОНУТ (`plan.md` §2 Execution Rules — называть, не трогать):
  `M .env.example`, `M docker-compose.yml` (жёсткое `MINIO_ROOT_PASSWORD:?…` без дефолта —
  `docker compose` CLI падает на интерполяции ДО любой команды; рабочий обход —
  `docker exec agelto_app …`), `M docs/03_Dev/process/testing.md`, untracked
  `.swissknife.json`/`.swissknife/`/`.swissknifeman/`/`config/cms.php`/`database/seeders/User/`/
  `docker/php/conf.d/pcov-dev.ini`/`estimates/gemini-analys.md`/
  `plans/2026.07.31-SYS-INTEGRITY/artifacts/.write-test`.
- Прочие Open risks — без изменений (промты P2.12 не поставлены/OQ-7; качество пометок неизмеримо
  на eval-контуре, D148 — это ровно предмет P8.6; `CoreKnowsNoDocumentTypeTest` — пред-существующее
  падение, вне Scope этого плана, воспроизведено ДО P8.4 и подтверждено воспроизводимым БЕЗ диффа
  P8.5 (`git stash -u`)).

**Workarounds/Deferred/Open questions:**

- *Workarounds:* команды идут `docker exec agelto_app …` вместо `docker compose exec app …` —
  вынужденный обход чужого дифа `docker-compose.yml`.
- *Deferred:* пересмотр `ai.representations.policy` для PDF-сканов; режим bench «без gold» (D151,
  скан-эталон); OQ-10 (контур операторских правок позиции) — явно назван вне Scope P8.5 (D137).
- *Open questions:* OQ-7 (дедупликация текстов промтов P2.12, чужой долг); D148 (измеримость
  пометок на eval-контуре) — снимается P8.6.
