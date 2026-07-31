# HANDOFF — 2026-07-31 — after P1.7

**Next:** P1.8 — Документация домена и приёмочный прогон фазы

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | low |
| Context | continue (/clear) — ручной item |
| Суть | Синхронизировать доки/канон под новую модель маршрутизации прокси, прогнать фазу целиком (полный сюит, pint, phpstan), закрыть фазу P1 |

```
/task:plan-exec 2026.07.31-SYS-INTEGRITY P1.8
```

**Done:** **P1.6 закрыт 🟠, P1.7 закрыт 🟢** (батч B1, единственная пара items P1 без пересекающихся
входов) — перевод HTTP-каналов кроулинга на скоупы резолвера и Filament-поверхность прокси/
привязки профиля:

- **P1.6** (item-коммит `da6aed1`) — три call-site'а (`CsvSourceMethod::collectListing` →
  `ProxyScope::CrawlListing`, `CsvSourceMethod::collectTab` → `CrawlDetail`,
  `DocumentDownloader::fetch` → `DocumentDownload`) переведены с `ProxyPool::pick(target: $url)`
  (полный URL, дыра 8) на `ProxyResolver::resolve(scope, canonicalHost)`; `ProxyPool.php`/
  `ProxyPoolTest.php` удалены; каждый HTTP-вызов сообщает исход резолверу через `reportOutcome`
  (сеть/403/429/407/пустое тело/успех, 5xx — успех транспорта, research/02 §5).
  **🟠 (deviations):** дифф вне `Files` — 3 интеграционных Feature-теста (`CardCollectDetailsTest`,
  `DetailAnalysisProcessorTest`, `FetchDetailsTest`), которые молча ловили пустой пул через старый
  `ProxyPool::pick` → `null` и теперь получили сид активного узла (резолвер fail-closed); плюс один
  исторический докблок-хит `grep "ProxyPool"` (`ProxyLease.php:11`, не код); плюс отказ от буквального
  `Http::assertSent` по опции `proxy` (Laravel `Http::fake()` не хранит Guzzle-опции) в пользу
  проверки `$batch->proxyId`/`Http::assertNothingSent()`.
- **P1.7** (item-коммит `75cd673`) — `ProxyForm`: `Select` по стране (закрытый список
  `ProxyForm::COUNTRIES`, публичная константа) и скоупам (`ProxyScope::class`, `multiple()`) вместо
  `KeyValue::make('targets')`; здоровье — `Fieldset` из пяти read-only полей
  (`disabled()->dehydrated(false)`, правка не долетает до записи). `ProxiesTable` — колонки
  `country`/`scopes`/`open_until` (выведенный индикатор breaker'а). `CrawlerForm` —
  `use_proxy`/`proxy_country` (переиспользует `ProxyForm::COUNTRIES`). Три обязательных
  Feature-кейса в `tests/Feature/Filament/ProxyResourceTest.php`. **🟢 без отклонений** — дифф
  ровно по путям `Files`.

Validation P1.6: `--filter=CsvSourceMethod` 8 passed/29 assertions, `--filter=DocumentDownloader`
6 passed/20 assertions. Validation P1.7: `--filter=ProxyResource` 3 passed/15 assertions,
`--filter=Filament` 62 passed/331 assertions. Оба item'а: pint `--dirty --test` чисто (кроме чужого
`config/cms.php`), `phpstan analyse` `[OK] No errors`, baseline 69 записей без изменений. Полный
`docker exec agelto_app php artisan test` (на `75cd673`) — 930 passed/2 failed/1 skipped: оба
падения — `CoreKnowsNoDocumentTypeTest`, pre-existing baseline-дефект (`WorkingCopyProvisioner.php:
Estimate`), воспроизведён на родительском `d961495` до диффа обоих items, не их предмет.

`plan-lint` — счёт см. ниже (после этого коммита bookkeeping).

**Remaining:** 43 items из 50 (закрыто 7: 4 🟢 + 3 🟠). Фаза P1 — 4/8 🟢 (7 терминальных из 8,
остался только P1.8). Очередь фаз (D63): `P1 → P2 → P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.11.2, §3 Routing поitem'ный, §5 D1–D81, §4 Status Board
4/50 🟢) · `research/02-proxy-routing.md` (вход всех items P1) · `phases/P1.md`…`P7.md` ·
`roadmap.md` (очередь фаз и батчи B1/B2/B3) · `index.md` · `open-questions.md` (OQ-1 `Resolved →
D71`, OQ-2…OQ-5) · `findings/` (слой 1) · `brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:**

- **OQ-4 (браузерный канал) остаётся открытым** — решение владельца. Контракт лизы спроектирован
  так, что канал добавляется без переделки, а сам канал фазой не вводится.
- **Инверсия семантики скоупа.** Пустой `scopes` = узел НЕ выдаётся; оператор обязан проставить
  скоупы при заведении узла, иначе пул пуст (fail-closed, сознательный выбор) — теперь видимо и
  редактируемо в Filament (P1.7).
- **`use_proxy`/`proxy_country` профиля (`prc_crawlers`) резолвером P1.6 НЕ потребляются** —
  `resolve()` вызывается без `ProxyRequirements`; поля видны и редактируются в Filament (P1.7), но
  ни один из трёх call-site'ов их не читает. Не блокер P1.8 (документация фиксирует состояние
  как есть), но кандидат в Pending Work следующей фазы, где эти call-site'ы калибруются (P4).
- **`required_country` per-скоуп политика конфига (`research/02` §8 пример) НЕ заведена** —
  `config/crawler.php` несёт только `forbidden_countries`; геотребование «RU для кроулинга» сегодня
  нигде не форсируется резолвером — то же ограничение, что P1.5/P1.6 назвали в Known Deviations,
  актуально и после P1.7.
- **`ProxyForm::COUNTRIES`** — закрытый список из 10 кодов (не «конфиг/справочник» буквально, как
  просило ТЗ P1.7, а публичная константа класса) — тот же инвариант (не свободный текст), но при
  росте числа стран-юрисдикций провайдеров список придётся расширять руками в коде, не через
  админку/конфиг. PROMOTE-CANDIDATE, если это станет частым.
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
  Формулировки без слова-маркера уже стоят в Phase Context P1–P4 (см. предыдущие handoff).
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
  `ProxyPool::reportFailure()` в докблоке (не код) — косметика, не блокер, не предмет P1.6/P1.7.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* нет.
- *Deferred:* перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  вторая БД и репликация — закрыто D71, возврат только по триггеру D72 · положения (3)–(5) целевой
  модели хранения → OQ-5 · переход транспорта ЕИС на XML/SOAP (D14) · переименование дисков в
  поколенческие имена — в момент cutover (D53) · судьба таблицы `cache` — находка ревизии P7.5
  (D75) · связь «выделенные узлы под конкретный профиль» (pivot `prc_crawler_proxy`) — названного
  требования нет (`research/02` §14.1, D80) · политика запретов/требований по стране для
  `crawl_*`/`document_download` скоупов (`required_country`) — не заводилась ни P1.5, ни P1.6, ни
  P1.7, не в их предмете · подстановка `use_proxy`/`proxy_country` профиля в резолвер call-site'ов
  — не в предмете P1.6, кандидат P4.
- *Open questions:* OQ-1 **закрыт** (D71) · OQ-2 (судьба `PromptContextGenerator`, снимает P2.5) ·
  OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал — решение владельца; форма
  контракта под него готова, `research/02` §9.3) · OQ-5 (продуктовый контур — основание отдельного
  плана; статус сверяется в P7.8).
