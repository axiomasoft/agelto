# HANDOFF — 2026-08-06 — after P8.4

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P8.5

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | ноль реальных LLM-вызовов; нужен `docker exec agelto_app …` (обход чужого дифа `docker-compose.yml`) |
| Context | continue (/clear) |
| Суть | Filament-ресурс read-only просмотра смет с колонкой пометки (причина+поля, провенанс) и фильтром «только помеченные» поверх индекса P8.4 |
| Команда | Routing P8.5 = `implementation/medium`, `Exec = plan-exec` — но соседи по очереди (P8.4/P8.6) `manual/high`, батч не собирается (solo) |

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P8.5
```

**Done:** P8.4 закрыт 🟠 2026-08-06 заходом `plan-run` (сессия sonnet/high, совпадает с Routing
implementation/high — без перебора тира). Item-коммит **`1486814`**, bookkeeping-коммит — следом за
этой перезаписью.

Персист носителя неопределённости уровня позиции реализован полностью: миграция
`2026_08_06_100077_add_quality_to_prc_estimate_items_table` — `prc_estimate_items.quality` (jsonb,
nullable) + частичный btree `prc_estimate_items_quality_index ON (estimate_id) WHERE quality IS NOT
NULL` (потребитель — фильтр P8.5) + `prc_estimates.validation_notes` (jsonb, nullable). Сборщик
`EstimatePersister::itemAttributes()` получил необязательный второй параметр `?ValidationReport
$report` и приватный `rowQuality()`, мёржащий контрактную пометку модели (`EstimateRow::$quality`,
P8.3) и построчную карту причин валидатора под ключами `model`/`validator` (провенанс — ключ
верхнего уровня, `research/12-uncertainty-model.md` §3.2); `quality` пишется ВСЕГДА, в т.ч. явным
`null`. `ValidationReport` дополнен ДВУМЯ параллельными построчными картами — `rowReasons` и
`rowNotes` — заполняемыми одним вызовом на строке обнаружения аномалии (без разбора текста notes).
`RerunRangeAction`/`EstimateStoreRepository` — 0 диффа: правило re-run (`model` перезаписывается,
`validator` исчезает) и маршрут второго писателя уже следуют из необязательного параметра.

**По ходу найден и исправлен load-bearing дефект:** каст `AsCollection::class` безусловно
JSON-кодирует значение на записи — PHP `null` уходил в БД как JSON-строка `"null"`, а не SQL `NULL`,
что сделало бы партиционирующий индекс `WHERE quality IS NOT NULL` вечно пустым и структурно
ломало бы инвариант И-А (§6.1). Заменено на примитивный каст `'collection'` (`Item::$quality`,
`Estimate::$validation_notes`) — null-safe по конструкции Laravel (`HasAttributes::setAttribute()`/
`castAttribute()`). Подробности — phases/P8.md P8.4 Completion Notes.

**Known Deviations P8.4 (статус 🟠, не 🟢) — два, детали в `phases/P8.md`:**

1. Новый тест-файл лежит по пути `tests/Feature/Procurement/Analyzers/Estimate/
   EstimateQualityPersistenceTest.php`, а не по буквальному пути `Files` item'а
   (`tests/Feature/Procurement/Estimate/<Subject>Test.php`) — скорректировано под действующую
   layer-mirror-конвенцию (тот же каталог, что `EstimatePersisterTest.php`/`RerunRangeTest.php`);
   каталога из буквального пути не существует.
2. `ValidationReport` заведена ВТОРАЯ параллельная карта `rowNotes` сверх буквальной формулировки
   Scope Included п.4 («построчная карта причин», одно поле) — прямое следствие формата хранения
   §3.2 `research/12` (`validator.notes` документирован как отдельный подключ), без которого поле
   было бы нереализуемо без запрещённого разбора текста.

**Remaining:**

- **Фаза P8** — items P8.5–P8.7 (счёт 2/7 — счётчик считает только `🟢`, P8.3/P8.4 закрыты `🟠`).
  Очередь по данным: P8.5 (витрина) → P8.6 (gold/eval) → P8.7 (доки+приёмка фазы).
- Фазы P1–P7 терминальны (`🟠`) — работы по ним не осталось.

**Sources of truth:**

- `research/12-uncertainty-model.md` §3.2/§3.3/§7/§8 — форма хранения, правило слияния и re-run,
  карта следствий (P8.4 закрыт по ней без отклонений формы).
- `phases/P8.md` P8.4 — Completion Notes/Known Deviations/Pending Work (закрыт); P8.5 — Scope
  Included готово, читать целиком; `## Phase Status` фазы (2/7 по `🟢`).
- `plan.md` — §5 D153–D158, §4 Status Board (P8 `🟡` 2/7), §6 Update Log, §3 Routing (P8.5 —
  `implementation/medium`, `plan-exec`, `full`, без изменений).
- `app/Services/Procurement/Analyzers/Estimate/EstimatePersister.php` — `itemAttributes()`/
  `rowQuality()` (`:212`/`:237`), единственный сборщик формы хранения `quality`.
- `app/Models/Procurement/Estimate/Item.php`/`Estimate.php` — каст `'collection'` (не
  `AsCollection::class`) на `quality`/`validation_notes`, докблок объясняет почему.

**Open risks:**

- **Найденный вне Scope дефект (Pending Work P8.4):** `Item::$attributes` (и любой другой nullable
  jsonb-столбец с кастом `AsCollection::class` в проекте) несёт ту же null-unsafety — не триггерит
  дефект сегодня (нет потребителя `WHERE attributes IS NOT NULL`), но не блокер. Фикс — тот же
  примитивный `'collection'`/`'array'` точечно на столбце, когда появится потребитель null-семантики.
- Открытые риски P8.1–P8.3 (генератор схемы не сужает enum; стоимость формы `response_schema`
  +9.6%; `pdf_ocr` не отдаётся для голых сканов; И-Б непроверяем сегодня) — без изменений, см.
  предыдущий handoff/Completion Notes соответствующих items.
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
  на eval-контуре, D148; `CoreKnowsNoDocumentTypeTest` — пред-существующее падение, вне Scope этого
  плана, воспроизведено ДО P8.4).

**Workarounds/Deferred/Open questions:**

- *Workarounds:* команды идут `docker exec agelto_app …` вместо `docker compose exec app …` —
  вынужденный обход чужого дифа `docker-compose.yml` (Validation P8.4 написана в форме `docker
  compose exec app …` — тот же обход применим и там).
- *Deferred:* пересмотр `ai.representations.policy` для PDF-сканов; режим bench «без gold» (D151,
  вариант c); вторая model-origin причина в enum; точечный фикс null-unsafety `AsCollection::class`
  на `Item::$attributes` и других столбцах — до появления потребителя null-семантики.
- *Open questions:* без изменений (OQ-4/5/6/7/10).
