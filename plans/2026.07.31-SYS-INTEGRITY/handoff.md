# HANDOFF — 2026-08-01 — after P1.9

**Next:** `/task:plan-exec 2026.07.31-SYS-INTEGRITY P1.10`

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписанный пин `plan-exec` (Routing P1.10 `implementation/low` ниже — легальный перебор) |
| Context | continue (/clear) — ручной item |
| Суть | P1.10: доки домена приводятся к факту P1.9 (границы D81 по скоупу) и повторная приёмка фазы с честными числами (два pre-existing падения — поимённо, D86) |

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P1.10
```

**Done:** **P1.9 закрыт 🟠 Done with deviations** (`f153f48`). Вторая половина требования
безопасности фазы («кроулинг ЕИС обязан идти через российский прокси») теперь выражена данными и
фильтром: `CsvSourceMethod::collectListing` строит `ProxyRequirements(country:
$crawler->proxy_country)` и уходит в `ProxyLease::direct()` (с `Log::info`) при
`$crawler->use_proxy === false`; `config/crawler.php` несёт `required_country` для всех четырёх
скоупов (`crawl_*`/`document_download` → `'RU'`, `llm` → `null`); `ProxyResolver::candidates()`
применяет его жёстким SQL-фильтром наравне с `forbidden_countries`, ДО `requirements?->country` —
обе гео-политики складываются союзом, конфликт даёт пустое множество и штатный
`NoProxyAvailableException`. Предохранитель F11: пустая политика запретов для `llm` бросает НОВЫЙ
`ProxyPolicyMisconfiguredException` (наследник `OperationFailed`), который `PoolOutboundProxy` не
ловит — env-фолбэк на пустой политике больше не срабатывает молча (D84). Шесть обязательных
тест-кейсов — все прошли (`ProxyResolverTest` 13p, `CsvSourceMethodTest` 10p,
`GeminiProxyRoutingTest` 9p, `CrawlerProxySettingsTest` 2p — без регрессии).

Фаза P1 остаётся **4/10 🟢** (P1.9 закрыт 🟠, счётчик `Items 🟢/всего` не растёт) — **🟡 In
progress**, терминальна после P1.10.

**Known Deviations P1.9 (кратко, полный текст — `phases/P1.md` P1.9 Known Deviations):**
item-коммит `f153f48` несёт дифф вне `Files` item'а — (1) `@property`-докблок
`Crawler.php` (нужен `phpstan`, иначе 3 новые ошибки `property.notFound` на
`use_proxy`/`name`/`proxy_country`); (2) `phpstan-baseline.neon` 69→62 — докблок сделал 7 старых
записей `unmatched`, удалены (не рост); (3)–(6) `.country('RU')` на сидах
`crawl_detail`/`document_download` в `FetchDetailsTest`, `CardCollectDetailsTest`,
`DetailAnalysisProcessorTest`, `DocumentDownloaderTest` — `required_country` теперь фильтрует ОБА
call-site'а без изменения их сигнатур (D83 (а)), а эти интеграционные тесты сидировали узел без
страны (тот же класс отклонения и приём, что P1.6/`da6aed1`). Routing — точное совпадение
(`implementation/high` предписано, `sonnet/high` исполнено), перебора нет.

**Remaining:** 43 items из 52 (P1.9 закрыт, P1.10 остаётся). Очередь фаз (D63):
`P1 → P2 → P3 → P5 → P6 → P4 → P7`; переход к P2 — ПОСЛЕ закрытия P1.10 и повторного `plan-audit
P1` (фаза несёт 🟠-items).

**Sources of truth:** `plan.md` (Meta v0.12.0, §3 Routing поitem'ный, §5 D1–D87, §4 Status Board —
P1 4/10 🟡 In progress) · `phases/P1.md` (items + блок `## Audit P1 — 2026-07-31`) ·
`research/02-proxy-routing.md` (§8 гейт стран, §9.1 контракт резолвера, §10 пустое множество) ·
`phases/P2.md`…`P7.md` · `roadmap.md` · `index.md` · `open-questions.md` · `findings/` (слой 1) ·
`brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:**

- **P1.10 обязан привести три утверждения доков к факту** (D87): `use_proxy`/`proxy_country`
  теперь верны для `crawl_listing`, но НЕ для `crawl_detail`/`document_download` — правка называет
  границу по скоупу, не снимает утверждение целиком.
- **OQ-4 (браузерный канал) остаётся открытым** — решение владельца.
- **`use_proxy = false` обходит ОБЕ гео-политики** (намеренный режим `research/02` §10, D83
  Consequences) — виден логом `direct`-лизы (реализовано P1.9); строка доки — предмет P1.10.
- **Два красных arch-теста** `CoreKnowsNoDocumentTypeTest` (pre-existing, `WorkingCopyProvisioner.php:
  Estimate`) — исключены из критерия приёмки P1.10 поимённо (D86), адреса починки в этом плане нет.
- **Разовый флейк `CsvBlockIngestorTest`** замечен на одном из нескольких полных прогонов
  (`tests/Feature/Crawler/CsvBlockIngestorTest.php` > «повтор того же блока…») — тест не трогает
  прокси-слой, не воспроизведён на дереве ДО диффа P1.9 и на повторном прогоне С диффом; не предмет
  P1, адреса в этом плане нет.
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
  (замечен на P1.9, не предмет плана).
- *Open questions:* OQ-1 **закрыт** (D71) · OQ-2 (судьба `PromptContextGenerator`, снимает P2.5) ·
  OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал — решение владельца) · OQ-5
  (продуктовый контур — статус сверяется в P7.8).
