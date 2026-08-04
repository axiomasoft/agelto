# P5-eval-baseline — baseline платного цикла и критерий приёмки (P5.4)

> Deliverable P5.4 (`plans/2026.07.31-SYS-INTEGRITY/phases/P5.md`). Снят ДО первой правки
> контракта P5.5 (дерево `6650e40` + правки только `plans/**`, `git status` рабочего дерева кода
> чист) — эталон, с которым P5.7 сравнит контрольный прогон ПОСЛЕ расширения схемы. Кода/конфига/
> промтов этот item не менял (Scope Excluded).

## 0. Session

Session: sonnet/high (D18 `plan-run`, наследует настройки владельца).

## 1. Предпрогонные проверки

- `docker exec agelto_app php artisan estimate:fixtures:verify --json` — **зелёный**: 24/24,
  `registry_orphans: []`, `disk_orphans: []`, `расхождений: 0 · лишних: 0`.
- `docker exec agelto_app php artisan estimate:eval:run --task=extraction --split=all --dry-run` —
  исполним, `EvalRun #20` (dry-run, ноль LLM-вызовов).

## 2. Оценка стоимости и апрув

**Методика.** Прямая оценка `token_estimate` корпуса не строилась — вместо неё взят факт: сумма
`llm_calls.cost_micros` в окне `started_at`–`finished_at` каждого из семи последних сопоставимых
`--split=all` прогонов на активном маршруте `extraction` (`gemini-3.5-flash`, тот же корпус,
`EvalRun #13–#19`, 2026-08-01…2026-08-04):

| EvalRun | Дата | Успешно | USD |
|:--|:--|:--|:--|
| #11 | 2026-08-01 | 4/6 | 1.9342 |
| #12 | 2026-08-01 | 6/6 | 2.4303 |
| #13 | 2026-08-02 | 6/6 | 0.7507 |
| #14 | 2026-08-02 | 6/6 | 0.6531 |
| #15 | 2026-08-02 | 6/6 | 0.6943 |
| #16 | 2026-08-02 | 5/6 | 0.5635 |
| #17 | 2026-08-02 | 6/6 | 0.5886 |
| #18 | 2026-08-04 | 6/6 | 0.6370 |
| #19 | 2026-08-04 | 5/6 | 1.1529 |

