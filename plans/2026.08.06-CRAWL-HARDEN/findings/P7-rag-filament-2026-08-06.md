# FINDINGS (слой 1) — внешние факты под P7: витрина файлового слоя в Filament 5

**Дата:** 2026-08-06 · **Инструмент:** `laravel-boost search-docs` (версионные доки установленного
пакета) · **Пакет:** `filament/filament@5.x` (в проекте — 5.6.8, `research/01-gap-matrix.md` §4).

Причина захода: витрина золотых смет (P7.1) должна показать содержимое файлов
`estimates/gold/*.gold.json`, у которых НЕТ строки в БД (`findings/repo-recon-2026-08-06.md` §10:
gold живёт только в файловой системе; в `app/Filament/**` слово `gold_dir` не встречается ни разу).
До этого замера было неизвестно, умеет ли Filament 5 таблицу поверх не-Eloquent данных — от ответа
зависело, придётся ли ради витрины импортировать золото в БД (лишний носитель одного факта).

---

## 1. Таблица поверх произвольных данных — штатная возможность Filament 5

**Вердикт: `RAG:✅ 2026-08-06` — да, поддерживается официально, отдельный раздел документации
«Custom Data».** Источник: `packages/tables/docs/09-custom-data.md`, ветка `5.x`
(`https://github.com/filamentphp/filament/blob/5.x/packages/tables/docs/09-custom-data.md`).

Механика (дословно из доков):

| Возможность | Как выражается | Что делает Filament, а что мы |
|:--|:--|:--|
| Источник записей | `->records(fn (): array\|Collection\|LengthAwarePaginator => …)` | данные отдаём мы; Filament рендерит |
| Пагинация | в `records()` инжектятся `int $page`, `int $recordsPerPage`; вернуть `LengthAwarePaginator` | ссылки и контролы — Filament; нарезка — мы |
| Поиск | в `records()` инжектится `?string $search` (`null`, если поиска нет); таблица включается `->searchable()` | поле поиска — Filament; фильтрация — мы |
| Поиск по колонке | инжектится `array $columnSearches`; колонка — `->searchable(isIndividual: true)` | то же |
| Действия строки | `$record` в колбэке — **`array`**, а не `Model`; `->url(fn (array $record) => …)` работает | — |
| Bulk-действия через страницы | `->resolveSelectedRecordsUsing(fn (array $keys) => …)`, для «выбрать всё» — `$isTrackingDeselectedKeys`/`$deselectedKeys` | нам не нужно: витрина read-only |

Прямая оговорка доков: после действия данные кастомной таблицы **не обновляются сами** — нужен
`$this->resetTable()`. Для read-only витрины неактуально (действий-мутаций нет), но правило
записано, чтобы его не «открыли заново».

**Следствие для дизайна:** импорт золота в БД ради видимости в админке НЕ требуется. Файл остаётся
единственным источником истины (граница фазы в контракт-блоке P7), а витрина читает его на лету.

## 2. Носитель витрины — кастомная Page, а не Resource

`Filament\Resources\Resource` привязан к Eloquent-модели (`protected static ?string $model`), у
золотой сметы модели нет. Носитель — кастомная страница панели по образцу, который в проекте уже
есть: `app/Filament/Pages/FinalPromptPage.php:28-31` — `final class … extends Page implements
HasTable` + `use InteractsWithTable` + `use HasAzGuardPage`, слаг с параметром
(`final-prompt/{run}`, :33), `mount(int $run)` (:44), таблица в `table(Table $table)` (:54).

`RAG:— (repo-grounded: app/Filament/Pages/FinalPromptPage.php:28-33)` — образец «первой кастомной
страницы панели (D45)»; его же докблок (:25-26) фиксирует правило авторизации: **скрытие пункта
навигации доступом не является**, URL остаётся достижимым, поэтому трейт `HasAzGuardPage`
обязателен, а не опционален.

## 3. Что из этого НЕ следует

- Доки «Custom Data» не обещают серверную сортировку/фильтры «из коробки»: всё, что не
  перечислено в таблице выше, придётся считать в `records()` руками. Для витрины на 6 файлов и
  ≤160 строк смет это не проблема, но обещать «фильтры как у обычного ресурса» нельзя.
- Раздел не описывает инфолист поверх массива; в доках инфолистов подтверждено другое:
  `RepeatableEntry` умеет повторять схему по **массиву** (`packages/infolists/docs/08-repeatable-entry.md`,
  раздел Introduction: «for items in an array or relationship», пример состояния — голый
  PHP-массив) — этого достаточно, чтобы показать позиции и ресурсы золотой сметы без модели.
  Маркер: `RAG:✅ 2026-08-06`.

## 4. Инвентарь файлового слоя на дату замера

`RAG:— (repo-grounded: estimates/manifest.json, ls estimates/gold)` — 25 записей в манифесте,
из них с `gold_path` — 6; на диске ровно те же 6 файлов `*.gold.json`
(`EST-GSFX-LSR-001`, `EST-XLS-KS2-001`, `EST-XLSX-KS2-001`, `EST-XLSX-KS2-003`, `EST-XLSX-LSR-001`,
`EST-XLSX-VOR-001`); рядом — 9 `*.screening.json` и 9 `*.profile.json`. `is_verified = false` у
ВСЕХ 25 записей: ни один эталон сегодня не помечен проверенным человеком.

Побочная находка того же захода: `estimates/reports/gold-corpus-report.md` (отчёт
`estimate:gold:build`) закоммичен в состоянии ТЕСТОВОГО прогона — в таблице стоят синтетические
коды `EST-XLSX-GOOD-001`/`EST-XLSX-BROKEN-001`, которых нет ни в манифесте, ни на диске. То есть
файл, который админка могла бы показывать как «сводный отчёт корпуса», сегодня описывает не корпус.
Маркер: `RAG:— (repo-grounded: estimates/reports/gold-corpus-report.md)`.
