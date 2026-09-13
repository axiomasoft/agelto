---
audience: dev
type: reference
status: living
---

# Admin Panel (Filament) — для разработчика

## Ресурсы

`app/Filament/Resources/Procurement/` — по подпапкам вплотную к домену:

| Подпапка | Ресурсы |
|:--|:--|
| `Procurements/` | Закупки (карточка закупки, документы, сметы) |
| `Crawler/` | `Runs`, `Proxies`, `IgnoreRules`, `Crawlers` — управление обходом |
| `Platform/` | `Platforms`, `Registries` — площадки-источники |
| `Ai/` | `Providers`, `AiRoutes`, `Prompts`, `Analyses`, `DatasetExamples`, `Fixtures` — AI-пайплайн |
| `Estimates/` | `EstimateResource` (P8.5) — read-only витрина смет: список со счётчиком «помечено N из M» и просмотр позиций с пометкой неопределённости |

`Crawler/Proxies` — форма узла: страна и скоупы (`ProxyScope`, мультиселект) — закрытые списки
(`ProxyForm::COUNTRIES`), не свободный текст; здоровье breaker'а — read-only `Fieldset`
(`disabled()->dehydrated(false)`, правка не долетает до записи — автомат обновляет эти поля сам).
Страница «Прокси краулера» доступна только с `ProxyPermission::Update` и хранит в typed settings
только nullable ID default-узла. В селекте видны лишь активные прокси со scope listing/detail/document
и только безопасные service/host/port/country; credentials в страницу, уведомления и settings не
попадают. `CrawlerForm` вместо старого bool использует режимы: «Профиль» для привязанных узлов,
«По умолчанию» для inheritance этой настройки и «Напрямую» для явного opt-out. Настроенный, но
недоступный узел fail-closed — не переключает запрос на другой маршрут; история fetch показывает,
какой из трёх источников был выбран, не раскрывая credentials.

`Ai/Fixtures` (P5) — рабочее место для прогонки эталонных документов через штатный пайплайн вручную:
карточка эталона (файл/код/оси/`type_profile`) → relation-менеджер прогонов (`RunsRelationManager`,
токены/цена) → модальное сравнение результата анализатора с эталоном (`RunResultSchema`). Соседние
экраны того же контура — финальный склеенный промт прогона и очередь предложений по промтам
(approve/reject/audit) — см. Completion Notes P5.5/P5.6 в `plans/archive/2026.07.28-EST-ARCH/phases/P5.md`.

`Ai/Fixtures` → вкладка «Золото» (P7.1) показывает текущее содержимое
`estimates/gold/<CODE>.gold.json` без импорта или редактирования: паспорт и итоги выводятся на
странице, позиции — таблицей с раскрываемыми подэлементами. Файл читается заново для запроса, а
безопасность пути проверяют формат кода эталона и принадлежность каталогу `ai.fixtures.gold_dir`.
На списке эталонов признак «Золото» берётся из уже загруженного манифеста, без чтения файла для
каждой строки. Пустое золото и расхождение «манифест обещает файл, файла нет» показаны отдельно.

`Ai/DatasetExamples` (P7.2) — read-only витрина immutable-снапшотов обучающего контура: список
показывает эталон, документ, задачу, тип, источник, версию и проверку; фильтры отделяют задачи,
типы, источники и строки с эталон-фикстурой. Страница просмотра выводит метаданные и для
estimate-контракта применяет тот же структурированный рендер gold, что вкладка эталона. Если тело
снимка вынесено во внешнее хранилище (`gold_ref`), витрина показывает идентификатор и не читает
внешний объект. Создания, редактирования и удаления в панели нет.

`Estimates/EstimateResource` (P8.5) — read-only на уровне ресурса (`canCreate`/`canEdit`/`canDelete`
запрещены явно, D137: правки позиции оператором в этой фазе нет, OQ-10). Счётчик «помечено N из M»
на списке — агрегат в запросе (`withCount`), не выборка позиций в PHP: число запросов списка не
растёт с числом позиций сметы. Просмотр несёт `ItemsRelationManager` (не секция инфолиста —
`RepeatableEntry` не даёт интерактивный `->filters()`, нужный для «только помеченные») с колонкой
пометки: причина по-русски (`RowQualityReason::label()` — единственный носитель перевода) и
провенанс («Не прочитано моделью» / «Нашла проверка сметы»); имена полей — подписи колонок сметы,
не машинные ключи (`ItemsRelationManager::FIELD_LABELS` — единственный носитель).

Каждый ресурс — Filament v5 `Schemas`/`Tables`/`Pages` подпапки (канон skill `filament`,
scaffold только через `make:filament-*`).

## Операционный контур

## Навигация и названия

Панель использует фиксированный порядок: «Закупки» → «Площадки» → «Сбор данных» →
«Качество анализа» → «Настройка AI» → «Доступ и безопасность» → «Система».
Это операторские домены, а не PHP namespaces: URLs, permission keys, Policies и
скрытая страница итогового запроса к AI не меняются. Enum, который Filament получает
напрямую, реализует `HasLabel`; значения LLM/PromptStep выводит
`App\\Filament\\Support\\LlmPresentation`, сохраняя LLM-контракты независимыми от Filament.

