# Boost 2.0 Skills (source)

Структура соответствует [Third-Party Package Skills](https://laravel.com/docs/12.x/boost#third-party-package-skills): каждый скилл — папка `{skill-name}` с файлом `SKILL.md` внутри.

```
resources/boost/skills/
├── laravel-attributes/
│   └── SKILL.md
├── named-arguments/
│   └── SKILL.md
└── ...
```

Формат `SKILL.md` — Markdown с **YAML frontmatter** в начале:

```yaml
---
name: skill-name
description: >-
  Краткое описание. Когда активировать скилл.
---
# Заголовок

Тело скилла...
```

- **name** — идентификатор скилла (kebab-case), совпадает с именем папки.
- **description** — короткое описание; по нему Boost показывает скилл в списке.

После правки здесь нужно синхронизировать в `.ai/skills/` (откуда Boost подхватывает скиллы при `boost:update`):

```bash
php artisan boost:sync-skills          # копирует в .ai/skills
php artisan boost:sync-skills --update # синк + boost:update + восстановление skills в boost.json
```

**Важно:** Лучше вызывать `boost:sync-skills --update`, а не напрямую `boost:update`. Команда `boost:update` перезаписывает `boost.json` только обнаруженными скиллами (Roster зависит от composer + node/pnpm); в некоторых окружениях скиллы вроде `inertia-vue-development` и `tailwindcss-development` могут пропасть. При `--update` мы сохраняем текущий список skills до обновления и после обновления объединяем его с новым (union), чтобы ничего не терять.
