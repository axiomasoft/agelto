# HANDOFF — 2026-08-06 — after P8.1

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P8.2

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | нет платных вызовов, нет сетевого доступа — item проектный (research-doc + D-записи) |
| Context | continue (/clear) — ручной item |
| Суть | Спроектировать модель неопределённости сметной позиции (`research/12-uncertainty-model.md`): enum причин, форма `RowQuality`, провенанс, уровень сметы, границы «что НЕ помечаем», карта следствий P8.3–P8.6. Гейт владельца по ЧЕТЫРЁМ предметам (D146) обязателен до закрытия |
| Команда | — (`Exec = manual`: `task:plan-exec` отказал бы по undershoot-гейту §9, Routing P8.2 = frontier/high) |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P8.2
```

**Done:** P8.1 закрыт 🟢 2026-08-06 заходом `plan-run` (сессия sonnet/high). Item-коммит
**`f42523e`**, bookkeeping-коммит — следом за этой перезаписью. Фактура:

- **Шаги «г»–«д»:** `estimate:gold:import --code=EST-PDF-SCAN-002 --prepare` — рабочая копия
  (`document_id=67`/`version_id=63`), `unprocessable=0`; D139-контроль `prc_ai_dataset_examples`
  ДО/ПОСЛЕ — `0`/`0` (гейт не нарушен).
- **Шаг «е»:** подготовка произвела ДВА представления — `pdf_ocr` (49 565 772 байт) + `text`
  (193 626 байт); `document.status=downloaded` (перевод в `prepared` — обязанность
  `PrepareDocumentJob`, не вызванного этим путём, факт не дефект).
- **Шаг «ж» — находка (D152 подтверждён, деталь новая):** `RepresentationResolver::forLlm()` для
  голого PDF-скана возвращает ОРИГИНАЛ версии (49 297 847 байт), НЕ `pdf_ocr` — политика
  `config('ai.representations.policy')` для расширения `pdf` ищет только вид `RepresentationKind::Pdf`
  (производит исключительно `SpreadsheetStrategy`, не `ScanStrategy`) и падает на
  `fallback_to_source`. `ExtractionRoute::Whole` подтверждён на этом фактическом файле.
- **Шаг «з»:** платный прогон зондом `artifacts/p8_scan_probe.php` (резюмируемый — checkpoint-
  continuation, 5 запусков из-за повторных `cURL error 28` сетевых таймаутов среды, 5 из 12
  суммарных вызовов провалились инфраструктурно). Остановлен на `capped=true,
  cap_reason=infra_error` (Validation OR-ветка «упёрлись в кап», принята как валидный финиш) — 134
  позиции `row_type=item` из ожидаемых моделью 254, $0.345 расхода.
- **Главная находка (п.4):** модель читает `name`/`code`/`unit` на уровне фонового профиля §2
  (0% null), но МОЛЧА обнуляет числовые поля — `quantity` 0%→100%, `total_price_curr` 22.9%→100%,
  `unit_price_curr`/`total_price_base`/`index_code` тоже подняты; `incomplete_items=0`, ни одного
  текстового сигнала о нечитаемости. Подтверждает Open risk «модель может молчать про нечитаемое» —
  избирательно (текст да, числа нет).
- **п.5:** живой корпус P4 (66 документов, `procurement_id` есть) — деградированных (скан/без
  текстового слоя) документов НЕТ (0 `pdf_ocr`/`text` среди 62 версий с представлениями; 31 `pdf` —
  XLSX-конверсии, не сканы). Оговорка: 23/66 документов ещё не прошли `DocumentPreparer`.
- Полная фактура, числа с якорем дерева — `findings/P8-degraded-source.md`.

**Remaining:**

- **Фаза P8** — items P8.2–P8.7 (счёт 1/7). P8.2 несёт гейт владельца на четыре предмета (D146) —
  item не закрывается без вердикта владельца по каждому.
- Фазы P1–P7 терминальны (`🟠`) — работы по ним не осталось.

**Sources of truth:**

- `phases/P8.md` P8.1 — Completion Notes/Known Deviations (закрыт); P8.2 — Scope Included/
  Implementation Rules 4 (гейт владельца); `## Phase Status` фазы (1/7).
- `plan.md` — §4 Status Board (P8 `🟡` 1/7), §6 Update Log (запись закрытия P8.1), §3 Routing
  (P8.2 — `frontier/high`, `manual`, `full`, без изменений).
- `findings/P8-degraded-source.md` — полный результат P8.1 (§1–§5), вопросы P8.2 в конце файла.
- `roadmap.md` — строка P8.1 (закрыт) и P8.2 (следующий launch-block).

**Open risks:**

- **`RepresentationResolver::forLlm()` не отдаёт `pdf_ocr` для голых PDF-сканов** (находка §3 «ж»,
  `findings/P8-degraded-source.md` вопрос P8.2) — факт конфигурации, не баг; вне Scope P8.1
  (`app/**`/`config/**` не трогались). Требует отдельного решения владельца/item'а, не обязательно
  P8.2.
- **Платный прогон P8.1 не дошёл до `totals_done`/персиста** — если P8.6 или калибровка потребует
  большей выборки, чем 134/254 позиций, может понадобиться отдельный второй платный замер (не по
  вине владельца о достаточности выборки — по инфраструктурной нестабильности среды).
- **Вердикт п.5 «деградированных в P4 нет» — временный**, 23/66 документов ещё не прошли
  `DocumentPreparer`; пересчитать той же командой, если P8.6/дальнейшие items тронут живой корпус.
- Чужой незакоммиченный диф, НЕ ТРОНУТ (`plan.md` §2 Execution Rules — называть, не трогать):
  `M .env.example`, `M docker-compose.yml` (жёсткое `MINIO_ROOT_PASSWORD:?...` без дефолта —
  `docker compose` CLI падает на интерполяции ДО любой команды; контейнеры подняты раньше и
  работают, команды — `docker exec agelto_app …`), `M docs/03_Dev/process/testing.md`, untracked
  `.swissknife.json`/`.swissknife/`/`.swissknifeman/`/`config/cms.php`/`database/seeders/User/`/
  `docker/php/conf.d/pcov-dev.ini`/`estimates/gemini-analys.md`.
- Пустой служебный файл `plans/2026.07.31-SYS-INTEGRITY/artifacts/.write-test` (untracked,
  создан этим заходом для диагностики прав на запись контейнера — не входит в `Files` P8.1, не
  закоммичен; безвреден, можно удалить вручную).
- Условный гейт D138 (не гейт P8.1): полный прогон 86 стр. скан-эталона вне Scope в любом случае.
- Прочие Open risks — без изменений (промты P2.12 не поставлены/OQ-7; миграция P8.4 на БД с боевыми
  данными; качество пометок валидатора неизмеримо на eval-контуре, D148).

**Workarounds/Deferred/Open questions:**

- *Workarounds:* команды идут `docker exec agelto_app …` вместо `docker compose exec app …` —
  вынужденный обход чужого дифа `docker-compose.yml`.
- *Deferred:* режим bench «без gold» (правка `FormatBenchRunner::eligibleExamples()`) — отвергнут
  как предмет research-item'а (D151, вариант c); заводится отдельным item'ом при необходимости.
  Пересмотр `ai.representations.policy` для PDF-сканов (найдено §3 «ж») — тоже отдельный предмет,
  не входит в P8.2 по умолчанию.
- *Open questions:* без изменений (OQ-4/5/6/7/10). Блокер P8.1 закрыт D151/D152, item сам закрыт
  2026-08-06.
