---
id: D101
date: 2026-08-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Новая операторская поверхность Filament легальна только вместе со своим правом AzGuard и грантом роли; мутирующее табличное действие — только с явным `->authorize()`.** Конкретно: P5.6 получает `PlatformCheckPermission` (генерируется `guard:filament:generate --source=enum`) и грант оператору РОВНО `ViewAny`+`View`; P6.1 — `StatusMappingPermission` и грант `ViewAny`+`View`+`Update` (пары заводит только `StatusMappingService::resolve()`, поэтому `Create`/`Delete` роли не выдаются); P4.3 новых прав не заводит (`ProxyPermission` есть и выдан оператору полным CRUD) — ему добавлен `->authorize('update')` на действии «Включить обратно» по канону `ViewAnalysis::approveAction()`. У всех трёх Validation получила исполняемый гейт «оператор открывает страницу»: аутентификация ролью `OperatorRole` (`artisan('guard:sync-roles')` в `beforeEach` + `assignRole`), а не суперадмином. Отвергнуто: полагаться на авторизацию ресурса для табличного действия (она покрывает страницы, но не кнопки на них) и на `crud(...)`-грант «чтобы точно открылось» (право, которого нет у витрины, не должно существовать у роли). **Consequences:** три item'а получили дополнительный обязательный шаг и по 1–2 теста сверх прежней планки (`≥3`→`≥4` у P5.6, `≥5`→`≥6` у P6.1 и P4.3); `guard:sync-roles` становится частью item-коммита, а не бухгалтерии; тир и глубина ревью НЕ поднимаются — гейт детерминирован (grep по `OperatorRole` даёт точное число строк + тест под оператором), а `Review = light` у P5.6 сохраняется

## Почему

Находка F1 аудита (Major, confidence high): `ResourceGate::check()` union-only — отсутствие гранта возвращает `null`, Gate падает в deny, и оператор получает 403 с пустой навигацией на витринах, ради которых владелец просил «видим её как живую»; тест под суперадмином при этом зелёный, то есть отказ не виден ни одним гейтом. Канон плану известен — P3.4 и P7.5 его уже несут. `RAG:— (repo-grounded: /home/vostrikov/projects/packages/azguard/packages/filament/src/Permissions/ResourceGate.php:53-57 «union-only»; FilamentDiscovery::resourceSubject() — имя субъекта = `class_basename($model)`; PermissionEnumGenerator::className() — `Str::studly($name).'Permission'`; config/az-guard-filament.php `enforce = true`, `source = enum`; app/Guards/Admin/Roles/OperatorRole.php — 17 грантов, ни одного для новых ресурсов; tests/Feature/Filament/EstimateResourceTest.php:22-29 — канон теста под оператором)`
