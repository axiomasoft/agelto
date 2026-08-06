# P8-degraded-source — инвентарь потерь сигнала + живая проба скана (P8.1)

**Item закрыт.** Все пять пунктов Scope Included выполнены. Эскалация предыдущего захода снята
D151 (метод: `estimate:gold:import --code=… --prepare` + зонд плана над `ExtractionOrchestrator`,
`phases/P8.md` P8.1 «✅ ЭСКАЛАЦИЯ СНЯТА»). Пункты 1–2 (инвентарь потери сигнала, оффлайн-профиль
пустоты) и подготовка пункта 3 (срез/манифест/фикстура/пре-гейт на исходнике) — из предыдущего
захода, не переделывались. Остаток (п.3 шаги «г»–«з», п.4, п.5) — этот заход, 2026-08-06.
**Материальное ограничение:** платный прогон п.3«з» не дошёл до терминального статуса анализа
(`succeeded`/`skipped`) — среда стабильно отдавала `cURL error 28` (таймаут 150 с) на части
LLM-вызовов (5 из 12 попыток, детали §3); зонд спроектирован резюмируемым (checkpoint-continuation,
не re-roll с нуля) и после нескольких продолжений остановлен на частичном, но представительном
результате (134 позиции из ожидаемых моделью 254, $0.345 расхода) — далее ретраи переставали
менять числа четыре раза подряд, что само по себе диагноз (см. §3, §7 Known Deviations в
`phases/P8.md`). Это не блокер и не искомый негативный результат про модель — это факт про
надёжность сети ЭТОЙ среды, отдельный от вопроса «что модель говорит про нечитаемое» (на который
§3–§4 отвечают уверенно по уже полученным 134 позициям).

## 1. Инвентарь потери сигнала (Scope Included п.1)

| Носитель | Файл:строка | Что означает `null`/потеря сегодня |
|:--|:--|:--|
| `EstimateRow::$unit_price_base` | `app/Dto/Procurement/Analyzers/Estimate/EstimateRow.php:40-42` | Документированно ТРЁХЗНАЧЕН: «пуста у ресурсной сметы... законное состояние» ИЛИ модель не заполнила читаемый источник ИЛИ источник нечитаем. Контракт не несёт поля, различающего эти три случая — это и есть дыра, которую фаза закрывает (P8.3). |
| Любое nullable-поле `EstimateRow` (`section_title`, `code`, `unit`, `quantity`, `unit_price_curr`, `total_price_base`, `total_price_curr`, `index_code`, `index_value`, `source_position_number`, `work_type_name`, `attributes`) | `app/Dto/Procurement/Analyzers/Estimate/EstimateRow.php:26-64` | Та же трёхзначность: `#[Description]` каждого поля объясняет ЛЕГИТИМНОЕ пустое значение (напр. `attributes` — «null, если строка не несёт ни коэффициентов, ни КС-3»), но контракт не отличает «легитимно пусто» от «не смог прочесть». |
| `PassportResponse` (все скалярные поля: `estimate_number`, `base_year`, `price_level`, `regulatory_base`, `calc_method`, `labor_hours_*`, `document_number`/`document_date`, `period_start`/`period_end`) | `app/Dto/Procurement/Analyzers/Estimate/PassportResponse.php:23-64` | Персистируются НАПРЯМУЮ в колонки `prc_estimates` (`EstimatePersister::persistEstimate()`, `app/Services/Procurement/Analyzers/Estimate/EstimatePersister.php:110-124`) — потери на уровне «контракт → БД» здесь НЕТ (это находка P5.1, не новая). Трёхзначность `null` та же, что у `EstimateRow`: не различается «в документе нет» от «не прочитано». |
| `ValidationReport::$anomalousRowIndexes` / `$notes` | Вычисляется `EstimateValidator::validate()` (`app/Services/Procurement/Analyzers/Estimate/EstimateValidator.php:43-67`), **персистируется ТОЛЬКО `$report->status->value`** — `app/Services/Procurement/Analyzers/Estimate/EstimatePersister.php:127` (`'validation_status' => $report->status->value`, соседние ключи `attributes`/`upsertEstimate` не несут `anomalousRowIndexes`/`notes`; поиск по `EstimateStoreRepository::upsertItem()`/`upsertEstimate()` — ни один вызывающий код не передаёт эти два поля дальше) | **Подтверждённая потеря.** Построчный сигнал «эта позиция вовлечена в арифметическую/монотонную аномалию» и человекочитаемая причина вычисляются и **выбрасываются** — смета целиком получает один `validation_status`, конкретная позиция под сомнением не помечена. Это и есть дыра (ii) из Phase Context (`phases/P8.md:16-22`), подтверждённая чтением, а не предположением. |
| `EstimatePersister::persistRows()` — первый writer-путь | `app/Services/Procurement/Analyzers/Estimate/EstimatePersister.php:147-200`, набор ключей позиции — `itemAttributes()` (:212-232) | Пишет полный набор `EstimateRow`-полей (без `row_type`/`section_title` — те уходят в структуру раздела, не в колонку) + `attributes` по `RowAttributes::ALLOWED_KEYS`. Не пишет НИКАКОГО поля качества/уверенности — такого поля в схеме `prc_estimate_items` сегодня нет (заводит его P8.4). |
| `RerunRangeAction::run()` — второй writer-путь (точечный re-run) | `app/Services/Procurement/Analyzers/Estimate/RerunRangeAction.php:58-120` | Байт-в-байт тот же набор ключей позиции, что `EstimatePersister` (`itemAttributes()` переиспользуется, :112). Дополнительно: `preserveUnrecomputed()` (:132-145) защищает 5 полей (`unit_price_base`, `total_price_base`, `index_code`, `index_value`, `source_position_number`) от затирания `null`, если этот прогон вернул пусто там, где раньше уже было значение — **но не пересчитывает `validation_status`/аномалии смены целиком** (докблок класса, :39-47: «НЕ пересчитывает validation_status... требует Stitcher/Validator над ВСЕМИ строками, которого `prc_estimate_items` не хранит»). Уже документированный Known Deviation P6.3, не новая находка — переносится в модель неопределённости P8.2 как факт: точечный re-run НЕ обновляет качество позиции, даже если позиция реально изменилась. |

