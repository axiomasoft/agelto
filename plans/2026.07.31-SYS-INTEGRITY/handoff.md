# HANDOFF — 2026-07-31 — after P1.3

**Next:** P1.4 — резолвер: политика выбора, двухуровневое здоровье, circuit breaker

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Context | continue (/clear) — ручной item |
| Суть | P1.4 — `ProxyResolver` (фильтр скоуп/страна/breaker → score → weighted random top-K), двухуровневое здоровье, автомат circuit breaker по таксономии причин (D5–D7) |

```
/task:plan-run 2026.07.31-SYS-INTEGRITY P1.4
```

**Done:** **P1.3 закрыт** (🟢, item-коммит `c2b2369`) — привязка профиля обхода к прокси и
перечитывание настроек на итерации:

- **Миграция `2026_07_31_100063_add_proxy_routing_to_prc_crawlers_table`:** РОВНО две колонки на
  `prc_crawlers` — `use_proxy` (boolean, default `true`), `proxy_country` (`char(2)`, nullable);
  колонки скоупа на профиле нет (D80); `down()` обратим.
- **Модель `Crawler`:** `use_proxy`/`proxy_country` в `$fillable`; `use_proxy` кастуется в
  `boolean`.
- **`CrawlerFactory`:** дефолты (`use_proxy: true`, `proxy_country: null`) + states
  `withoutProxy()`/`proxyCountry(string)`.
- **Перечитывание настроек на итерации (D81):** `$crawler->refresh()` первой строкой тела `for`
  (`FreshRunProcessor.php`, до `makeWithFallback()`) и `while` (`ArchiveWindowScanner.php`, до
  выбора источника); `DetailAnalysisProcessor.php` НЕ тронут — у стадии детального анализа
  профиля не существует (D81).
- **Тест `tests/Feature/Crawler/CrawlerProxySettingsTest.php`:** двойник `SourceMethod`,
  фиксирующий `use_proxy`/`proxy_country` профиля на момент каждого вызова `collectListing()`;
  между 1-й и 2-й итерацией тест обновляет строку `prc_crawlers` напрямую, 2-я итерация видит
  новые значения — по одному сценарию на оба процессора (2 теста, 2 assertions).

Validation: `migrate` / `migrate:rollback --step=1` / повторный `migrate` — все прошли (`docker
exec agelto_app`, тот же обходной путь, что в P1.2 — `docker compose` на хосте не поднимается);
`--filter=CrawlerProxySettings` зелёный (2 passed); `--filter=FreshRunProcessor` зелёный (7
passed, включая новый тест); `--filter=ArchiveWindow` зелёный (5 passed, включая новый тест);
`pint --test` по шести файлам `Files` — чисто; `phpstan analyse` (`docker exec agelto_app`) —
`[OK] No errors`, baseline не вырос; `git diff --name-only` item-коммита не содержит
`DetailAnalysisProcessor.php` (D81).

`plan-lint` — счёт см. ниже (после этого коммита bookkeeping).

**Известное для следующих items (не блокер P1.4, факт видимости):**

- `ProxyPool::pick(target: …)` вызывает `whereJsonContains('targets', …)` на удалённой колонке —
  упадёт SQL-ошибкой при непустом `target` до перевода call-site'ов (P1.6, D19); живого трафика в
  этом окне нет (D36).
- `app/Filament/Resources/Procurement/Crawler/Proxies/Schemas/ProxyForm.php` ссылается на снятое
  поле `targets` — Filament вне Scope, чинится в P1.7.

**Remaining:** 47 items из 50 (закрыто 3). Фаза P1 — 3/8. Очередь фаз (D63):
`P1 → P2 → P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.11.2, §3 Routing поitem'ный, §5 D1–D81, §4 Status Board
3/50) · `research/02-proxy-routing.md` (вход всех items P1) · `phases/P1.md`…`P7.md` ·
`roadmap.md` (очередь фаз и батчи B1/B2/B3) · `index.md` · `open-questions.md` (OQ-1 `Resolved →
D71`, OQ-2…OQ-5) · `findings/` (слой 1) · `brief/` (слой 0) · `journal.jsonl` (D21).

**Open risks:**

- **OQ-4 (браузерный канал) остаётся открытым** — решение владельца. Контракт лизы спроектирован
  так, что канал добавляется без переделки, но сам канал фазой не вводится.
- **Состав `ProxyScope` заморожен миграцией.** Правка после P1.2 = новая миграция на схеме,
  которую P3.2 к тому моменту перенесёт (D4, D18, D30).
- **Инверсия семантики скоупа.** Пустой `scopes` = узел НЕ выдаётся; оператор обязан проставить
  скоупы при заведении узла, иначе пул пуст (fail-closed, сознательный выбор).
- **`ProxyPool::pick(target:)` небезопасен до P1.6** — см. «Известное для следующих items» выше;
  живого трафика нет (D36), но P1.4/P1.5 НЕ должны звать `pick()` с непустым `target`.
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
  сессии) — исполнитель P1.4 обязан использовать `docker exec agelto_app` вместо `docker compose
  exec app` буквально командой Validation, либо починить `.env` (чужой диф — не трогать без
  директивы владельца).
- **Протокольная дыра (PROMOTE-CANDIDATE, не правится этим планом):** свип после отмены D-записи
  идёт по списку адресов, который пишет человек, и неполон там, где отменённое решение цитируется
  пересказом, а не номером.

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
