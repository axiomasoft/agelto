# Docs PR Checklist (сквозное правило)

Применяется ко ВСЕМ PR, не только к домену парсинга (для правил парсинг-пайплайна — skill
`procurement-pipeline-dev`, свой PR-чеклист там). Источник — `docs/` каркас EST-AI P10.1
(`plans/EST-AI/research/H-docs-structure.md`).

Перед мержем PR проверь:

- [ ] **docs обновлены?** Если PR меняет доменное поведение (пайплайн, enum статусов, LLM-роутинг,
  промты) — соответствующий файл в `docs/02_Workflow/` или `docs/03_Dev/` обновлён или заведён
  тикет/Pending Work на обновление.
- [ ] **Операторский SOP обновлён при Filament-правке?** Если PR меняет ресурс/экшн/лейбл в
  Filament-админке (`app/Filament/Resources/Procurement/**`) — соответствующий SOP в
  `docs/04_Operators/how-to/` и/или `docs/04_Operators/reference/statuses.md` актуализирован,
  `last-reviewed` в затронутом SOP обновлён на дату PR.
- [ ] Новые/изменённые файлы `docs/**` несут frontmatter `audience:`/`type:` (`status:` —
  `draft`/`living` по факту наполнения).
- [ ] Внутренние ссылки на изменённые файлы `docs/**` не сломаны (переименование/перенос файла —
  обнови ссылающиеся роутеры `_index.md`/`index.md`).
