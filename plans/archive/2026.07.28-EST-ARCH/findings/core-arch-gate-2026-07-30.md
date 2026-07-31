# findings — arch-гейт «ядро не знает про тип документа»: два нарушителя в продуктовом коде и ложно-зелёная форма `not->toUse`

Слой 2 (`plan-protocol` §16). Факты репозитория, снятые при исполнении P2.6 на `<base>` = `11a9fe8`
(до единого коммита кода). Item эскалирован: зелёный исход arch-гейта недостижим в границах его
`Scope` (D89, исход «в»), а форма гейта, написанная «в лоб», зеленеет на пустом месте.

## 1. Два нарушителя приёмочного свойства D26 — в продуктовом коде, вне `Scope` P2.6

Свойство §6.3 контракта `root/document-analyzer-contract.md`: класс из восьми неймспейсов ядра
(§6.2) не имеет права (а) импортировать неймспейс с сегментом `Analyzers`, (б) импортировать
`App\Dto\Procurement\Ai\Estimate\**`, (в) импортировать `App\Models\Procurement\Estimate\**`,
(г) нести идентификатор `Estimate`/литерал `'estimate'` кроме `AiDocumentType::Estimate`.

На `11a9fe8` свойство нарушено ровно ДВАЖДЫ (команда — §3 ниже, вывод воспроизведён):

| # | Файл : строка | Что | Какой пункт §6.3 |
|:--|:--|:--|:--|
| 1 | `app/Services/Llm/Validation/StructuredResponseValidator.php:7` | `use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;` | (б) и (г) |
| 2 | `app/Jobs/Procurement/ShadowAnalysisJob.php:18` | `use App\Services\Procurement\Analyzers\Estimate\EvalMetrics;` | (а) и (г) |

Оба — не косметика, а живая связность:

- `StructuredResponseValidator::structuralError()` (`:76-99`) держит СМЕТНОЕ структурное правило
  (`$data instanceof RowsChunkResponse`, монотонность `row_index` внутри чанка) внутри
  общего валидационного каскада транспорта. Ядро транспорта знает про строки сметы.
- `ShadowAnalysisJob` инъектирует `EvalMetrics` из каталога анализатора сметы в сигнатуры
  `:78`, `:96`, `:188` (`compareWithPrimary()`), то есть ядерный job считает СМЕТНУЮ метрику.

Оба файла названы ЯДРОМ самим контрактом: `App\Services\Llm` — п. 3 списка §6.2 («включая
`Contracts`, `Dto`, `Providers`, `Schema`, `Validation`»), `ShadowAnalysisJob` — поимённо в правиле
вычитания §6.2 («`App\Jobs\Procurement` — ядро (`ScreeningJob`, `PipelineJob`, `ShadowAnalysisJob`)»).

Ни один из них P2.4/P2.5 не чинил: `ShadowAnalysisJob` вошёл в дифф P2.4 ОДНОЙ строкой `use` —
переезд FQN вслед за `EvalMetrics`, а не развязка (P2.4 Known Deviations, 14 путей вне `Files`);
`StructuredResponseValidator` не входил ни в один дифф фазы P2.

## 2. Форма гейта: `expect([много таргетов])->not->toUse(...)` — ложно-зелёная

Проверено пробами на `pest-plugin-arch` (Pest 3, плагин из `vendor/`), контейнер `agelto_app`:

| Проба | Форма | Исход |
|:--|:--|:--|
| F | `expect(['…\ShadowAnalysisJob'])->not->toUse(['App\Services\Procurement\Analyzers'])` | **⨯ красный** (верно) |
| E | тот же таргет, ДВЕ зависимости (вторая не используется) | **⨯ красный** (верно) |
| D | ДВА таргета (нарушитель + чистый `ScreeningOutcomeHandler`), ОДНА зависимость | **✓ зелёный** — ложно |
| B | ОДИН таргет, wildcard-зависимость `App\*\Procurement\Analyzers` | **⨯ красный** (верно) |

