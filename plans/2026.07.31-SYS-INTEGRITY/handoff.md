# HANDOFF — 2026-07-31 — after P1

**Next:** `/task:plan-audit 2026.07.31-SYS-INTEGRITY P1`

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | xhigh — фаза закрыта с 🟠-items, аудит перед следующей фазой |
| Context | NEW SESSION — шаг-не-item |
| Суть | Adversarial-аудит закрытой фазы P1: сверка 8 items (5 🟢 + 3 🟠) с git-фактами, no-op, scope drift |

```
/task:plan-audit 2026.07.31-SYS-INTEGRITY P1
```

**Done:** **Фаза P1 закрыта 🟠 Done with deviations** (8/8 items терминальны: P1.1/P1.2/P1.3/P1.7/
P1.8 — 🟢, P1.4/P1.5/P1.6 — 🟠). Прокси переведён из списка узлов в слой маршрутизации:
`ProxyScope`-таксономия, типизированная схема (страна, per-target здоровье), `ProxyResolver` с
политикой выбора и circuit breaker'ом, гейт запрещённых стран и LLM-канал через общий пул
(`OutboundProxy`), три HTTP call-site'а кроулинга и Filament-поверхность переведены на скоупы
резолвера, доки домена и оба глоссария синхронизированы, приёмочный прогон фазы (P1.8, якорь
`40f9611`) зелёный.

**Известные отклонения фазы (агрегат по items, механически из Known Deviations):**

- P1.1 — правка ТЗ P1.2/P1.3 по двум найденным расхождениям выполнена не полностью (проект
  D-записи есть, правка ТЗ отложена директивой владельца). Process, не material. Возобновление:
  `/task:plan-design 2026.07.31-SYS-INTEGRITY P1.2 P1.3`.
- P1.2 — process-отклонение: первый прогон `pint --dirty` без `--test`, откачен вручную.
- P1.4 — Pre-mutation Task Contract запущен после первых правок файлов, не строго до первой
  мутации; гейт вернул `allow`/`allow` на фактическом маршруте — процессное отклонение.
- P1.5 — item-коммит несёт дифф вне `Files` (контрактные DTO/exception, `@property`-докблоки
  моделей, сокращение `phpstan-baseline.neon`) — статус `🟠`.
- P1.6 — (1) дифф вне `Files` — заглушка `nullOutboundProxy()` в `PrismGeminiProviderTest.php`
  для 11 вызовов без аргументов; (2) Validation «grep пусто» не выполнена буквально — один
  пред-существующий хит вне диффа этого item'а.
- P1.7 — item-коммит `da6aed1` несёт дифф вне `Files` — три Feature-теста с сидом активного узла
  (резолвер fail-closed на пустом пуле).
- P1.3, P1.8 — отклонений нет.

**Docs-sync:** обновлено (P1.8) — `docs/03_Dev/crawling-pipeline.md`, `docs/03_Dev/database.md`,
`docs/03_Dev/architecture.md`, `docs/03_Dev/llm-providers.md`, `docs/03_Dev/admin-panel.md`,
`docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/glossary.md`,
`.ai/guidelines/glossary.md`, `CLAUDE.md`.

**Remaining:** 42 items из 50 (закрыто 8 в P1). Очередь фаз (D63):
`P1 → P2 → P3 → P5 → P6 → P4 → P7`. Фаза P1 несла 🟠-items — по канону `plan-close` следующий шаг
перед стартом P2 — `plan-audit P1`, не сразу `plan-design P2`.

**Sources of truth:** `plan.md` (Meta v0.11.2, §3 Routing поitem'ный, §5 D1–D81, §4 Status Board —
P1 5/8 🟠 Done with deviations) · `research/02-proxy-routing.md` · `phases/P1.md`…`P7.md` ·
`roadmap.md` (очередь фаз и батчи B1/B2/B3) · `index.md` · `open-questions.md` (OQ-1
`Resolved → D71`, OQ-2…OQ-5) · `findings/` (слой 1) · `brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:** (без изменений против предыдущего handoff — закрытие фазы документирует
состояние как есть, не меняет код)

- **OQ-4 (браузерный канал) остаётся открытым** — решение владельца.
- **`use_proxy`/`proxy_country` профиля резолвером P1.6 НЕ потребляются** — задокументировано как
  факт (не баг), кандидат в Pending Work фазы, где эти call-site'ы калибруются (P4).
- **`required_country` per-скоуп политика конфига НЕ заведена** — то же ограничение, что P1.5/P1.6
  назвали.
- **`ProxyForm::COUNTRIES`** — закрытый список из 10 кодов, публичная константа класса, не
  конфиг/справочник — PROMOTE-CANDIDATE при росте числа стран.
- **OQ-1 закрыт (D71 — одна БД)**, подтверждение упирается в границу измеримости (D57/D61 — P7.3).
- **`prc_revisions` растёт неограниченно** (D74) — снимается наблюдением в P7.1.
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — сверяется заново перед стартом P5
  (D41, D42).
- **P4.7 — точка невозврата по схеме** — P5 и P6 обязаны быть терминальны до него (D47, D12).
- **Три платных LLM-цикла** (P2.2/P2.5, P5.4, P5.7) — апрувятся владельцем на гейте P5.4 (D26, D45).
- **`docker compose` на хосте не поднимается** (`MINIO_ROOT_PASSWORD`) — использовать `docker exec
  agelto_app` вместо `docker compose exec app`.
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри
  `Llm` — PROMOTE-CANDIDATE, не предмет P1.
- **`app/Dto/Procurement/Proxy/ProxyLease.php`** несёт историческую ссылку на удалённый
  `ProxyPool::reportFailure()` в докблоке (не код) — косметика.
- **Правка ТЗ P1.2/P1.3 (D20) отложена владельцем** — незакрытая D-запись, follow-up перед
  началом P2 или в рамках `plan-audit P1`.
- **`verify-citations.py` — 81 находка вне диффа закрытия P1** (устаревшие пути/строки в
  `plan.md`, `research/02-proxy-routing.md`, `phases/P7.md` — не новые, baseline-файла
  исключений в проекте нет) — follow-up вне scope этого закрытия.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* нет.
- *Deferred:* перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  вторая БД и репликация — закрыто D71 · положения (3)–(5) целевой модели хранения → OQ-5 ·
  переход транспорта ЕИС на XML/SOAP (D14) · переименование дисков в поколенческие имена (D53) ·
  судьба таблицы `cache` (D75) · связь «выделенные узлы под конкретный профиль» (D80) · политика
  `required_country` — не заводилась ни одним item'ом P1 · подстановка `use_proxy`/`proxy_country`
  профиля в резолвер — не в предмете P1, кандидат P4.
- *Open questions:* OQ-1 **закрыт** (D71) · OQ-2 (судьба `PromptContextGenerator`, снимает P2.5) ·
  OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал — решение владельца) · OQ-5
  (продуктовый контур — статус сверяется в P7.8).