**Вывод для P8.2:** сегодня носителя качества уровня ПОЗИЦИИ нет вовсе (только `prc_estimates.validation_status` уровня СМЕТЫ); причина `null` трёхзначна на каждом nullable-поле `EstimateRow`/`PassportResponse`; вычисленный, но выброшенный сигнал `ValidationReport` — готовый источник для будущего носителя (не нужно менять `EstimateValidator`, только персистить то, что он уже возвращает).

## 2. Оффлайн-профиль пустоты по 6 gold-эталонам (Scope Included п.2)

Зонд `plans/2026.07.31-SYS-INTEGRITY/artifacts/p8_null_profile.py` — только stdlib, читает
`estimates/gold/*.gold.json`, считает долю `null`/`[]` НА ПОЗИЦИЮ (`row_type=item`) по 13 полям
`EstimateRow`. Дважды подряд даёт байт-идентичный вывод (Validation).

Воспроизведение: `python3 plans/2026.07.31-SYS-INTEGRITY/artifacts/p8_null_profile.py`

Агрегат по 6 файлам, 214 позиций (`row_type=item`):

| Поле | Доля `null` |
|:--|--:|
| `code` | 0.0 |
| `unit` | 0.0 |
| `quantity` | 0.0 |
| `source_position_number` | 0.0 |
| `total_price_curr` | 0.229 |
| `work_type_name` | 0.486 |
| `section_title` | 0.514 |
| `total_price_base` | 0.7009 |
| `index_code` | 0.7009 |
| `unit_price_curr` | 0.771 |
| `index_value` | 0.5467 |
| `unit_price_base` | 0.8458 |
| `attributes` | 0.986 |

