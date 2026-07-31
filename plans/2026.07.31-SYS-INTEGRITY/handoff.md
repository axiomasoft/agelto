# HANDOFF — 2026-07-31 — after P1.5

**Next:** P1.6–P1.7 (батч B1) — перевод трёх HTTP call-site'ов кроулинга на скоупы резолвера +
Filament-поверхность прокси/привязки профиля

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Context | continue (/clear) — ручной item |
| Суть | B1: подстановка `ProxyScope` в три call-site'а `pick()` по замороженному контракту P1.4, удаление `ProxyPool` (P1.6); Filament-форма прокси/привязки профиля (P1.7) |

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P1.6 P1.7
```

**Done:** **P1.5 закрыт 🟠 Done with deviations** (item-коммит `65b562a`) — гейт запрещённых стран +
LLM-канал через прокси-пул:

- **Порт `App\Services\Llm\Contracts\OutboundProxy`** (`optionsFor(string $host): array`) + адаптер
  **`App\Services\Procurement\Proxy\PoolOutboundProxy`** поверх `ProxyResolver` (D16): резолв
  `ProxyScope::Llm` → `httpOptions()`; пустой пул → фолбэк на `config('ai.gemini.proxy')` +
  `Log::warning`; пустой пул И пустой env → `NoProxyAvailableException` пробрасывается необработанной
  (домен `Llm` её не именует). Биндинг в `AppServiceProvider::register()`.
- **`PrismGeminiProvider`** получил конструктор с `OutboundProxy`; все пять чтений
  `config('ai.gemini.proxy')` заменены на `$this->outboundProxy->optionsFor($this->host())`
  (`host()` — канонический `generativelanguage.googleapis.com`).
- **`ProxyResolver::candidates()`** — жёсткий SQL-гейт (D5): `whereNotIn('prc_proxies.country',
  $forbiddenCountries)` — пустой список не сужает выборку, непустой исключает и запрещённые страны,
  и `NULL` (SQL `NOT IN` с `NULL` → false в WHERE), без отдельного предиката.
- **`config/crawler.php`** → `proxy.policy` — четыре скоупа; `llm` несёт
  `forbidden_countries` из `env('CRAWLER_PROXY_LLM_FORBIDDEN_COUNTRIES', 'RU')`, три
  crawl-скоупа — пустой список (задел под их политику НЕ вводился — не в предмете item'а).

**Known Deviations (статус 🟠, не 🟢):** item-коммит несёт дифф вне `Files` item'а —
`tests/Unit/Llm/PrismGeminiProviderTest.php` (11 вызовов `new PrismGeminiProvider` переведены на
локальную заглушку `nullOutboundProxy()` — конструктор провайдера перестал быть беспараметровым).
Плюс Validation-критерий «`grep -rn "Services\\\\Procurement" app/Services/Llm` — пусто» не
выполнен буквально: один пред-существующий хит (`PromptCanonReader.php:9`, коммит `2a140b9`, чужой
этому item'у) — вне Scope P1.5, назван явно. Подробности — `phases/P1.md` P1.5 Completion Notes.

Validation: `--filter=GeminiProxyRouting` 7 passed/10 assertions (инвариант-кейс §8 на трёх
конфигурациях здоровья + позитивный кейс + фолбэк/исключение адаптера + seam-тест провайдера);
регрессия `--filter=Llm` 120 passed/320 assertions, `--filter=ProxyResolver` 10 passed/15 assertions;
pint чисто по всем 8 файлам диффа; `phpstan analyse` — `[OK] No errors`, baseline 69 записей до и
после (не выросло).

`plan-lint` — счёт см. ниже (после этого коммита bookkeeping).

**Известное для следующих items (не блокер P1.6/P1.7, факт видимости):**

- `ProxyPool::pick(target: …)` по-прежнему вызывает `whereJsonContains('targets', …)` на удалённой
  колонке — упадёт SQL-ошибкой при непустом `target` до перевода call-site'ов (P1.6, D19); живого
  трафика в этом окне нет (D36).
- `app/Filament/Resources/Procurement/Crawler/Proxies/Schemas/ProxyForm.php` ссылается на снятое
  поле `targets` — чинится в P1.7.
- `PromptCanonReader.php` несёт `use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry`
  внутри домена `Llm` (пред-существующий факт, коммит `2a140b9`, не предмет P1) —
  **PROMOTE-CANDIDATE**: находка для отдельной ревизии домена `Llm`, не для этого плана.

**Remaining:** 45 items из 50 (закрыто 5: 3 🟢 + 2 🟠). Фаза P1 — 3/8 🟢 (5 терминальных). Очередь
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
  живого трафика нет (D36), но эта опасность закрывается ИМЕННО P1.6.
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
  сессии) — исполнитель обязан использовать `docker exec agelto_app` вместо `docker compose
  exec app` буквально командой Validation, либо починить `.env` (чужой диф — не трогать без
  директивы владельца).
- **Протокольная дыра (PROMOTE-CANDIDATE, не правится этим планом):** свип после отмены D-записи
  идёт по списку адресов, который пишет человек, и неполон там, где отменённое решение цитируется
  пересказом, а не номером.
- **`PromptCanonReader.php` несёт пред-существующую ссылку на `Services\Procurement`** внутри
  домена `Llm` — см. «Известное для следующих items» выше (PROMOTE-CANDIDATE, не предмет P1).

**Workarounds/Deferred/Open questions:**

- *Workarounds:* нет.
- *Deferred:* перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  вторая БД и репликация — закрыто D71, возврат только по триггеру D72 · положения (3)–(5) целевой
  модели хранения → OQ-5 · переход транспорта ЕИС на XML/SOAP (D14) · переименование дисков в
  поколенческие имена — в момент cutover (D53) · судьба таблицы `cache` — находка ревизии P7.5
  (D75) · связь «выделенные узлы под конкретный профиль» (pivot `prc_crawler_proxy`) — названного
  требования нет (`research/02` §14.1, D80) · починка `ProxyForm.php` (поле `targets`) — P1.7 ·
  политика запретов/требований по стране для `crawl_*`/`document_download` скоупов — не заводилась
  этим item'ом (только структура конфига под неё), не в предмете P1.5/P1.6.
- *Open questions:* OQ-1 **закрыт** (D71) · OQ-2 (судьба `PromptContextGenerator`, снимает P2.5) ·
  OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал — решение владельца; форма
  контракта под него готова, `research/02` §9.3) · OQ-5 (продуктовый контур — основание отдельного
  плана; статус сверяется в P7.8).
