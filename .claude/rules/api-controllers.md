---
paths:
  - "**/Http/Controllers/**"
  - "**/routes/api.php"
  - "**/routes/web.php"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: backend-laravel@0.12.0
Template: rules/api-controllers.md
Edit-policy: generated-core — НЕ редактируй; расширяй через extension-point
Extension-points: .claude/rules/agelto-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack backend-laravel --target .
-->

Канон access-layer (глубина — скилл `php:repositories`). Risk-слой: **data-integrity**
(авторизация/access). На ревью этого слоя включай HTTP-чек-лист: авторизация в policy/gate
(не в контроллере); на каждое доменное исключение — HTTP-маппинг (404/403/422/429);
контрактный тест бросает исключение в сценарии.

- Контроллер **тонкий**: чтение через `*Repository`, запись через Action; никаких
  `Model::query()/::create()/::where()/::all()/::find()` в контроллере.
- Валидация — FormRequest (не сырой `request()->input()`); авторизация — policy/gate
  или атрибут, не инлайн.
- Отдача — Resource/Data (spatie/laravel-data), не `toArray()` модели.
