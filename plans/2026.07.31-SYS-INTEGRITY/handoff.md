# HANDOFF — 2026-07-31 — after P1.4

**Next:** P1.5 — гейт запрещённых стран для LLM-скоупа + LLM-канал через пул (порт `OutboundProxy`)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Context | continue (/clear) — ручной item |
| Суть | P1.5 — порт `OutboundProxy` + адаптер `PoolOutboundProxy` поверх `ProxyResolver`, перевод `PrismGeminiProvider` на порт с env-фолбэком, жёсткий фильтр запрещённых стран ДО скоринга внутри резолвера (D5, D16) |

```
/task:plan-run 2026.07.31-SYS-INTEGRITY P1.5
```

**Done:** **P1.4 закрыт 🟠 Done with deviations** (item-коммит `6fc2e75`, после промежуточного
`f4ffbf1`) — резолвер прокси: политика выбора, двухуровневое здоровье, circuit breaker:

- **`ProxyResolver`** (`app/Services/Procurement/Proxy/ProxyResolver.php`) — единственная точка
  выдачи: фильтр (`is_active`, `scopes @> [scope]`, требование страны профиля, глобальный+целевой
  breaker не открыт) → `ProxyScoreCalculator` → weighted random внутри top-K с инъектируемым
  источником случайности (D5, D6). Гейт запрещённых стран (D5, скоуп `llm`) НЕ входит — заводит
  P1.5 поверх этого же файла (уже в его `Files`). Пустой пул/весь пул занят пробой →
  `NoProxyAvailableException` + `Log::warning` (`research/02` §10 — «тихого прямого запроса не
  существует»).
- **`ProxyCircuitBreaker`** — чистая логика автомата `closed → open → half-open → closed`,
  таксономия причин → уровень (`ProxyFailureReason::level()`-логика внутри breaker'а, не в самом
  enum'е — P1.5 его не трогает); `http_429` — отдельный порог/cooldown целевого уровня (D7).
- **`ProxyHealthStoreRepository`** — CRUD `Proxy`/`ProxyTarget` + атомарный condition-`UPDATE`
  захвата half-open пробы (единственность гарантирует БД).
- **Контракт резолвера** (research/02 §9.1): `ProxyLease`/`ProxyRequirements`/`ProxyOutcome`
  (`app/Dto/Procurement/Proxy/**`) + `NoProxyAvailableException`
  (`app/Exceptions/Procurement/`) — типы контракта, которых не было в `Files` item'а (см. Known
  Deviations ниже).
