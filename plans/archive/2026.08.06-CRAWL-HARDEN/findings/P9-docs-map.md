# P9.1 — ревизия документации и карта переноса

**Дата замера:** 2026-08-08 · **HEAD:** `c1f071c4` · **Рабочее дерево:** dirty, замер снят по
фактической файловой системе с обязательным указанием HEAD. P9.1 не перемещает и не удаляет файлы.

## 1. Перечень деревьев на момент исполнения

Команда инвентаризации:

```bash
find docs docs-private -name '*.md' -print | sort
```

Получено **84** Markdown-файла: **76** в `docs/**` и **8** в `docs-private/**`.
Канонические `docs/00_Ideas`–`docs/04_Operators`, `docs/index.md` и `docs/meta/editing.md`
остаются на месте. Переносу или удалению подлежат только перечисленные ниже 18 `.md`-носителей:

```text
docs-private/ai-economics.md
docs-private/crawler-gotchas.md
docs-private/history/carryover-from-v1.md
docs-private/history/roadmap.md
docs-private/local-llm-training.md
docs-private/open-follow-ups.md
docs-private/prompt-engineering-notes.md
docs-private/risk-register.md
docs/dev/subagents-and-workflows.md
docs/specs/v2/00-overview.md
docs/specs/v2/01-database.md
docs/specs/v2/02-crawling.md
docs/specs/v2/03-document-pipeline.md
docs/specs/v2/04-llm-providers.md
docs/specs/v2/05-admin-observability.md
docs/specs/v2/06-roadmap.md
docs/specs/v2/07-carryover-from-v1.md
docs/specs/v2/08-estimate-extraction.md
```

Кроме `.md`-периметра карты, команда `ls -la docs/dev` подтверждает резервную копию
`docs/dev/subagents-and-workflows.md.bak`. Она не является строкой карты, но должна быть удалена
вместе с нижнерегистровым каталогом владельцем переноса `docs/dev`.

Счёт строк исходников: `docs-private/**` — **566**, `docs/specs/v2/**` — **1759**,
`docs/dev/subagents-and-workflows.md` — **179**. Всего в двух удаляемых деревьях — 2325 строк,
а с нижнерегистровым файлом вне канона — 2504 строки.

## 2. Письменное правило живого факта

Переносим факт, только если он описывает текущее поведение либо сохраняет причину принятого
решения. Не переносим с одной из пяти причин закрытого списка:

1. `план работ`;
2. `критерий приёмки фазы`;
3. `открытый вопрос уже закрытого плана`;
4. `имя таблицы/класса, не подтверждённое деревом`;
5. `дубль существующего раздела`.

Для спеки слово «частично» ниже всегда сопровождается номерами разделов. Спорные строки не
решаются P9.1: они помечены `[решает P9.3/P9.4/P9.5/P9.8 при переносе]` с вариантами.

## 3. Карта переноса — 18 из 18 файлов

