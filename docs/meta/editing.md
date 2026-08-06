---
audience: shared
type: explanation
status: living
---

# Как править документацию

## Где что живёт

- `docs/` — публичная документация, зеркалится в Obsidian-vault Brain (`~/Vaults/Brain/.projects.json`
  задаёт связку проекта с vault'ом).
- `docs-private/` — чувствительное (реальная экономика/лимиты, глубокая промт-инженерия, риски) —
  НЕ зеркалится наружу.
- Каркас разделов (что за что отвечает, куда класть новый файл) — SSOT
  `plans/archive/2026.07.07-EST-AI/research/H-docs-structure.md`. Новый раздел сверх этого дерева — сначала решение
  в Decision Log плана, потом файл (см. `plan.md` §Execution Rules).
- `docs/specs/v2/**` — рабочее ТЗ (не справочник), живёт до завершения плана EST-AI; из него
  транскрибируются факты в `03_Dev/**`, содержимое не копируется побайтно.

## Frontmatter — обязателен в каждом файле

```yaml
---
audience: dev | operator | owner | shared
type: howto | reference | explanation | runbook | adr
status: draft | living
last-reviewed: YYYY-MM-DD   # только для SOP (04_Operators/how-to/**)
---
```

`status: draft` — заглушка с аннотацией «что здесь будет»; `status: living` — раздел наполнен и
поддерживается. `last-reviewed` обновляйте при каждой содержательной правке SOP.

## Диаграммы

Mermaid — инлайн в markdown-файле (рендерится и GitHub, и Obsidian), отдельные файлы только для
диаграмм с двумя и более использованиями в разных документах. Известный gotcha: `stateDiagram-v2`
не парсит `::` в некавычечной edge-метке — используйте `Class.method()`, а не `Class::method()`,
в подписях переходов (в sequence/flowchart-диаграммах и в кавычечных `"..."`-метках `::` не
ломается).

## Один канонический документ

Никаких копий текста между разделами — только ссылки. Пример: `resources/ai/prompts/README.md` —
единственный источник «как писать промт», `03_Dev/ai/prompts.md` на него ссылается, не копирует.

## PR-чеклист

Правило сформулировано как source в `.ai/guidelines/docs-pr-checklist.md` — применяется ко ВСЕМ
PR: «docs обновлены?», и отдельно для правок Filament-ресурсов — «операторский SOP обновлён?».

## Brain-sync

`docs/` (и всё, что не в `docs-private/`) зеркалится в Obsidian-vault Brain. Наверх — нумерованные
категории (`00_Ideas`…`04_Operators`), которые vault уже ожидает по этим именам. Переименование
верхнеуровневой категории — решение, затрагивающее внешнюю систему, не делается по ходу мелкой
правки.