AzGuard получает русскую copy только через `config/az-guard-filament.php` → `labels`.
Пакет по умолчанию сохраняет English labels, поэтому другой consumer может задать
свой словарь без изменения Role/DirectGrant/Doctor identity.

Committed default `APP_LOCALE=ru` локализует стандартные Filament actions и validation copy;
application-owned domain labels по-прежнему задаются явно в Resources и Schemas.

Полный runtime-инвентарь admin routes и ролевых владельцев хранится в
`plans/archive/2026.08.09-RELEASE-READINESS/artifacts/P9-admin-route-role-matrix.md`.
`AdminRouteInventoryTest` сверяет этот committed снимок с `route:list --path=admin --json`:
новый, удалённый или переназначенный route требует явного обновления матрицы и review доступа.
`e2e/admin-navigation-responsive.spec.ts` повторяет этот контракт в Chromium для SuperAdmin,
Operator и Analyst: точный порядок групп, desktop/mobile role routes, owner routes для соответствий
статусов, создания прокси и просмотра инструкции, Sections/help, доступность mobile table actions,
отсутствие горизонтального overflow и чистый Filament/Livewire/asset browser health. Direct URLs
проверяются отдельно для role denial и context без `storageState`.

## Формы и карточки

Каждая application-owned форма и карточка использует именованные `Section` по задаче оператора,
а не плоский список технических полей. Helper text объясняет только неочевидные единицы,
наследование, приоритет, секреты, read-only состояние или side effects; он не меняет validation,
dehydration, Policy и stored values. Статический `FilamentSchemaContractTest` не допускает новый
application schema carrier без Section.

Панель — внутренний инструмент авторизованных операторов, не публичный API. В ней разделены три
вида наблюдаемости: история закупки (`prc_events`), технические вехи и ошибки стадий
(`prc_operation_logs`) и аудит операторских действий. Метрики краулинга, очередей, документов и
LLM должны вести к этим поверхностям и health-check'ам, а не смешивать операционный журнал с
доменной историей.

## AzGuard RBAC-гейт

`ResourceGate::check()` коротко замыкает `Gate::before` по карте `FilamentDiscovery::subjects()` —
для большинства Procurement-ресурсов авторизация решается ГРУБО (per-resource permission из
`AdminGuardPanelProvider::discoverPermissionEnums()`), без записи-специфичных проверок.

Пункт «Документация» в группе «Доступ» ведёт на единственный именованный route
`documentation.show` (`/documentation/`) и видим только при `DocumentationPermission::View` в panel `admin`.
Это discovery-ссылка, не authorization boundary: прямой URL повторно проверяется контроллером.

**Исключение — `PromptResource` (D44).** `config('az-guard-filament.exclude.resources')` исключает
`PromptResource` из discovery-каталога плагина: `ResourceGate::check()` для `Prompt` возвращает
`null` (карта его не знает), enum-RBAC `Gate::before` НЕ срабатывает, штатный `Gate::policy()`
резолвится в `App\Guards\Admin\Policies\PromptPolicy` (`app/Guards/Admin/Policies/PromptPolicy.php`,
`#[GuardPolicy(model: Prompt::class)]` + `#[GateAbility]`). Политика перепроверяет И грубое право
(`hasPermission()`), И запись-специфичное условие (`!is_protected` — сидовые промты из обоих корней
канона (`resources/ai/prompts/**` + `resources/<домен>/ai/prompts/**`, D32) read-only в админке, D17).
Это единственный официально задокументированный
паттерн композиции «коарс-право + запись-специфичный чек» в AzGuard (`packages/azguard/docs/
best-practices/policies-and-gates.md`) — не RBAC-роль, `PromptPermission`/`OperatorRole` не менялись.

## Смежное

`Ai/Prompts` (P7.5) отвечает на вопрос «какой это промт»: список показывает тип документа,
ключ, шаг, версию, приоритет, активность и признак канона; его можно фильтровать по этим осям.
Карточка показывает полный моноширинный текст и все версии одной пары «тип документа + ключ».
Канонические записи остаются read-only по `PromptPolicy`.

Страница «Композиция системного промта» доступна только при `PromptPermission::ViewAny` — проверка
выполняется и по прямому URL, а не только скрытием пункта навигации. Она читает допустимые шаги из
`DocumentAnalyzerRegistry`, показывает текущую цепочку правил, записи и их версии. Это не финальный
payload: блок схем добавляет место вызова; текст, реально ушедший в модель, смотрят на странице
«Финальный промт прогона» из карточки конкретного анализа или прогона.

- Как именно read-only работает для оператора — `docs/operators/how-to/manage-prompts.md` (P10.4).
- Промт-система целиком — [`ai/prompts.md`](ai/prompts.md).
- Общий канон AzGuard — skill `laravel-permissions`/`attribute-authorization`.
# Карта переходов слоёв смет

Витрина сметы ведёт к закупке и анализу; анализ — к своим сметам и эталону рабочей копии; эталон — к gold-вкладке, его ответам и прогонам; эталонный ответ — к эталону и анализу. Ссылки показываются только для загруженной структурной связи и не меняют данные.