| Источник | Вердикт и адрес | Живые факты, которые забираем | Сознательно не переносим |
|:--|:--|:--|:--|
| `docs-private/ai-economics.md` | целиком → `docs/03_Dev/ai/economics.md` (P9.3) | §§1–5: датированные тарифы/EOL, порядок стоимости, `ai:cost-report`, квоты и явно названные неизвестные | —; датированные значения остаются датированными, а не выдаются за вечные |
| `docs-private/crawler-gotchas.md` | частично по двум адресам (P9.3): §1 → `docs/03_Dev/entity-lifecycle-map.md`; §§2–8 → `docs/03_Dev/crawling-pipeline.md` / «Ловушки» | все восемь именованных ловушек: anonymous organization, SAVEPOINT, `participant_ordinal`, N+1, ADR-0009 owner, `dateField`, update-window API, синтетические HTML-фикстуры | дубли формулировок уже существующих разделов — `дубль существующего раздела`; сами восемь фактов не теряются |
| `docs-private/history/carryover-from-v1.md` | удалить (P9.3; содержательный дубль растворяемого `docs/specs/v2/07-carryover-from-v1.md`) | ничего отдельного: происхождение остаётся в git и архивном плане | весь файл — `дубль существующего раздела`; v1-ориентиры дополнительно мертвы |
| `docs-private/history/roadmap.md` | удалить (P9.3; дубль `docs/specs/v2/06-roadmap.md`) | ничего отдельного | весь файл — `дубль существующего раздела`; фазы и acceptance — `план работ` / `критерий приёмки фазы` |
| `docs-private/local-llm-training.md` | целиком → `docs/03_Dev/ai/local-llm-training.md` (P9.3) | §§1–6: условия старта, датированный стек, инструменты, конфиг-скелет, serve/shadow, DPO; все внешне меняющиеся версии сохраняют требование перепроверки | — |
| `docs-private/open-follow-ups.md` | частично → `docs/03_Dev/open-follow-ups.md` (P9.3) | §§1–4 как открытые долги; §5 как ссылка на живую ловушку `participant_ordinal` | §6 — `открытый вопрос уже закрытого плана`; `[решает P9.3 при переносе]`: удалить §6 либо оставить короткой закрытой audit-строкой вне списка открытых |
| `docs-private/prompt-engineering-notes.md` | целиком → `docs/03_Dev/ai/prompt-engineering.md` (P9.3) | §§1–6: происхождение промтов, антипаттерны, composite versioning, отказ от fixed-token chunking, диагностика деградации, связь с training loop | повтор технической инструкции из `resources/ai/prompts/README.md` — `дубль существующего раздела` (сам источник уже ссылается, не копирует) |
| `docs-private/risk-register.md` | частично → `docs/01_Business/risk-register.md` (P9.3) | сводка закрытых рисков как причины решений, остаточное наблюдение ADR-0009, принятый компромисс единого `prc_` | финальный блок «Фаза 4/5 ещё не реализована» — `открытый вопрос уже закрытого плана`; `[решает P9.3 при переносе]`: удалить как stale или оставить датированной историей |
| `docs/specs/v2/00-overview.md` | частично → `docs/01_Business/overview.md` (§1 границы) и `docs/03_Dev/architecture.md` (§§2–3, 6) (P9.4) | §1 границы/аудитории; §2 причины принципов; §3 контейнеры только после сверки с кодом; §6 действующие НФТ; из §4–5 только отсутствующие в живых glossary/workflow факты | §7 — `план работ`; совпавшие §4–5 — `дубль существующего раздела`; старые префиксы/контейнеры — `имя таблицы/класса, не подтверждённое деревом` |
| `docs/specs/v2/01-database.md` | частично → `docs/03_Dev/database.md` (P9.4) | §§0/0.1 инварианты и причины; §2 entity-resolution; §3.1 codelist; §§9–10 storage/search; §11 сценарии как инварианты; из §12 только ещё действующие ограничения | табличный каталог §§1–8 — `дубль существующего раздела` фактической схемы P8.4; отменённые/старые имена — `имя таблицы/класса, не подтверждённое деревом`; закрытое из §12 — `открытый вопрос уже закрытого плана` |
| `docs/specs/v2/02-crawling.md` | частично → `docs/03_Dev/crawling-pipeline.md` (P9.4) | §§1–6 source-контракт, CSV/cap, fresh/archive, detail и устойчивость; §8 мост/ADR-0009; из §7 только подтверждённая provider-agnostic browser boundary | совпавшее с текущим pipeline — `дубль существующего раздела`; непостроенные детали browser backend — `план работ`; §9 закрытые/непроверенные вопросы — `открытый вопрос уже закрытого плана` либо `имя таблицы/класса, не подтверждённое деревом` |
| `docs/specs/v2/03-document-pipeline.md` | частично → `docs/03_Dev/document-pipeline.md` (P9.4) | §§1–5 действующие инварианты, FSM/stages и queue ownership; §6 только сценарии, которых ещё нет в каноне | совпавшие абзацы — `дубль существующего раздела`; §7 — `открытый вопрос уже закрытого плана`; любые старые job/enum-имена — `имя таблицы/класса, не подтверждённое деревом` |
| `docs/specs/v2/04-llm-providers.md` | частично → `docs/03_Dev/llm-providers.md` (P9.4) | §§1–7 только подтверждённые контракт, routing, caching, eval/shadow и cost reasons | совпавшее — `дубль существующего раздела`; §8 и нереализованные кандидаты — `открытый вопрос уже закрытого плана` / `план работ`; неподтверждённые адаптеры — `имя таблицы/класса, не подтверждённое деревом` |
| `docs/specs/v2/05-admin-observability.md` | частично → `docs/03_Dev/admin-panel.md` (P9.4) | §§1–6: только подтверждённые текущими P7 ресурсами возможности, метрики, алерты, разделение журналов и health surfaces | UI/ресурс, которого нет в дереве, — `имя таблицы/класса, не подтверждённое деревом`; уже описанные P7 поверхности — `дубль существующего раздела` |
| `docs/specs/v2/06-roadmap.md` | удалить после одной строки-сводки → `docs/01_Business/roadmap.md` (P9.4) | из §§Фаза 0–8 только исторический факт «система реализована вертикальными срезами от каркаса до админки/наблюдаемости» и ссылка на архивный план как provenance | подробные списки фаз — `план работ`; все строки «Приёмка» — `критерий приёмки фазы`; таблица соответствий — `дубль существующего раздела` |
| `docs/specs/v2/07-carryover-from-v1.md` | удалить без переноса (P9.4) | ничего: живой код и архивный план заменили этот переходный носитель | §§0–7 — `план работ`, `критерий приёмки фазы` и мёртвые `Old/**`/классы — `имя таблицы/класса, не подтверждённое деревом` |
| `docs/specs/v2/08-estimate-extraction.md` | целиком → `docs/03_Dev/ai/estimate-extraction.md` с сохранением §§1–9 и приложения (P9.5) | весь связный контракт: инвентарь, словари, response contracts, оба алгоритма, экономика с датами, prompt/injection rules, dataset/eval/export, retention/backup; конфликт с кодом становится Pending Work | —; сокращение запрещено P9.5, а устаревшая строка помечается вместо молчаливой редакции |
| `docs/dev/subagents-and-workflows.md` | целиком → `docs/03_Dev/ai-context/subagents-and-workflows.md`; `.bak` удалить (P9.2) | весь generated-core guideline: task entrypoints, dev-loop, agent roles, review gate, project gates, checklist; перенос — `git mv` с сохранением generated-header, как прямо требует P9.2 | — |

