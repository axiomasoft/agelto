# RAG — Filament v5 и AzGuard для фазы P8 (2026-07-27)

Слой 1 (`plan-protocol` §16): внешние факты, на которых стоят решения P8. Проверено при
детализации фазы P8, design pass 2/3, заход 8.

Версия в проекте: `filament/filament: ^5.0` (`composer.json:35`), панель — `app/Providers/Filament/AdminPanelProvider.php`.

## 1. Кастомная страница внутри ресурса (вход экрана сравнения P8.3)

**Вопрос:** можно ли повесить собственную страницу на маршрут ресурса, не заводя отдельный
Filament-Page вне ресурса.

**Вердикт: да.** Страница регистрируется в `getPages()` ресурса собственным маршрутом; порядок
регистрации значим (Laravel матчит маршруты сверху вниз):

```php
public static function getPages(): array
{
    return [
        'index' => Pages\ListCustomers::route('/'),
        'view' => Pages\ViewCustomer::route('/{record}'),
        'edit-contact' => Pages\EditCustomerContact::route('/{record}/edit/contact'),
    ];
}
```

Источник: https://filamentphp.com/docs/5.x/resources/custom-pages и
https://filamentphp.com/docs/5.x/resources/editing-records (через context7
`/websites/filamentphp_5_x`, запрос «Custom page inside a resource: getPages() registration…»,
2026-07-27).

**Следствие для плана:** экран сравнения P8.3 — страница `compare` ресурса эталонов
(`/{record}/compare`), а не отдельный пункт навигации; авторизация страницы ресурса идёт по
абилке ресурса (см. §3 ниже — для КАСТОМНЫХ Filament-Page вне ресурса это НЕ так).

## 2. Кнопки-действия в шапке страницы

Собственные действия добавляются переопределением `getHeaderActions()` (`Filament\Actions\Action`),
рядом со штатными. Источник — тот же раздел документации 5.x, запрос выше, 2026-07-27.

**Следствие:** «Прогнать извлечение» (P8.2), «Проверено человеком» (P8.3), «Одобрить/Отклонить»
(P8.4) — header actions; авторизация каждой — `->authorize('<ability>')`, как уже сделано в
`app/Filament/Resources/Procurement/Ai/Analyses/Pages/ViewAnalysis.php` (repo-grounded образец).

## 3. Загрузка файла на НЕдефолтный диск и имя файла

`FileUpload` умеет `disk()` / `directory()` / `visibility()`; имя файла в хранилище задаётся
`getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => …)`; превью
отключается `previewable(false)`. По умолчанию видимость `private`, если диск не `public`.

```php
FileUpload::make('attachment')
    ->disk('estimates')
    ->directory($code)
    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => 'source.'.$ext)
    ->previewable(false)
```

Источник: https://filamentphp.com/docs/5.x/forms/file-upload (context7, запрос «FileUpload field:
store on a specific disk and directory, custom file name…», 2026-07-27).

**Следствие для P8.1:** конвенция `<CODE>/source.<ext>` (D17) достижима штатным полем, БЕЗ
самодельного контроллера загрузки; но код эталона известен только ПОСЛЕ валидации формы (он
выводится из класса/формата/формы), поэтому файл принимается во временное хранилище Livewire, а
раскладку по конвенции делает сервис на сохранении записи — единственный носитель пути остаётся
`Fixture::storagePathFor()` (P2.2). `previewable(false)` обязателен: в корпусе есть 132-МБ PDF.

## 4. AzGuard: как появляются права нового ресурса (repo-grounded, не внешний факт)

- `guard:filament:generate {--source=} {--panel=} {--dry-run}` — генератор permission-enum'ов по
  Filament-ресурсам (`packages/azguard/packages/filament/src/Commands/GenerateFilamentPermissionsCommand.php:26`).
- `guard:sync-roles {--panel=} {--dry-run}` — синхронизация PHP-ролей с таблицей ролей
  (`packages/azguard/packages/core/src/Commands/SyncRolesCommand.php:23`).
- `guard:catalog:validate {--panel=} {--strict} {--json}` — сверка каталога, политик и enum'ов
  (`.../CatalogValidateCommand.php:35`); `guard:doctor {--panel=} {--json}`.
- Список абилок — ОБЩИЙ для всех ресурсов панели (`config/az-guard-filament.php` → `abilities`),
  поэтому «своя абилка на один ресурс» (например `fixture.run`) размножилась бы по всем ресурсам
  панели; кастомное действие авторизуется существующей абилкой (`update`/`create`) — см. D69.
- Кастомные Filament-Page и виджеты НЕ авторизуются автоматически (Filament идёт через
  `canAccess()`, минуя Gate) — нужен трейт `AzGuard\Filament\Concerns\HasAzGuardPage`; скрытие
  пункта навигации доступом НЕ является (`config/az-guard-filament.php`, блок `pages`).
- Автоматическая авторизация страницы РЕСУРСА гарантирована там, где абилку записи проверяет сам
  Filament (`ViewRecord`/`EditRecord`); для КАСТОМНОЙ страницы ресурса (§1) документация такой
  гарантии не заявляет — [UNVERIFIED], поэтому план предписывает объявлять `canAccess()` явно и
  закреплять это тестом «пользователь без права получает 403 по прямому URL». Риск принят
  сознательно: явная проверка дешевле и не зависит от поведения версии.