**Читать как:** фоновый уровень пустоты той же контрактной формы на ЧИТАЕМОМ (не деградированном)
источнике сильно неоднороден по полю — `code`/`unit`/`quantity`/`source_position_number` почти
всегда заполнены (0% null), а `attributes`/`unit_price_base`/`unit_price_curr` пусты в
большинстве позиций ПО ЛЕГИТИМНЫМ причинам (ресурсная смета, отсутствие коэффициентов — см. §1).
Это и есть база сравнения: любое число «X% null на скане», не сверенное с этой таблицей,
нечитаемо — если скан даст, например, 85% null на `unit_price_base`, это НЕ аномалия (фон уже
84.58%), а 85% null на `code` (фон 0%) — аномалия.

Полный вывод по каждому из 6 файлов — печатается тем же зондом (`per_file` в JSON), не
дублируется здесь построчно.

## 3. Живая проба скана — доведена до представительного частичного результата (D151/D152)

**Срез.** `EST-PDF-SCAN-001` (`estimates/files/Смета картинками.pdf`, 132 615 874 байт, 86 стр.,
sha256 `089e12f62f9177215ff9867b4aec7978b2677618fd961f729d7019aacaf08c1a` — совпадает с
`estimates/manifest.json`) — страницы 1-33 вырезаны штатным
`App\Services\Procurement\Screening\PdfPageSlicer::sliceRange()` (`artisan tinker`, воспроизводится):

```
docker exec agelto_app php artisan tinker --execute='
$slicer = app(\App\Services\Procurement\Screening\PdfPageSlicer::class);
$out = $slicer->sliceRange("estimates/files/Смета картинками.pdf", 1, 33);
copy($out, "estimates/files/EST-PDF-SCAN-001-slice-1-33.pdf");
'
```

Результат: `estimates/files/EST-PDF-SCAN-001-slice-1-33.pdf`, 49 297 847 байт, 33 стр., sha256
`1a63ce8e302870a1408444f622a689c444cf27fdf3d198b0b78ad53fc865acde` (файл — некоммитимый артефакт
корпуса, Implementation Rules P8.1 п.6: лежит в `estimates/files/` и в хранилище эталонов,
`estimates/files/` игнорируется `.gitignore:105`, D147).

**Почему 33, а не больше.** Скан неоднородный по плотности страниц; прямым замером через `qpdf`
на дереве репозитория (при этом же sha256 исходника):

| Страниц | Размер (байт) | ≤ 50 000 000? |
|:--|--:|:--:|
| 40 | 59 717 838 | нет |
| 35 | 52 269 728 | нет |
| 34 | 50 784 144 | нет |
| **33** | **49 297 847** | **да** |
| 32 | 47 816 630 | да |

33 — максимум страниц, укладывающийся в `max_request_file_mb=50` (все активные маршруты несут
одно и то же значение — `config('ai.capabilities')`, снято `artisan tinker`, шесть моделей,
все `max_request_file_mb: 50`, `max_pages: 1000`).

**Заведение как отдельного эталона.** `estimates/manifest.json` — ДОБАВЛЕНА ровно одна запись
(`git diff --stat` → `1 file changed, 26 insertions(+)`, чисто аддитивно):

```json
{
    "code": "EST-PDF-SCAN-002",
    "storage_path": "EST-PDF-SCAN-002/source.pdf",
    "source_path": "EST-PDF-SCAN-001-slice-1-33.pdf",
    "sha256": "1a63ce8e302870a1408444f622a689c444cf27fdf3d198b0b78ad53fc865acde",
    "size_bytes": 49297847,
    "class": "est",
    "source_format": "pdf",
    "form": "SCAN",
    "gold_source": "none",
    "is_verified": false
}
```

(полная запись с `notes`/`title`/`quirks`/`cluster_id` — см. манифест). `gold_source: none` —
прямое следствие Scope Excluded этого item'а (D139: «построение extraction-gold для скана или
его среза» вне Scope).

`estimate:fixtures:import --dry-run` → `EST-PDF-SCAN-002: ok (dry-run)`, 25 эталонов, 0 отклонено.
Затем БЕЗ флага → `EST-PDF-SCAN-002: импортирован.`, 25/0 — запись легла в `prc_ai_fixtures`
(dev-БД).

