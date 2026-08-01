# HANDOFF — 2026-08-01 — after P1

**Next:** `/task:plan-audit 2026.07.31-SYS-INTEGRITY P1` (фаза закрыта с шестью `🟠`-items —
`plan-close` предписывает аудит перед переходом к P2, D63)

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | xhigh — предписанный маршрут `plan-audit` закрытой фазы с deviations |
| Context | NEW SESSION — шаг-не-item |
| Суть | Adversarial-аудит закрытой фазы P1 (10/10 items, 4×🟢/6×🟠): сверить статусы с git-фактами, проверить, что новая находка partition-drift (P1.10) корректно вынесена за периметр фазы, вынести вердикт GREEN/ATTENTION/RED |

```
/task:plan-audit 2026.07.31-SYS-INTEGRITY P1
```

**Done:** **Фаза P1 закрыта повторно 🟠 Done with deviations** (все 10 items терминальны —
4×🟢 P1.1/P1.2/P1.3/P1.7, 6×🟠 P1.4/P1.5/P1.6/P1.8/P1.9/P1.10). `Phase Handoff` в `phases/P1.md`
перезаписан целиком (маркер `> УСТАРЕЛ — фаза реопенена D82` снят); Status Board `plan.md` §4:
`P1 4/10 🟡 In progress` → `4/10 🟠 Done with deviations`. Known Deviations агрегат собран
механически из полей всех 10 items (без пересказа) — P1.9/P1.10 добавлены к прежним восьми.

Итог фазы: прокси переведён из списка узлов в слой маршрутизации (`ProxyScope`, типизированная
схема, `ProxyResolver` с политикой выбора и circuit breaker'ом), резолвер потребляет привязку
профиля (`use_proxy`/`proxy_country`, скоуп `crawl_listing`) и гео-политику `required_country`
(все четыре скоупа, союз с `forbidden_countries`, P1.9), три HTTP call-site'а и Filament переведены
на скоупы резолвера, доки домена дважды приведены к факту (P1.8 база, P1.10 — граница D81).
Приёмочный прогон фазы (полный сюит, якорь `27f724a`): **926 passed / 13 failed / 1 skipped**
(3904 assertions) — 2 падения допустимые (D86, `CoreKnowsNoDocumentTypeTest`), 11 — НОВЫЙ класс
partition-drift (`pg_partman`, вне предмета фазы, см. Open risks).

Docs-sync (обязательный шаг режима `Pn`): уже исполнен внутри фазы — P1.8 обновил
`docs/03_Dev/{crawling-pipeline,database,architecture,llm-providers,admin-panel}.md`,
`docs/02_Workflow/{crawling-and-discovery,glossary}.md`, `.ai/guidelines/glossary.md`, `CLAUDE.md`;
P1.10 поправил три утверждения в `docs/03_Dev/crawling-pipeline.md` и `docs/03_Dev/database.md`
под факт P1.9. Новых доков в этой сессии не требовалось (пересборка Phase Handoff — не
доки проекта, а plan-артефакт).

**Lint:** `plans/plan-lint.py` в дереве не найден по указанному пути пакета
(`packages/swissknifeman/packages/task/scripts/plan-lint.py`) — шаг пропущен с предупреждением
(правило шага 7: «нет переменной/скрипта — пропуск»), запусти вручную при наличии инструмента.
`verify-citations.py` — не прогонялся в этой сессии (не найден по стандартному пути); ранее
известные находки вне диффа закрытия P1 остаются в Open risks (см. ниже).

**Remaining:** 42 items из 52 (P1 закрыта целиком). Очередь фаз (D63): `P1 → P2 → P3 → P5 → P6 →
P4 → P7`; переход к P2 — после `plan-audit P1` (см. Next).

**Sources of truth:** `plan.md` (Meta v0.12.0, §3 Routing поitem'ный, §5 D1–D87, §4 Status Board —
P1 4/10 🟠 Done with deviations) · `phases/P1.md` (items + `## Audit P1 — 2026-07-31` + Phase
Handoff) · `research/02-proxy-routing.md` (§8 гейт стран, §9.1 контракт резолвера, §10 пустое
множество) · `phases/P2.md`…`P7.md` · `roadmap.md` · `index.md` · `open-questions.md` ·
`findings/` (слой 1) · `brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:**

- **Фаза несёт шесть `🟠`-items** (P1.4, P1.5, P1.6, P1.8, P1.9, P1.10) — `plan-audit P1`
  проверяет, терминальна ли фаза как есть или нужен третий заход.
- **Partition-drift — 11 падений полного сюита вне предмета плана** (найдено P1.10):
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
- **Незапланированные файлы в рабочем дереве вне Files фазы P1**: `M .env.example`,
  untracked `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`,
  `.swissknife/`, `plans/2026.07.31-SYS-INTEGRITY/.claude/`, `plans/2026.07.31-SYS-INTEGRITY/
  .swissknife/` — чужой дифф из `plan.md` §2 (carve-out) плюс скилл-каталоги; не трогать, не
  коммитить с закрытием фазы.

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
