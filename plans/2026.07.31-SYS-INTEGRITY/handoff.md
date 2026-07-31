# HANDOFF — 2026-07-31 — after P1.6

**Next:** P1.7 — Filament: поверхность прокси и привязки профиля (батч B1, второй item)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Context | continue (/clear) — ручной item |
| Суть | Форма/таблица прокси (страна, скоупы-мультиселект, read-only здоровье) + поля привязки прокси в форме кроулера, Feature-тест Filament |

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P1.7
```

**Done:** **P1.6 закрыт 🟠 Done with deviations** (item-коммит `da6aed1`) — перевод трёх HTTP
call-site'ов кроулинга на скоупы резолвера:

- **`CsvSourceMethod::collectListing`** → `ProxyResolver::resolve(ProxyScope::CrawlListing,
  canonicalHost($endpoint))`; **`CsvSourceMethod::collectTab`** (вкладка карточки) →
  `ProxyScope::CrawlDetail`; **`DocumentDownloader::fetch`** → `ProxyScope::DocumentDownload`.
  Цель — канонический хост (`parse_url($url, PHP_URL_HOST)`), не URL — чинит дыру 8 (тихий прямой
  запрос при рассинхроне `pick(target: $url)`/`targets(домен)`).
- **`ProxyPool.php` и `ProxyPoolTest.php` удалены целиком** (D19) — единственная точка выдачи
  теперь `ProxyResolver`.
- **`requestWithOutcome()`** (по одной копии в каждом из двух файлов — общий класс не заводился,
  диапазон call-site'ов не пересекается, `Files` item'а закрытый список): сетевой отказ
  (`ConnectionException`) мапится в `NetworkTimeout`/`TlsError`/`ConnectFailed` по тексту исключения
  и пробрасывается дальше без проглатывания; ответ цели — `Http403`/`Http429`/`ProxyAuthFailed`
  (407)/`EmptyBody`/успех (5xx — успех транспорта, research/02 §5) — уходит резолверу через
  `reportOutcome`.
- **`ListingBatch::proxyId`** берётся из `$lease->proxyId`; `CsvBlockIngestor` по-прежнему пишет
  его в `prc_fetches.proxy_id` без изменений своего кода (проверено `CsvBlockIngestorTest`).

**Known Deviations (статус 🟠, не 🟢):** item-коммит несёт дифф вне `Files` item'а — три
Feature-теста, которые интеграционно проходят через переведённые call-site'ы и стали падать без
правки, потому что резолвер fail-closed на пустом пуле (раньше `ProxyPool::pick` молча возвращал
`null`): `tests/Feature/Crawler/CardCollectDetailsTest.php`, `tests/Feature/Crawler/
DetailAnalysisProcessorTest.php`, `tests/Feature/Console/FetchDetailsTest.php` — каждому добавлен
только сид активного узла нужного скоупа, поведенческие ассерты не менялись. Плюс
`grep -rn "ProxyPool" app tests` не буквально пуст — один хит, `app/Dto/Procurement/Proxy/
ProxyLease.php:11`, историческая ссылка в докблоке (не код, файл вне `Files`). Плюс Code Guidance
про `Http::assertSent` по опциям `proxy` не выполнено буквально — Laravel `Http::fake()` не хранит
Guzzle-опции в `recorded()` (только `[Request, Response]`); позитивный кейс на каждый call-site
проверяет тот же факт («лиза дошла до запроса») через `$batch->proxyId === $proxy->id` /
`Http::assertNothingSent()` на пустом пуле. Подробности — `phases/P1.md` P1.6 Completion Notes.

Validation: `--filter=CsvSourceMethod` 8 passed/29 assertions, `--filter=DocumentDownloader`
6 passed/20 assertions (на `da6aed1`); `grep -rn "pick(target" app` — пусто; полный
`php artisan test` — 927 passed/2 failed (pre-existing `CoreKnowsNoDocumentTypeTest`, не предмет
P1.6, воспроизведено на родительском `d961495`)/1 skipped; pint `--dirty --test` чисто по диффу
item'а; `phpstan analyse` — `[OK] No errors`, baseline 69 записей до и после (не выросло).

`plan-lint` — счёт см. ниже (после этого коммита bookkeeping).

**Remaining:** 44 items из 50 (закрыто 6: 3 🟢 + 3 🟠). Фаза P1 — 3/8 🟢 (6 терминальных из 8).
Очередь фаз (D63): `P1 → P2 → P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.11.2, §3 Routing поitem'ный, §5 D1–D81, §4 Status Board
3/50 🟢) · `research/02-proxy-routing.md` (вход всех items P1) · `phases/P1.md`…`P7.md` ·
`roadmap.md` (очередь фаз и батчи B1/B2/B3) · `index.md` · `open-questions.md` (OQ-1 `Resolved →
D71`, OQ-2…OQ-5) · `findings/` (слой 1) · `brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:**

- **OQ-4 (браузерный канал) остаётся открытым** — решение владельца. Контракт лизы спроектирован
  так, что канал добавляется без переделки (дискретные `server`/`username`/`password` на
  `ProxyLease`), а сам канал фазой не вводится.
- **Инверсия семантики скоупа.** Пустой `scopes` = узел НЕ выдаётся; оператор обязан проставить
  скоупы при заведении узла, иначе пул пуст (fail-closed, сознательный выбор).
- **`use_proxy`/`proxy_country` профиля (`prc_crawlers`) резолвером P1.6 НЕ потребляются** —
  `resolve()` вызывается без `ProxyRequirements`, вопрос сужения по стране профиля/режима без
  прокси в Scope Included P1.6 не входил (только скоуп + каноническая цель + учёт исхода);
  подстановка не была найдена в тексте ТЗ P1.6, следующая точка, где это может понадобиться —
  калибровка политики (P4) либо отдельная находка ревизии. Не блокер P1.7 (форма только редактирует
  колонки, не меняет резолвер).
- **`required_country` per-скоуп политика конфига (`research/02` §8 пример) НЕ заведена** —
  `config/crawler.php` несёт только `forbidden_countries` на все четыре скоупа (`crawl_*`/
  `document_download` — пустой список, `llm` — `['RU']` по умолчанию); геотребование «RU для
  кроулинга» сегодня нигде не форсируется резолвером — то же ограничение, что P1.5 назвала в своих
  Known Deviations («не заводилась этим item'ом, не в предмете P1.5/P1.6»), актуально и после
  P1.6.
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
  домена `Llm` — PROMOTE-CANDIDATE, не предмет P1.
- **`app/Dto/Procurement/Proxy/ProxyLease.php`** несёт историческую ссылку на удалённый
  `ProxyPool::reportFailure()` в докблоке (не код) — косметика, не блокер, не предмет P1.6.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* нет.
- *Deferred:* перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  вторая БД и репликация — закрыто D71, возврат только по триггеру D72 · положения (3)–(5) целевой
  модели хранения → OQ-5 · переход транспорта ЕИС на XML/SOAP (D14) · переименование дисков в
  поколенческие имена — в момент cutover (D53) · судьба таблицы `cache` — находка ревизии P7.5
  (D75) · связь «выделенные узлы под конкретный профиль» (pivot `prc_crawler_proxy`) — названного
  требования нет (`research/02` §14.1, D80) · политика запретов/требований по стране для
  `crawl_*`/`document_download` скоупов (`required_country`) — не заводилась ни P1.5, ни P1.6, не
  в их предмете · починка `ProxyForm.php` (поле `targets`) — предмет P1.7.
- *Open questions:* OQ-1 **закрыт** (D71) · OQ-2 (судьба `PromptContextGenerator`, снимает P2.5) ·
  OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал — решение владельца; форма
  контракта под него готова, `research/02` §9.3) · OQ-5 (продуктовый контур — основание отдельного
  плана; статус сверяется в P7.8).