Счётная сверка карты:

```bash
find docs-private docs/specs/v2 docs/dev -name '*.md' -print | wc -l
# 18
```

Таблица выше содержит **18** строк источников: 18 = 8 `docs-private` + 9 `docs/specs/v2` +
1 `docs/dev`. Каждый источник имеет адрес либо явную причину удаления.

## 4. Живые ссылки и item-правщик — 37 из 37 строк grep

Команда:

```bash
grep -rn "docs-private\|specs/v2" docs .ai .claude CLAUDE.md
```

| № | Совпадение | Владелец правки |
|--:|:--|:--|
| 1 | `docs/index.md:10` — общий `docs/specs/v2/` | P9.5 (финальный роспуск каталога) |
| 2 | `docs/index.md:43` — общий `docs/specs/v2/` | P9.5 |
| 3 | `docs/index.md:44` — `docs-private/` | P9.3 |
| 4 | `docs/03_Dev/_index.md:34` — общий `docs/specs/v2/` | P9.4; его `Files` надо расширить этим путём либо явно передать P9.5 |
| 5 | `docs/03_Dev/llm-providers.md:99` — `docs-private/ai-economics.md` | P9.3 |
| 6 | `docs/03_Dev/adr/0009-job-owns-document-transition.md:15` — private risk | P9.3 (меняется только путь) |
| 7 | `docs/03_Dev/adr/0009-job-owns-document-transition.md:18` — spec 02 | P9.4 (меняется только путь/history wording) |
| 8 | `docs/03_Dev/adr/0009-job-owns-document-transition.md:59` — spec 02 §8 | P9.4 |
| 9 | `docs/03_Dev/adr/0009-job-owns-document-transition.md:71` — crawler gotchas | P9.3 |
| 10 | `docs/03_Dev/adr/0009-job-owns-document-transition.md:82` — private risk/gotchas | P9.3 |
| 11 | `docs/03_Dev/adr/0009-job-owns-document-transition.md:94` — spec 02 §8 | P9.4 |
| 12 | `docs/03_Dev/adr/0010-input-representation.md:166` — spec 08 §5.4 | P9.5 |
| 13 | `docs/03_Dev/adr/0006-bounded-contexts-as-folders.md:18` — `specs/v2/**` | P9.4 (исторический ADR: меняется адрес, не решение) |
| 14 | `docs/03_Dev/ai/prompts.md:166` — private prompt notes | P9.3 |
| 15 | `docs/03_Dev/ai/training-loop.md:11` — spec 08 | P9.5 |
| 16 | `docs/03_Dev/ai/training-loop.md:164` — private local training | P9.3 |
| 17 | `docs/03_Dev/ai/training-loop.md:179` — spec 08 | P9.5 |
| 18 | `docs/03_Dev/document-pipeline.md:147` — spec 08 | P9.5; его `Files` надо расширить этим путём |
| 19 | `docs/meta/editing.md:13` — `docs-private/` | P9.3 |
| 20 | `docs/meta/editing.md:18` — `docs/specs/v2/**` | P9.4 (убирает правило рабочего ТЗ; P9.8 позднее переносит structure SSOT) |
| 21 | `docs/meta/editing.md:55` — `docs-private/` | P9.3 |
| 22 | `docs/specs/v2/03-document-pipeline.md:120` — spec 01 | P9.4 (исчезает вместе с source-файлом) |
| 23 | `docs/01_Business/_index.md:14` — `docs-private/` | P9.3 |
| 24 | `docs/01_Business/overview.md:58` — `docs-private/` | P9.3 |
| 25 | `docs/01_Business/roadmap.md:9` — spec 06 | P9.4 |
| 26 | `docs/01_Business/roadmap.md:48` — spec 06 | P9.4 |
| 27 | `docs/01_Business/roadmap.md:51` — `docs-private/` | P9.3 |
| 28 | `.claude/rules/agelto-reviewer-overrides.md:46` — spec 08 §7 | P9.5 |
| 29 | `.claude/runs/614cd61e-04a8-487c-aff4-97668a764d8c.json:23` — абсолютный spec 01 | `[решает P9.4 при переносе]`: удалить transient run либо обновить; P9.4 Scope включает `.claude/**`, но `Files` путь не называет |
| 30 | `.claude/runs/614cd61e-04a8-487c-aff4-97668a764d8c.json:128` — абсолютный spec 01 | то же решение P9.4, одной операцией с №29 |
| 31 | `.claude/skills/prompt-engineering-agelto/SKILL.md:71` — private prompt notes §5 | P9.3 |
| 32 | `.claude/skills/prompt-engineering-agelto/SKILL.md:86` — private prompt notes | P9.3 |
| 33 | `.claude/skills/llm-layer-dev/SKILL.md:94` — spec 08 | P9.5 |
| 34 | `.claude/skills/estimate-extraction-dev/SKILL.md:13` — spec 08 | P9.5 |
| 35 | `.claude/skills/estimate-extraction-dev/SKILL.md:41` — spec 08 | P9.5 |
| 36 | `.claude/skills/estimate-extraction-dev/SKILL.md:90` — spec 08 §§4в/4г | P9.5 |
| 37 | `.claude/skills/estimate-extraction-dev/SKILL.md:114` — spec 08 §3.2 | P9.5 |

