# Docs PR Checklist (сквозное правило)

Применяется ко ВСЕМ PR, не только к домену парсинга (для правил парсинг-пайплайна — skill
`procurement-pipeline-dev`, свой PR-чеклист там). Источник — живой
[`docs/meta/structure.md`](../../docs/meta/structure.md).

Перед мержем PR проверь:

- [ ] **docs обновлены?** Если PR меняет доменное поведение (пайплайн, enum статусов, LLM-роутинг,
  промты) — соответствующий файл в `docs/workflow/` или `docs/dev/` обновлён или заведён
  тикет/Pending Work на обновление.
- [ ] **Операторский SOP обновлён при Filament-правке?** Если PR меняет ресурс/экшн/лейбл в
  Filament-админке (`app/Filament/Resources/Procurement/**`) — соответствующий SOP в
  `docs/operators/how-to/` и/или `docs/operators/reference/statuses.md` актуализирован,
  `last-reviewed` в затронутом SOP обновлён на дату PR.
- [ ] Новые/изменённые файлы `docs/**` несут frontmatter `audience:`/`type:` (`status:` —
  `draft`/`living` по факту наполнения).
- [ ] Внутренние ссылки на изменённые файлы `docs/**` не сломаны (переименование/перенос файла —
  обнови ссылающиеся роутеры `_index.md`/`index.md`).
- [ ] Запусти `DocsIntegrityTest`: он проверяет frontmatter, разрешимость относительных
  markdown-ссылок и достижимость каждого файла из `docs/index.md` в едином дереве `docs/`.