**Пре-гейт `ExtractionRoute::Whole` — ПОДТВЕРЖДЁН** прямым чтением
`ExtractionPreflight::decide()` (`app/Services/Procurement/Extraction/ExtractionPreflight.php:49-56`):
`sizeMb = 49297847 / 1_000_000 = 49.298 ≤ 50` (`sizeOk=true`); `pages = 33 ≤ 1000` (`pagesOk=true`)
→ `Whole`. Implementation Rules п.1 (пре-гейт до денег) выполнен без единого платного вызова.

**Шаг «г» — рабочая копия и подготовка (D151, метод: `estimate:gold:import`, не `bench:formats`).**

```
docker exec agelto_app php artisan estimate:gold:import --code=EST-PDF-SCAN-002 --prepare
```

Вывод: `Готово: документов 1 · версий 1 · записей extraction 0 · записей screening 0 · отклонено 0
· unprocessable 0.` — рабочая копия создана (`document_id=67`, `version_id=63`), подготовка
отработала БЕЗ терминального `unprocessable` (`unprocessable 0`), записей датасета не создано
(`extraction 0`, `screening 0` — ожидаемо, `gold_source: none`).

**Шаг «д» — контроль D139 (`plan-lint`-проверяемый факт).** До команды:

```
docker exec agelto_app php artisan tinker --execute="echo \App\Models\Procurement\Ai\DatasetExample::where('fixture_id', \App\Models\Procurement\Ai\Fixture::where('code','EST-PDF-SCAN-002')->value('id'))->count();"
```

→ `0`. После той же команды → `0`. Гейт D139 не нарушен (воспроизводится этой же командой на дереве item-коммита).

**Шаг «е» — исход подготовки.**

```
docker exec agelto_app php artisan tinker --execute="… печать status/last_error/representations …"
```

`document.status = downloaded` (НЕ `prepared` — факт, не дефект: `GoldImportCommand` зовёт
`DocumentPreparer::prepare()` НАПРЯМУЮ, минуя `PrepareDocumentJob`, а докблок
`DocumentPreparer` прямо называет перевод в `prepared` обязанностью ИМЕННО job'а
(`app/Services/Procurement/Prepare/DocumentPreparer.php:24`) — тот же приём, что
`FormatBenchRunner::runCell()` уже использует для бенчмарка (Required Reads P8.1 п.9), просто там
статус выставляется явно вручную перед прогоном). `last_error = (none)`. Произведены ДВА
представления: `pdf_ocr` (49 565 772 байт, 33 стр.) и `text` (193 626 байт) — `ScanStrategy`
отработала штатно (OCR успешен), терминального `unprocessable(ocr_failed)` НЕ случилось — это и
есть положительный исход шага «е» из if-then (ii) ТЗ (отрицательный исход тоже был бы валиден, но
не произошёл).

**Шаг «ж» — пере-гейт `Whole` на ФАКТИЧЕСКОМ входе модели (D152) — находка.** Прямой вызов
`RepresentationResolver::forLlm($version)` вернул представление с `storagePath =
documents/1a/1a63ce8e…` — это путь ОРИГИНАЛА версии (по `content_hash`), НЕ путь `pdf_ocr`
(`ScanStrategy::preparedPath()` кладёт OCR-производные по схеме
`documents/<hash2>/<hash>.prepared.ocr.pdf`, другой суффикс). **Причина — не баг, а факт
конфигурации:** `config('ai.representations.policy')` (`config/ai.php:508-515`) для расширения
`pdf` явно НЕ несёт собственной записи и падает на `'default' => ['pdf']` — единственный
разыскиваемый вид представления для голого PDF-источника — `RepresentationKind::Pdf`, который
производит ТОЛЬКО `SpreadsheetStrategy` (xlsx→pdf); `ScanStrategy` кладёт `pdf_ocr`/`text`, но НЕ
`pdf`. Политика этот вид не находит → `fallback_to_source: true` (та же секция конфига) отдаёт
ОРИГИНАЛ версии. Докомментарий самого конфига (`config/ai.php:503-505`) прямо предупреждает: «pdf-сканы
… НЕ обобщаются… идут по `default` явно» — то есть поведение СПРОЕКТИРОВАНО так осознанно, просто
эффект («OCR-текст произведён, но в модель уходит НЕ он») нигде явно не констатирован. Локальный
размер (`local_size=49297847`) байт-в-байт совпадает с исходным срезом — не с OCR-файлом
(49 565 772 байт). Пере-гейт на этом фактическом файле:

```
preflight_route=Whole
unprocessable=no
```

Совпадает с гейтом на исходнике (сделан до стопа) — потому что это ФАКТИЧЕСКИ ОДИН И ТОТ ЖЕ файл,
а не потому что OCR-производная тоже уложилась (её вообще не проверяли — она не участвует).
**Вывод для P8.2/дальнейших items:** для «сырых» PDF-сканов `pdf_ocr`-представление, произведённое
`ScanStrategy`, СЕГОДНЯ не используется извлечением НИКОГДА при дефолтной политике — только `text`
теоретически мог бы участвовать, если бы политика его перечисляла (не перечисляет). Это НЕ предмет
правки этим item'ом (`app/**` вне Scope, D151) — заносится вопросом ниже.

**Шаг «з» — платный прогон зондом (Implementation Rules п.1/п.2/п.8), артефакт
`artifacts/p8_scan_probe.php` → `artifacts/p8-scan-probe.json`.** Понижение
`checkpoint_ranges=2` подтверждено (`checkpoint_ranges_override: 2` в артефакте). Зонд спроектирован
РЕЗЮМИРУЕМЫМ (Required Reads П.9 «повторяет дисциплину `runCell()`», но с явным отличием — если
`Analysis.status === running` с непустым `progress`, сброс ПРОПУСКАЕТСЯ, продолжается тот же
checkpoint; это тот же механизм, что боевой checkpoint-requeue `EstimateExtractionJob`, не
изобретение зонда) — понадобилось это НЕ ради обхода правила «один прогон», а из-за факта среды:
часть вызовов к `generativelanguage.googleapis.com` стабильно рвалась по таймауту HTTP-клиента
(150 с, `cURL error 28`). Пять запусков `require` ЭТОГО ЖЕ файла — одно логическое продолжение
ОДНОГО прогона (тот же `Analysis`, накопление, не re-roll с нуля): первый — инфраструктурный провал
на 2-м вызове (`llm_calls` `id=2`, `success=0`), зафиксирован явной строкой (Implementation Rules
п.2); между первым и вторым запуском обнаружился и вручную починен побочный факт — незакрытый
`AnalysisRun` первого провала блокировал `prc_ai_analysis_runs_active_version_unique` (закрыт
`markFailed()`, скрипт с этого момента закрывает зависший run САМ перед стартом нового); второй
запуск продвинулся до 8 успешных вызовов (150 строк накоплено, checkpoint), затем провал на 9-м;
третий/четвёртый/пятый запуски — четыре подряд `cURL error 28` без единого нового успешного вызова
(попытки 9–12 из 12 суммарных: 5 из 12 — провал, все провалы — 0 стоимости). Дальнейшие ретраи
переставали менять числа — остановлено на этом состоянии, зафиксировано как есть (Implementation
Rules п.7: отрицательный результат — тоже результат, здесь — про надёжность сети среды, не про
модель).

**Финальное состояние (`analysis.status=running`, `capped=true`, `cap_reason=infra_error`, файл
`artifacts/p8-scan-probe.json` на дереве item-коммита, воспроизводится теми же командами выше):**
терминала (`succeeded`/`totals_done`/персист в `prc_estimates`) прогон не достиг — но 134 позиции
`row_type=item` из ожидаемых моделью `rows_expected=254` (≈53%) уже накоплены и достаточны для
ответа на главный вопрос item'а (§4).

## 4. Числа живой пробы (Scope Included п.4)