Вывод: множество ЗАВИСИМОСТЕЙ работает по «И» (любая используемая зависимость валит гейт), а
множество ТАРГЕТОВ — по «ИЛИ»: `not` считается выполненным, как только НАЙДЁТСЯ пара
(таргет, зависимость) без связи. Массив таргетов в `not->toUse` даёт гейт без красного исхода —
тот же класс, что D66/D69/D81/D89. Таргеты обязаны перебираться ПООДИНОЧКЕ.

Отдельно: `->ignoring('App\Jobs\Procurement\Analyzers')` для правила вычитания §6.2 применять
НЕЛЬЗЯ — `Pest\Arch\Factories\LayerFactory::make()` вычитает строки `ignoring` не только из
целевого слоя, но и из `uses` каждого объекта и из слоя зависимости, то есть заодно ослепляет
проверку «ядро не ссылается на job анализатора». Вычитание делается перечислением классов.

## 3. Рабочая форма гейта (проверена, красная ровно на двух файлах §1)

Динамический список классов ядра (правило вычитания — фильтром по сегменту пути, а не руками),
таргеты поодиночке, wildcard-предикат по сегменту `Analyzers` вместо перечня слоёв:

```php
function coreNamespacesOfContract(): array   // §6.2, БУКВАЛЬНО, порядок тот же
{
    return [
        'App\Services\Procurement\Screening', 'App\Services\Procurement\Extraction',
        'App\Services\Llm', 'App\Services\Procurement\Contracts',
        'App\Enums\Llm', 'App\Repositories\Llm', 'App\Models\Llm', 'App\Jobs\Procurement',
    ];
}

/** @return list<class-string> */
function coreClassesOfContract(): array
{
    $classes = [];

    foreach (coreNamespacesOfContract() as $namespace) {
        $directory = app_path(str_replace('\\', '/', substr($namespace, strlen('App\\'))));

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (str_contains($class, '\\Analyzers\\')) {   // правило вычитания §6.2
                continue;
            }

            $classes[] = $class;
        }
    }

    return $classes;
}

test('ядро не импортирует поверхность типа документа …', function (): void {
    foreach (coreClassesOfContract() as $class) {          // ПООДИНОЧКЕ, см. §2
        expect($class)->not->toUse([
            'App\*\Procurement\Analyzers',                 // (а)
            'App\Dto\Procurement\Ai\Estimate',             // (б)
            'App\Models\Procurement\Estimate',             // (в)
        ]);
    }
});
```

Пункт (г) — отдельным грепом внутри теста по тем же файлам: из исходника вырезается
`AiDocumentType::Estimate` (законный SSOT оси типа, D16), в остатке ищется
`/\bEstimate\b|'estimate'/`. Граница слова обязательна (D69): без неё гейт ловит
`'gold_is_estimate'`/`'predicted_is_estimate'` — легальные ключи массива в
`app/Services/Procurement/Screening/ScreeningEvalMetrics.php:32-33`.

Размер периметра на `11a9fe8`: **60** классов ядра, из них **1** вычтен правилом вычитания
(`App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob`).

**Прогон обеих проверок на `11a9fe8`:** `2 failed` — (а)/(б) падает на
`App\Services\Llm\Validation\StructuredResponseValidator`, (г) печатает обе строки таблицы §1.
Команда: `docker exec agelto_app php artisan test --filter=CoreKnowsNoDocumentType`.

## 4. Что из этого следует для ТЗ

Зелёный исход `Validation 1` («arch-тест зелёный») требует правки двух файлов в `app/`, а
`Validation 4` требует, чтобы `git diff --stat <base>..HEAD -- app/ database/ resources/` был ПУСТ,
и `Scope Excluded` п. 1 запрещает «любые изменения продуктового кода». Множество допустимых
исходов пусто — D89, исход «в». Развязка двух call-site'ов — предмет отдельного решения владельца
(`plan-design`), не исполнителя: у обоих нарушителей есть неочевидная цена (см. §1), и оба
принадлежат периметру, который §6.3 объявляет «ровно тем, что фаза P2 действительно вычищает».

Половина ТЗ про фиктивный второй тип (`AiDocumentType::TechSpec`, свой job, сводка через реестр)
на этом заходе НЕ проверялась: работа остановлена на первом блокере (Implementation Rule 4 —
«остановиться, зафиксировать точный список файлов и эскалировать»).