`grep ... | wc -l` даёт **37**, таблица содержит **37** строк. `plans/archive/**` не читались для
поиска и не входят в правки.

## 5. Сверка с рекомендацией research §3

| Рекомендация research | Фактическое решение P9.1 | Почему |
|:--|:--|:--|
| 8 private-файлов: 6 перенести, 2 дубля удалить | совпадает; у `open-follow-ups` и `risk-register` выделены stale/закрытые строки | правило живого факта нельзя подменять переносом закрытого backlog как открытого |
| specs 00–07 растворить, 08 перенести целиком | совпадает; для 00–06 явно названы переносимые и непереносимые разделы | фактический P8/P7 канон уже богаче спеки, а спецификация известна как drift-source |
| `docs/dev/subagents-and-workflows.md` перенести в `03_Dev/ai-context`, `.bak` удалить | совпадает; владелец — P9.2 | P9.2 одновременно заводит гейт, очищает baseline и прямо требует `git mv` с frontmatter |
| замер 83 / 18 / 150 / 0 | фактически 84 / 18 / 158 / 0 | после research дерево изменилось: добавлен один frontmatter-bearing Markdown-файл и восемь относительных ссылок; это ожидаемая execution-time delta, не ошибка research |
| specs — 1739 строк | фактически 1759 строк | P8.4 добавил исторические D82-пометки: `01-database.md` 342 вместо 326, `07-carryover-from-v1.md` 181 вместо 177 |
| живой контур ссылок — rules/skills/navigation/CLAUDE | фактический grep — 37 строк, включая два `.claude/runs` совпадения и новые ссылки из P3–P8 docs | P9.1 обязан следовать команде на текущем дереве, а не списку времени авторинга |

## 6. Передача следующим items

- P9.2 получает baseline **84 / 18 / 158 / 0**, перенос `docs/dev/**` и два временных исключения,
  предусмотренных design.
- P9.3 получает восемь private-строк карты и 14 строк grep, включая два спорных stale-блока.
- P9.4 получает specs 00–07 и 11 строк grep, включая два runtime-provenance совпадения; до исполнения
  должен закрыть указанные расхождения `Files`.
- P9.5 получает spec 08 и 12 строк grep; `docs/03_Dev/document-pipeline.md` надо добавить в его
  `Files` либо зафиксировать иной item-владелец.
- P9.8 получает только позднюю пересборку навигации, живой structure SSOT и orphan-check; перенос
  `docs/dev/**` к этому моменту уже закрывает P9.2.

P9.1 не принял ни одного из этих спорных решений за последующие items и не изменил ни одного файла
вне `plans/**`.
