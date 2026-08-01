# HANDOFF — 2026-08-01 — after P1.10

**Next:** `/task:plan-close 2026.07.31-SYS-INTEGRITY P1` (фаза терминальна — все 10 items 🟢/🟠
впервые с реопена; закрыть Status Board + Phase Handoff, ЗАТЕМ повторный
`/task:plan-audit 2026.07.31-SYS-INTEGRITY P1` — переход к P2 (D63) только после GREEN/ATTENTION)

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — предписанный маршрут `plan-close` (§3 Routing — шаг-не-item) |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть фазу P1: Status Board `plan.md` 🟡→🟠, Phase Handoff `phases/P1.md` пересобран из Known Deviations всех 10 items (в т.ч. НОВОЙ находки P1.10 — partition-drift), затем handoff указывает на повторный `plan-audit P1` |

```
/task:plan-close 2026.07.31-SYS-INTEGRITY P1
```

**Done:** **P1.10 закрыт 🟠 Done with deviations** (`27f724a`). Три утверждения доки приведены к
границе D81: `use_proxy`/`proxy_country` потребляются РОВНО на скоупе `crawl_listing`
(`CsvSourceMethod::listingLease()`); `crawl_detail`/`document_download` названы явно как не видящие
профиль — их гео закрывает политика скоупа `required_country` (добавлен рядом с
`forbidden_countries`, обе гео-политики складываются союзом); direct-лиза описана как операторский
режим ТОЛЬКО для `crawl_listing`, обходящий обе гео-политики целиком; предохранитель
`ProxyPolicyMisconfiguredException` для пустой политики запретов `llm` — описан. Таблица
«утверждение → подтверждающий хит в коде» — в `phases/P1.md` P1.10 Completion Notes.

Повторный приёмочный прогон (полный сюит, `docker exec agelto_app php artisan test`, дерево
`27f724a`): **926 passed / 13 failed / 1 skipped (3944→3904 assertions)**. Два падения — РОВНО
допустимые D86 (`CoreKnowsNoDocumentTypeTest`). Одиннадцать — **НОВАЯ находка этой сессии**:
partition-drift Postgres (`pg_partman` premake'ает партиции ТОЛЬКО вперёд от текущего часа;
календарь пересёк границу месяца 31.07→01.08 между `--filter`-прогонами P1.9 и этим первым полным
прогоном сессии; тестовые фикстуры сеют даты прошлого месяца — `prc_events.occurred_at`,
`llm_calls.called_at` — и падают на `no partition of relation "<table>" found for row`). Причина —
инфраструктурная, не в диффе P1.10 (два `.md`-файла) и не в предмете фазы P1 (прокси). Полный
разбор, список падений и фикс-предложение — `phases/P1.md` P1.10 Completion Notes/Pending Work.

Фаза P1 остаётся **4/10 🟢** (P1.10 закрыт 🟠, счётчик не растёт), но **все 10 items терминальны**
(🟢/🟠) впервые с реопена. `## Phase Status` `plan.md` НЕ переведён в `🟢`/`🟠` в этой сессии —
закрытие фазы (bookkeeping Phase Handoff + смена статуса) явно требует повторного `plan-audit`
(handoff предыдущей сессии, D82 п.4) — plan-exec не подменяет этот шаг.

**Known Deviations P1.10 (кратко, полный текст — `phases/P1.md` P1.10 Known Deviations):**
Validation осталась красной шире допустимого D86 — 13 failed вместо 2 (material-триггер
status-rule); 11 лишних падений НЕ починены в рамках Scope (docs-only item, причина —
partition-drift, не доки и не прокси). Routing — точное совпадение (`plan-exec` предписан;
исполнено `sonnet/medium` — пин команды, Routing-строка `implementation/low` ниже пина, легальный
перебор эффорта, не material).

**Remaining:** 42 items из 52 (P1.9 и P1.10 закрыты). Очередь фаз (D63): `P1 → P2 → P3 → P5 → P6 →
P4 → P7`; переход к P2 — ПОСЛЕ повторного `plan-audit P1` (см. Next).