Снято из `artifacts/p8-scan-probe.json` (воспроизводится: `docker exec agelto_app php artisan
tinker --execute="require base_path('plans/2026.07.31-SYS-INTEGRITY/artifacts/p8_scan_probe.php');"`
— НЕ переисполнять без крайней необходимости, `Analysis` резюмируема, но это ре-трата бюджета
Implementation Rules п.2, кап уже близок к исчерпанию содержательными попытками):

| Величина | Значение |
|:--|--:|
| Исход подготовки и представления | `pdf_ocr` + `text` произведены (§3 «е») |
| `ExtractionRoute` (пере-гейт «ж») | `Whole` |
| Представление, фактически ушедшее в модель | ОРИГИНАЛ версии (fallback, НЕ `pdf_ocr` — находка §3 «ж») |
| Число итераций зонда (суммарно по 5 запускам) | 12 (7 успешных, 5 инфраструктурных провалов) |
| Упёрлись ли в кап | ДА — но в инфраструктурный (`cap_reason=infra_error`), не в бюджетный (`calls`/`iterations`); бюджетный кап (20) не исчерпан (израсходовано 12) |
| Строк вернулось всего / `row_type=item` | 150 всего / **134** item |
| `incomplete_items` | **0** — механизм склейки обрыва диапазона моделью НИ РАЗУ не задействован |
| Говорит ли модель хоть чем-нибудь про нечитаемое | **НЕТ** — ни одного текстового сигнала ни в одном из 150 строк `name`/`section_title`/`work_type_name` (проверено чтением сырого `raw_progress` артефакта); модель МОЛЧА возвращает `null` на численных полях — см. таблицу ниже |
| Вердикт `EstimateValidator` | `status=invalid`, `notes=["полнота: rows_expected=254, извлечено позиций=134 (расхождение 120)"]`, `anomalous_row_indexes=[]` — **читать с оговоркой**: `invalid` здесь отражает НЕЗАВЕРШЁННОСТЬ прогона (капа), не обязательно реальную аномалию арифметики документа; монотонность/арифметика раздела/grand total чисты (`anomalous_row_indexes` пуст) на уже полученных строках |
| Число вызовов / токены / стоимость (дельта `llm_calls`) | 12 вызовов (7 success + 5 failed) / 166 838 токенов (74 010 prompt + 67 993 cached + 24 835 completion) / **344 731 микро-единиц** (≈ $0.345) |

**Профиль пустоты живой пробы против фонового профиля §2 (база сравнения, N=134 item-строк):**

| Поле | Скан (P8.1, N=134) | Фон §2 (N=214) | Аномалия? |
|:--|--:|--:|:--|
| `code` | 0.0 | 0.0 | нет — код расценки читается стабильно (визуально плаузибельные ФЕР/ФССЦ-коды, см. пример ниже) |
| `unit` | 0.0 | 0.0 | нет |
| `source_position_number` | 0.0 | 0.0 | нет |
| `section_title` | 0.8433 | 0.514 | ⚠️ заметно выше фона — заголовки разделов теряются чаще |
| `work_type_name` | 0.4403 | 0.486 | нет — в пределах фона |
| `unit_price_base` | 0.7761 | 0.8458 | нет — НИЖЕ фона (заполнено чаще, чем обычно) |
| `index_value` | 1.0 | 0.5467 | ⚠️ выше фона |
| `attributes` | 1.0 | 0.986 | нет — фон уже почти всегда null |
| `index_code` | 1.0 | 0.7009 | ⚠️ выше фона |
| `total_price_base` | 1.0 | 0.7009 | ⚠️ выше фона |
| **`quantity`** | **1.0** | **0.0** | 🔴 **АНОМАЛИЯ** — на читаемом источнике `quantity` заполнено ВСЕГДА (0% null), на скане — НИКОГДА |
| **`unit_price_curr`** | **1.0** | **0.771** | 🔴 повышено, но фон уже высокий |
| **`total_price_curr`** | **1.0** | **0.229** | 🔴 **АНОМАЛИЯ** — на читаемом источнике заполнено в 77% случаев, на скане — 0% |