Диапазон post-фикс P2.9/P2.12 (#13–#19): **$0.5635–$1.1529**, медиана **$0.6943**. Ни один из этих
прогонов НЕ покрывает нужное окно дерева — все они выполнены ДО фикса writer-путей P5.2 (коммит
`576794b`, 2026-08-04 17:04) и измеряли другой дифф (правки промтов P2.5/P2.6/P2.11/P2.12), поэтому
как baseline P5.4 не годятся ни один — только как основание оценки стоимости следующего прогона.

**Апрув владельца** (чат, 2026-08-04, дословно): *«Да, апрувить до ~$1.2»*.

## 3. Боевой baseline-прогон

Команда дословно:

```
docker exec agelto_app php artisan estimate:eval:run --task=extraction --split=all
```

- **`run_id` = EvalRun #22**, дата 2026-08-04, `provider=gemini`, `model=gemini-3.5-flash`
  (маршрут `extract_rows`/`extract_passport`/`extract_totals`, приоритет 1 — тот же для всех трёх
  `purpose`, `config('ai.capabilities.gemini-3.5-flash')`).
- Окно `started_at`–`finished_at`: `2026-08-04 14:47:41` → `2026-08-04 14:54:18`.
- **Фактический расход: $0.7322** (44 вызова: `extract_passport`×12, `extract_rows`×31,
  `extract_totals`×1; сумма `llm_calls.cost_micros` в окне прогона / 1e6) — в пределах апрува
  (~$1.2), сопоставимо с медианой post-фикс истории ($0.6943).
- **Итог: 5/6 эталонов обработано** (`succeeded=5, examples=6` в `EvalRun.metrics`) — `EST-XLSX-VOR-001`
  (dataset_example #16) упал с необработанным исключением ДО терминации анализа (см. §5, находка вне
  Scope этого item'а).

## 4. Таблица метрик

**Общий (5 успешных эталонов, `EvalRun.metrics`):**

| Метрика | Значение |
|:--|:--|
| precision | 0.2896 |
| recall | 0.2051 |
| f1 | 0.1064 |
| row_coverage | 0.7081 |

**По эталонам** (`prc_ai_eval_results.details`, `eval_run_id=22`):

| dataset_example_id | fixture | split | f1 | precision | recall | row_coverage |
|:--|:--|:--|:--|:--|:--|:--|
| 1 | EST-GSFX-LSR-001 | tune | 0 | 1 | 0 | 0 |
| 4 | EST-XLS-KS2-001 | — (не в `eval-splits.json`) | 0.2589 | 0.1568 | 0.7436 | 1 |
| 7 | EST-XLSX-KS2-001 | holdout | 0.0558 | 0.0406 | 0.0891 | 1 |
| 10 | EST-XLSX-KS2-003 | tune | 0.1579 | 0.1739 | 0.1446 | 0.96 |
| 12 | EST-XLSX-LSR-001 | holdout | 0.0594 | 0.0768 | 0.0484 | 0.5806 |
| 16 | EST-XLSX-VOR-001 | tune | — | — | — | — (упал, §5) |

Состав сплитов (`estimates/eval-splits.json`, `generated_at=2026-07-28T14:05:14Z`): **holdout** —
`EST-XLSX-KS2-001`, `EST-XLSX-LSR-001`; **tune** — `EST-XLS-KS2-001`, `EST-XLSX-KS2-003`,
`EST-GSFX-LSR-001`, `EST-XLSX-VOR-001`.

**`per_field`** (среднее по 5 успешным эталонам, из `details.per_field`):

| Поле | f1 | precision | recall |
|:--|:--|:--|:--|
| code | 0.0847 | 0.2606 | 0.1456 |
| name | 0.0618 | 0.2448 | 0.1940 |
| unit | 0.1472 | 0.3102 | 0.2587 |
| price | 0 | 0.6000 | 0.2000 |
| quantity | 0 | 0.8000 | 0 |
| row_type | 0.2886 | 0.4757 | 0.4516 |
| index_code | 0.8000 | 1 | 0.8000 |
| index_value | 0.2000 | 1 | 0.2000 |
| work_type_name | 0.4000 | 0.6000 | 0.8000 |
| unit_price_base | 0.4000 | 0.4000 | 0.4000 |
| total_price_base | 0.8000 | 1 | 0.8000 |
| source_position_number | 0.1106 | 0.2783 | 0.1912 |

**`passport`** (среднее по 5 успешным эталонам):

| Поле | f1 | precision | recall |
|:--|:--|:--|:--|
| currency | 0.8000 | 1 | 0.8000 |
| base_year | 0 | 0.2000 | 0.8000 |
| period_end | 1 | 1 | 1 |
| calc_method | 0.8000 | 1 | 0.8000 |
| price_level | 0 | 0.2000 | 0.8000 |
| period_start | 1 | 1 | 1 |
| document_date | 1 | 1 | 1 |
| passport.type | 0.2000 | 0.2000 | 1 |
| document_number | 1 | 1 | 1 |
| estimate_number | 0.2000 | 0.4000 | 0.8000 |
| passport.target | 0.2000 | 0.2000 | 1 |
| passport.address | 0.2000 | 0.4000 | 0.8000 |
| passport.generator | 0.4000 | 0.6000 | 0.8000 |
| passport.reference | 0.8000 | 0.8000 | 1 |
| labor_hours_workers | 0.8000 | 1 | 0.8000 |
| labor_hours_machinists | 0.8000 | 1 | 0.8000 |
| passport.contract_date | 0.6000 | 0.6000 | 1 |
| passport.contract_number | 0.6000 | 0.6000 | 1 |

**`totals`** (среднее по 5 успешным эталонам):

| Поле | f1 | precision | recall |
|:--|:--|:--|:--|
| kind | 0.6000 | 0.8000 | 0.8000 |
| rate | 0.6000 | 0.8000 | 0.8000 |
| value | 0 | 0.2000 | 0.8000 |
| chapter_number | 0.8000 | 1 | 0.8000 |

Воспроизведение агрегатов `per_field`/`passport`/`totals` (не печатаются `estimate:eval:run` в
консоль, но персистятся построчно в `prc_ai_eval_results.details`, `eval_run_id=22`) —
усреднение `details.<секция>.<поле>.<метрика>` по 5 успешным `dataset_example_id`.

## 5. Находка вне Scope: `EST-XLSX-VOR-001` падает с необработанным `ValueError`

**Не Known Deviations P5.4 — самостоятельная находка**, обнаруженная ТОЛЬКО фактом боевого
прогона (Scope Excluded этого item'а запрещает править код — фикс НЕ применён).

`dataset_example #16` (`EST-XLSX-VOR-001`) прервался исключением `bcadd(): Argument #2 ($num2) is
not well-formed` внутри `ExtractionEvalRunner::evaluateExample()` (перехвачено `catch (Throwable)`,
`analysis.status` остался `running`, `EvalRunner` пометил run `failed` и продолжил со следующим
примером — не блокирует остальные 5). Источник — `EstimateValidator::validateSection()`/
`validateTotal()` (`app/Services/Procurement/Analyzers/Estimate/EstimateValidator.php:147,192`):
`bcadd($accumulated, $row->total_price_curr ?? '0', self::SCALE)` вызывается НАПРЯМУЮ на сыром
строковом значении `EstimateRow::$total_price_curr` (свободный текст из structured LLM-ответа, без
нормализации).

**Тот же класс дефекта уже решён в другом месте кодовой базы** — `GsfxGoldMapper::cleanNumeric()`/
`expandScientificNotation()` (`app/Services/Procurement/Gold/GsfxGoldMapper.php:520-560`) несёт
ИМЕННО этот комментарий: *«`bcmath` (PHP 8.4) не принимает научную нотацию как валидный операнд —
`bcadd('2E-5', …)` бросает `ValueError`»* — и разворачивает мантиссу/экспоненту в позиционную
запись строкой ДО вызова `bcadd`. `EstimateValidator` эту нормализацию не применяет ни на одном из
двух вызовов `bcadd`.

Персистированные строки `EST-XLSX-VOR-001` в БД (`estimate_id=8`, `prc_estimate_items.
total_price_curr` — 21 строка) все well-formed (`67797.52` … `6074.70`) — проблемное значение было
в ПРОМЕЖУТОЧНОМ представлении до/во время валидации (транзакция отменена исключением, персист не
состоялся для этого прогона), не восстановимо постфактум без повторного платного вызова.

**Предлагаемый фикс** (вне Scope P5.4, follow-up): вынести `cleanNumeric`/
`expandScientificNotation` из `GsfxGoldMapper` в общий noдefault-метод (например статический хелпер
в `app/Services/Procurement/Analyzers/Estimate/` рядом с `EstimateValidator`, либо контракт-акссессор
на `EstimateRow`) и применить его в ОБОИХ вызовах `bcadd` `EstimateValidator.php:147,192` ДО
арифметики — тот же приём, что уже проверен на golden-корпусе GsfxGoldMapper'ом. Кандидат — новый
item фазы P5 (после P5.4, до/параллельно P5.5) или P7 (боевая устойчивость пайплайна); решает
владелец.

## 6. Критерий приёмки P5.7 (числами)

P2.2 закрыт **🟢 Done** (2026-08-01) — режим `--criterion=no-regression`
(`EvalRunComparator::CRITERION_NO_REGRESSION`) доступен и предписан Implementation Rules P5.4 п. с
для закрытой P2.2. Пороги — репо-константы `EvalRunComparator.php:44-48` (RAG:—, repo-grounded),
БЕЗ изменений (Scope Excluded запрещает трогать величины порогов):

| Ось | Порог (max drop) | Константа |
|:--|:--|:--|
| Holdout F1 | 0.005 | `HOLDOUT_F1_MAX_DROP` |
| Row coverage | 0.005 | `ROW_COVERAGE_MAX_DROP` |
| Per-fixture F1 | 0.05 | `PER_FIXTURE_F1_MAX_DROP` |

**Команда P5.7** (после расширения схемы P5.5/сборки gold P5.6):

```
docker exec agelto_app php artisan estimate:eval:compare --base=22 --candidate=<run_id P5.7> --criterion=no-regression
```

`base=22` — этот прогон. Критерий: `reject`, если ЛЮБАЯ из трёх осей просела больше своего порога
относительно `EvalRun #22`; `accept` иначе. Ослабление порогов запрещено (Implementation Rules п. 6)
— неудобный порог эскалируется к владельцу за новой D-записью, не двигается молча.

## 7. Итог для P5.5–P5.8

- Baseline снят ДО первой правки контракта — `git status` кода чист (только `plans/**`), тир и
  effort сессии совпали с Routing (`implementation/high`).
- 5/6, а не 6/6 — приемлемо как «завершён» по историческому прецеденту (`EvalRun #11/#16/#19` тоже
  закрывали свои items с неполным успехом); повторный платный прогон ради 6/6 НЕ запрошен владельцем
  (Implementation Rules НЕ требуют 100% успеха, только `--split=all` и полную таблицу метрик).
- §5 — самостоятельная находка для владельца, не блокирует P5.5 (не входит в её Scope, у P5.5 нет
  зависимости на `EstimateValidator`), но снижает P5.4-baseline числа (5/6 вместо 6/6) — P5.7
  повторит эту же уязвимость на контрольном прогоне с ненулевой вероятностью (тот же класс LLM-
  вывода), что стоит иметь в виду при трактовке результата `estimate:eval:compare`.