**Sources of truth:** `plan.md` (Meta v0.12.0, §3 Routing поitem'ный, §5 D1–D87, §4 Status Board —
P1 4/10 🟡 In progress) · `phases/P1.md` (items + блок `## Audit P1 — 2026-07-31`, P1.9/P1.10
Completion Notes) · `research/02-proxy-routing.md` (§8 гейт стран, §9.1 контракт резолвера, §10
пустое множество) · `phases/P2.md`…`P7.md` · `roadmap.md` · `index.md` · `open-questions.md` ·
`findings/` (слой 1) · `brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:**

- **P1 несёт три items 🟠** (P1.4, P1.5, P1.6, P1.8, P1.9, P1.10 — шесть, не три; см. `plan.md`
  §4 Status Board) — повторный `plan-audit P1` решает, терминальна ли фаза как есть или нужен
  третий заход.
- **NEW: partition-drift — 11 падений полного сюита вне предмета плана** (найдено P1.10):
  `pg_partman` premake только вперёд, тестовые фикстуры с датами прошлого месяца ломаются на
  границе календаря; фикс-предложение — `phases/P1.md` P1.10 Pending Work (миграция
  `setup_pg_partman` либо перевод фикстур на относительные даты, либо досоздание прошлых партиций
  перед `RefreshDatabase`). Владелец решает — отдельный fix-item/план (инфраструктурный класс, не
  доменный) или подхватывается веткой P7 (партиционирование уже её предмет, D33/P7.7).
- **OQ-4 (браузерный канал) остаётся открытым** — решение владельца.
- **`use_proxy = false` обходит ОБЕ гео-политики** — на скоупе `crawl_listing` намеренно (D83
  Consequences), задокументировано P1.10.
- **Два красных arch-теста** `CoreKnowsNoDocumentTypeTest` (pre-existing, `WorkingCopyProvisioner.php:
  Estimate`) — исключены из критерия приёмки P1.9/P1.10 поимённо (D86), адреса починки в этом
  плане нет.
- **Разовый флейк `CsvBlockIngestorTest`** замечен на одном из нескольких полных прогонов P1.9 —
  не воспроизведён в полном прогоне P1.10; не предмет P1, адреса в этом плане нет.
- **OQ-1 закрыт (D71 — одна БД)**, подтверждение упирается в границу измеримости (D57/D61 — P7.3).
- **`prc_revisions` растёт неограниченно** (D74) — снимается наблюдением в P7.1.
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — сверяется заново перед стартом P5
  (D41, D42).
- **P4.7 — точка невозврата по схеме** — P5 и P6 обязаны быть терминальны до него (D47, D12).
- **Три платных LLM-цикла** (P2.2/P2.5, P5.4, P5.7) — апрувятся владельцем на гейте P5.4 (D26, D45).
- **`docker compose` на хосте не поднимается** (`MINIO_ROOT_PASSWORD`) — использовать `docker exec
  agelto_app` вместо `docker compose exec app`.
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри `Llm` —
  PROMOTE-CANDIDATE, не предмет P1.
- **Правка ТЗ P1.2/P1.3 (D20) отложена владельцем** — незакрытая D-запись, follow-up перед P2.
- **`verify-citations.py` — находки вне диффа закрытия P1** (устаревшие пути/строки в `plan.md`,
  `research/02-proxy-routing.md`, `phases/P7.md`; baseline-файла исключений в проекте нет) —
  follow-up вне scope.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* нет.
- *Deferred:* перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  вторая БД и репликация — закрыто D71 · положения (3)–(5) целевой модели хранения → OQ-5 ·
  переход транспорта ЕИС на XML/SOAP (D14) · переименование дисков в поколенческие имена (D53) ·
  судьба таблицы `cache` (D75) · связь «выделенные узлы под конкретный профиль» (D80) ·
  `ProxyForm::COUNTRIES` как константа класса (D86, Pending Work P1.7) · починка двух pre-existing
  `CoreKnowsNoDocumentTypeTest` (D86 — адреса в плане нет) · разовый флейк `CsvBlockIngestorTest`
  (замечен на P1.9, не предмет плана) · partition-drift 11 падений (найдено P1.10, вне плана —
  фикс-предложение в `phases/P1.md` P1.10 Pending Work).
- *Open questions:* OQ-1 **закрыт** (D71) · OQ-2 (судьба `PromptContextGenerator`, снимает P2.5) ·
  OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал — решение владельца) · OQ-5
  (продуктовый контур — статус сверяется в P7.8).