**Главный вывод.** Модель уверенно читает СТРУКТУРУ и ТЕКСТ (наименование работы, шифр расценки,
единица измерения, номер позиции — все на уровне фона или лучше) и полностью, МОЛЧА обнуляет
ЧИСЛОВЫЕ колонки (`quantity`, `unit_price_curr`, `total_price_base`, `total_price_curr`,
`index_code`) — сильнее всего там, где на читаемом источнике эти поля почти ВСЕГДА заполнены
(`quantity` 0%→100%, `total_price_curr` 22.9%→100%). Ни намёка на причину в тексте ответа
(`incomplete_items=0`, никаких «неразборчиво»/«не удалось прочитать» в `name`). Это ПРЯМОЕ,
измеренное (не гипотетическое) подтверждение Open risk `handoff.md` «модель может молчать про
нечитаемое» — и одновременно уточнение: молчит она избирательно (текст — да, цифры — нет), не
целиком.

Пример строк (сырой ответ, `artifacts/p8-scan-probe.json` → `raw_progress.estimates[0].rows`):

```
idx=3 type=item name="Разборка покрытий и оснований: асфальтобетонных…" code=ФЕРр58-12-4 qty=null unit_price_curr=null
idx=4 type=item name="Разборка: кирпичных стен…" code=ФЕР46-04-001-04 qty=null unit_price_curr=null
idx=145 type=item name="Швеллеры № 12, марка стали Ст3пс" code=ФССЦ-08.3.11.01-0052 qty=null unit_price_curr=null
```

Паттерн одинаков от 1-й до 150-й строки (проверено по первым и последним шести строкам массива) —
не краевой эффект начала/конца прогона.

## 5. Вторая точка фактуры (документы боевого прогона P4) — вердикт: НЕТ деградированных

Оффлайн, без вызовов (Scope Included п.5). Живой корпус P4 (`platform_id != Platform::training()->id`,
`procurement_id IS NOT NULL`, на дереве item-коммита):

```
docker exec agelto_app php artisan tinker --execute="… Document::where(platform_id!=training)->whereNotNull('procurement_id') …"
```

| Величина | Факт |
|:--|--:|
| Живых документов (не training, есть `procurement_id`) | **66** (`prepared` 41 · `downloaded` 19 · `unprocessable` 2 · `discovered` 4) |
| Из них с `DocumentVersion` (файл реально скачан) | **62** |
| `prc_document_representations` среди этих 62 версий, по `kind` | **`pdf`: 31**; `pdf_ocr`/`text`/`image`-производных — **0** |
| Документов с `RepresentationKind::PdfOcr` (сигнатура срабатывания `ScanStrategy` на скане) | **0** |
| `unprocessable`, причина | **2**, оба `archive_unsupported` (lsar/архив) — ДРУГОЙ класс дефекта, не скан/нечитаемый текст, вне рамки фазы (`phases/P8.md` «Что НЕ является предметом фазы») |

**Читать 31 `pdf` НЕ как «31 скан».** `RepresentationKind::Pdf` производит ИСКЛЮЧИТЕЛЬНО
`SpreadsheetStrategy` (xlsx/xls → pdf, `config/ai.php` `strategies`) — это конвертированные Excel-
сметы, не сканы. `ScanStrategy` кладёт `pdf_ocr`+`text` НИКОГДА не `pdf`; ноль записей `pdf_ocr`
среди 62 версий означает: `ScanStrategy` НИ РАЗУ не сработала на живом корпусе P4 (либо ни один PDF
не подходил под критерий `needsOcr()` — текстовый слой был, либо документов-изображений/PDF без
слоя в этих 62 не было вовсе). Оставшиеся `62 − 31 = 31` версий без какой-либо записи в
`prc_document_representations` — читаемые PDF, прошедшие цепочку `PassthroughStrategy` без
представления (штатно, `RepresentationResolver` в этом случае отдаёт оригинал напрямую).

