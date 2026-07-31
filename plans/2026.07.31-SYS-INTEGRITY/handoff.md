# HANDOFF — 2026-07-31 — after P1.8

**Next:** P1 терминальна (8/8 items 🟢/🟠) — закрыть фазу

| Параметр | Значение |
|:--|:--|
| Model class | economy |
| Effort | low |
| Context | NEW SESSION — шаг-не-item |
| Суть | Формальное закрытие фазы P1: таблицы, Phase Handoff, финальный `plan-lint` |

```
/task:plan-close 2026.07.31-SYS-INTEGRITY P1
```

**Done:** **P1.8 закрыт 🟢** (item-коммит `40f9611`) — документация домена и приёмочный прогон
фазы:

- Слой маршрутизации прокси (`ProxyScope`, `ProxyResolver`/`reportOutcome`, двухуровневое здоровье
  `prc_proxies`/`prc_proxy_targets`, circuit breaker, гейт запрещённых стран, привязка профиля
  `use_proxy`/`proxy_country`, порт `OutboundProxy`/`PoolOutboundProxy`) описан в
  `docs/03_Dev/crawling-pipeline.md` (новый раздел, починен устаревший `ProxyPool`→`ProxyResolver`),
  `docs/03_Dev/database.md` (таблицы `prc_proxies`/`prc_proxy_targets`), `docs/03_Dev/architecture.md`
  (порт `OutboundProxy`), `docs/03_Dev/llm-providers.md` (раздел «Исходящий прокси»),
  `docs/03_Dev/admin-panel.md` (поверхность `Crawler/Proxies`), `docs/02_Workflow/crawling-and-discovery.md`
  (бизнес-язык).
- Новые термины (`ProxyScope`, `Proxy`/резолвер, per-target здоровье) введены в ОБА глоссария
  (`.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md`); перенос в `CLAUDE.md` — строка
  `Proxy` в «Доменные сущности».
- Приёмочный прогон фазы (якорь `40f9611`): `docker exec agelto_app php artisan test` — 930
  passed/2 failed/1 skipped (3944 assertions), оба падения — pre-existing `CoreKnowsNoDocumentTypeTest`
  (тот же дефект, что в P1.6/P1.7, не наш диф); `pint --dirty --test` — 1 style issue в чужом
  `config/cms.php` (не наш диф), 9 файлов `Files` чисты; `phpstan analyse --memory-limit=1G` —
  `[OK] No errors`, baseline 69 записей — без прироста против `d961495` (до фазы P1).
- **🟢 без отклонений** — item-коммит ровно по путям `Files`.

`plan-lint` (`--baseline HEAD` до bookkeeping-коммита) — см. следующий раздел после bookkeeping.

**Remaining:** 42 items из 50 (закрыто 8: 5 🟢 + 3 🟠). **Фаза P1 терминальна: 8/8 items
(🟢/🟠)** — закрытие таблиц/Phase Handoff/roadmap делает `task:plan-close`, не `plan-exec`. Очередь
фаз (D63): `P1 → P2 → P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.11.2, §3 Routing поitem'ный, §5 D1–D81, §4 Status Board
5/50 🟢) · `research/02-proxy-routing.md` · `phases/P1.md`…`P7.md` · `roadmap.md` (очередь фаз и
батчи B1/B2/B3) · `index.md` · `open-questions.md` (OQ-1 `Resolved → D71`, OQ-2…OQ-5) · `findings/`
(слой 1) · `brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:** (без изменений против предыдущего handoff — P1.8 документирует состояние как есть,
не меняет код)

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