- **`config/crawler.php`** — блок `proxy.resolver`/`proxy.breaker` (веса, top-K, EWMA, пороги
  breaker'а по уровню, `http_429`-исключение), все значения помечены некалиброванными (P4.7).

**Known Deviations (статус 🟠, не 🟢):** item-коммит несёт дифф вне `Files` item'а —
`app/Dto/Procurement/Proxy/**` (3 файла), `app/Exceptions/Procurement/NoProxyAvailableException.php`
(контракт §9.1 нереализуем без них), `app/Models/Procurement/Crawler/{Proxy,ProxyTarget}.php`
(только `@property`-докблоки — без них phpstan не видит атрибуты `casts()`-метода) и
`phpstan-baseline.neon` (СОКРАЩЁН на 5 устаревших записей `ProxyPool.php`, вскрытых теми же
докблоками). Подробности и обоснование каждого файла — `phases/P1.md` P1.4 Completion Notes.

Validation: `--filter=ProxyResolver` 10 passed/15 assertions, `--filter=ProxyCircuitBreaker` 9
passed/24 assertions (оба на `6fc2e75`), все четыре обязательных тест-кейса Code Guidance
поимённо; регрессия `--filter=ProxyPool` 6 passed, `--filter=CrawlerProxySettings` 2 passed; pint
чисто; `phpstan analyse` — `[OK] No errors`; `grep "orderBy('fails_count')"
app/Services/Procurement/Proxy` — пусто.

`plan-lint` — счёт см. ниже (после этого коммита bookkeeping).

**Известное для следующих items (не блокер P1.5, факт видимости):**

- `ProxyPool::pick(target: …)` вызывает `whereJsonContains('targets', …)` на удалённой колонке —
  упадёт SQL-ошибкой при непустом `target` до перевода call-site'ов (P1.6, D19); живого трафика в
  этом окне нет (D36).
- `app/Filament/Resources/Procurement/Crawler/Proxies/Schemas/ProxyForm.php` ссылается на снятое
  поле `targets` — Filament вне Scope, чинится в P1.7.
- `ProxyResolver::resolve()` НЕ содержит гейт запрещённых стран (§8) — P1.5 добавляет его поверх
  этого же файла (уже в `Files` P1.5), не заново проектирует фильтр.

**Remaining:** 46 items из 50 (закрыто 4: 3 🟢 + 1 🟠). Фаза P1 — 3/8 🟢 (4 терминальных). Очередь
фаз (D63): `P1 → P2 → P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.11.2, §3 Routing поitem'ный, §5 D1–D81, §4 Status Board
3/50 🟢) · `research/02-proxy-routing.md` (вход всех items P1) · `phases/P1.md`…`P7.md` ·
`roadmap.md` (очередь фаз и батчи B1/B2/B3) · `index.md` · `open-questions.md` (OQ-1 `Resolved →
D71`, OQ-2…OQ-5) · `findings/` (слой 1) · `brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:**

- **OQ-4 (браузерный канал) остаётся открытым** — решение владельца. Контракт лизы спроектирован
  так, что канал добавляется без переделки (дискретные `server`/`username`/`password` на
  `ProxyLease`), а сам канал фазой не вводится.
- **Состав `ProxyScope` заморожен миграцией.** Правка после P1.2 = новая миграция на схеме,
  которую P3.2 к тому моменту перенесёт (D4, D18, D30).
- **Инверсия семантики скоупа.** Пустой `scopes` = узел НЕ выдаётся; оператор обязан проставить
  скоупы при заведении узла, иначе пул пуст (fail-closed, сознательный выбор).
- **`ProxyPool::pick(target:)` небезопасен до P1.6** — см. «Известное для следующих items» выше;
  живого трафика нет (D36), но P1.5 НЕ должен звать `pick()` с непустым `target`.
- **OQ-1 закрыт (D71 — одна БД), подтверждение упирается в границу измеримости:** `replica lag` и
  per-query contention снять нечем (D57); P7.3 подтверждает по измеримым с поимённым перечнем
  неизмеренного (D61).
- **`prc_revisions` растёт неограниченно и это принято сознательно (D74)** — P7.1 обязан снять
  число (партиции + суммарный размер) наблюдением.
- **Кэш: часть цели D73 остаётся за владельцем** — `.env`/`.env.example` исполнитель не правит
  (чужой диф), строка гейта в Completion Notes P7.4 (D75).
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — терминальность строк Status Board
  донора P4/P5/P7 проверяется заново перед стартом P5 (D41, D42).
- **P4.7 — точка невозврата по схеме:** после него БД перестаёт быть подвижной; P5 и P6 обязаны
  быть терминальны до него (D47, D12).
- **Три платных LLM-цикла** (P2.2/P2.5, P5.4, P5.7) — реальные деньги, сумма апрувится владельцем
  на гейте P5.4 (D26, D45).
- **Ловушка `plan_lint` ждёт P2, P3 и P4 на старте.** Детект «фаза заявляет оркестрацию» —
  подстрочный и не различает утверждение от отрицания, срабатывает при статусе фазы 🟡+.
  Формулировки без слова-маркера уже стоят в Phase Context P1–P4 (см. предыдущий handoff).
  **PROMOTE-CANDIDATE:** негативный контекст обязан гасить детект.
- **`docker compose` на хосте не поднимается** (`MINIO_ROOT_PASSWORD` отсутствует в окружении
  сессии) — исполнитель P1.5 обязан использовать `docker exec agelto_app` вместо `docker compose
  exec app` буквально командой Validation, либо починить `.env` (чужой диф — не трогать без
  директивы владельца).
- **Протокольная дыра (PROMOTE-CANDIDATE, не правится этим планом):** свип после отмены D-записи
  идёт по списку адресов, который пишет человек, и неполон там, где отменённое решение цитируется
  пересказом, а не номером.
- **Files item'ов P1 не всегда полны для DTO/exception-типов, требуемых замороженным контрактом
  (PROMOTE-CANDIDATE, факт P1.4).** `research/02` §9.1 предписывает точную сигнатуру
  `resolve()`/`reportOutcome()`, но `Files` P1.4/P1.5/P1.6 называют только сервис-классы; типы
  `ProxyLease`/`ProxyRequirements`/`ProxyOutcome`/`NoProxyAvailableException` пришлось заводить вне
  списка (см. Known Deviations выше). P1.5/P1.6 эти типы УЖЕ существуют — доп. файлов по этой
  причине больше не потребуется, но исполнителю следующих items полезно знать источник 🟠 P1.4.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* нет.
- *Deferred:* перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  вторая БД и репликация — закрыто D71, возврат только по триггеру D72 · положения (3)–(5) целевой
  модели хранения → OQ-5 · переход транспорта ЕИС на XML/SOAP (D14) · переименование дисков в
  поколенческие имена — в момент cutover (D53) · судьба таблицы `cache` — находка ревизии P7.5
  (D75) · связь «выделенные узлы под конкретный профиль» (pivot `prc_crawler_proxy`) — названного
  требования нет (`research/02` §14.1, D80) · починка `ProxyForm.php` (поле `targets`) — P1.7.
- *Open questions:* OQ-1 **закрыт** (D71) · OQ-2 (судьба `PromptContextGenerator`, снимает P2.5) ·
  OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал — решение владельца; форма
  контракта под него готова, `research/02` §9.3) · OQ-5 (продуктовый контур — основание отдельного
  плана; статус сверяется в P7.8).