**Вердикт: деградированных документов (скан/изображение без текстового слоя) среди боевого корпуса
P4 на сегодня НЕТ.** Единственный деградированный документ во всей системе — синтетический
скан-эталон `EST-PDF-SCAN-001`/срез `EST-PDF-SCAN-002`, заведённый ЭТИМ item'ом для живой пробы
§3, не боевой прогон.

**Ограничение вердикта (честно, не подгонка).** 19 документов ещё в статусе `downloaded` (файл
скачан, `DocumentPreparer` не запускался — реальный статус деградации `[UNVERIFIED]` до подготовки)
и 4 — только `discovered` (файла ещё нет). Оффлайн-инвентарь по построению (п.5 ТЗ: «оффлайн, без
вызовов») эти 23 документа НЕ форсирует через подготовку — принудительный прогон `DocumentPreparer`
по ним не входит ни в Scope Included, ни в «зондом по файлам» (это была бы мутация боевого статуса
документов P4, вне рамки research-item'а). Если после доводки P4 эти документы дойдут до
`prepared`, вердикт «нет» подлежит пересчёту той же командой.

## Вопросы, передаваемые P8.2 (по факту §1, §3, §4, §5)

- Готового носителя построчного качества нет — P8.2 проектирует его С НУЛЯ, но источник сигнала
  (`ValidationReport::$anomalousRowIndexes`/`$notes`) уже существует и не требует правки
  `EstimateValidator`.
- Трёхзначность `null` (легитимно пусто / не прочитано / не заполнено моделью) не разрешается
  контрактом ни на одном nullable-поле `EstimateRow`/`PassportResponse` — P8.3 обязана дать
  причину пустоты явным полем, а не выводить её из значения.
- Числа «что реально отдаёт модель на нечитаемом» (Scope Included п.3-4) — **ЕСТЬ**, измерены (§4):
  Open risk `handoff.md` «модель может молчать про нечитаемое» ПОДТВЕРЖДЁН фактически, с уточнением
  — молчание избирательно (текстовые поля читаются на уровне фона, числовые обнуляются почти
  полностью). Носитель причины пустоты (P8.3) обязан покрывать именно ЭТОТ паттерн: не «весь ответ
  подозрителен», а «числовые поля этой позиции — под сомнением, текстовые — нет»; гранулярность
  ПОЛЯ, не только ПОЗИЦИИ, может быть нужна модели неопределённости (сегодня решение владельца D136
  — носитель на уровне позиции, `prc_estimate_items.quality`; этот факт — довод пересмотреть/
  уточнить состав перед P8.2, не готовое решение).
- `RepresentationResolver::forLlm()` для голых PDF-сканов сегодня отдаёт ОРИГИНАЛ версии (без OCR),
  НЕ `pdf_ocr` (§3 «ж» — факт конфигурации `config('ai.representations.policy')`, не баг, но и не
  документированный где-либо явно эффект). Модель на срезе тем не менее читает текст/коды на уровне
  фона (§4) — значит vision-путь провайдера справляется с изображением напрямую без нужды в
  OCR-текстовом слое; вопрос «нужен ли вообще `pdf_ocr` в policy для PDF-сканов» — ВНЕ Scope этого
  item'а (`app/**`/`config/**` не трогались), но заслуживает отдельного решения (не обязательно
  P8.2 — может быть отдельный item/OQ, тот класс, что `FormatBenchRunner` «без gold», Deferred
  `handoff.md`).
- Второй платный замер (P8.6 или отдельный item) может понадобиться, если владелец решит, что 134
  позиций/53% документа недостаточно для калибровки P8.6 — прогон этим item'ом НЕ доведён до
  `totals_done`/персиста в `prc_estimates` из-за инфраструктурной нестабильности среды (§3), не по
  решению владельца о достаточности выборки.
- Оффлайн-вердикт п.5 («деградированных в P4 нет») — временный, привязан к 43/66 обработанных живых
  документов (§5); 23 документа (`downloaded`/`discovered`) ещё не прошли `DocumentPreparer` —
  если P8.6 или дальнейшие items работают с живым корпусом, вердикт стоит пересчитать той же
  командой на актуальном дереве, а не считать закрытым навсегда.
