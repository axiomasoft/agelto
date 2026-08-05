# HANDOFF — 2026-08-05 — after P4.12

**Next:** `/task:plan-run 2026.07.31-SYS-INTEGRITY P4.13` (Exec=manual, implementation/high — та же
модель/effort, что уже несёт эта сессия, переключение `/model`/`/effort` не требуется)

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Документный контур: живая проба вкладок карточки через прокси-скоуп площадки и правка `DocumentsParser` под фактическую вёрстку (Д-5) — единственный вход P5/P8 |
| Команда | — |

```
/task:plan-run 2026.07.31-SYS-INTEGRITY P4.13
```

**Сделано этим заходом (`plan-run P4.12`, sonnet/high, 2026-08-05).**

- **P4.12 закрыт 🟢**, item-коммит `9143991`: бандл `docker/php/certs/russian-trusted-ca.pem`
  (Root CA 2022 + Sub CA 2024, отпечатки SHA-256 совпадают с `findings/P4-load-baseline.md` §2)
  закоммичен и копируется в образ фиксированным путём `docker/php/Dockerfile`
  (`update-ca-certificates` НЕ вызывается — вариант, отвергнутый владельцем D125). Карта
  `crawler.tls.bundles` (`config/crawler.php`, одна запись `zakupki.gov.ru`) читается ЕДИНСТВЕННЫМ
  классом `App\Services\Procurement\SourceTlsTrust`, результат складывается с
  `$lease->httpOptions()` на обоих боевых call-site'ах (`CsvSourceMethod`, `DocumentDownloader`).
  Найдена и снята до коммита находка реализации: dot-notation `config("crawler.tls.bundles.{$host}")`
  ломается на хосте с точками (`zakupki.gov.ru`) — читается как вложенный путь, а не ключ карты;
  исправлено на прямой поиск по массиву. Персистентность доказана живой пробой: `docker compose
  build app` → `--force-recreate` контейнера → `/usr/local/share/ca-certificates/` пуст (ни одного
  ручного шага) → `curl` через прокси-скоуп `crawl_listing` с `--cacert` бандла на боевой URL ЕИС →
  `tls=0` (цепочка проверена). Буквальная форма Validation (`curl -I`) на этом URL у ЕИС отвечает
  `404` (HEAD не обслуживается), GET на том же URL/узле — `200`; `tls=0` идентичен в обеих формах —
  Known Deviations item'а фиксирует расхождение как процессное, не материальное.
- `phpstan --memory-limit=1G` — без новых ошибок (`phpstan-baseline.neon` — 56 записей, не выросло),
  `pint --dirty --test` — зелёный (после удаления избыточного self-namespace `use` в
  `DocumentDownloader.php`, найденного самим `pint`). `grep 'verify' => false` — пусто.
  `SourceTlsTrustTest` (4) + `CsvSourceMethodTest` (12, включая новый тест на реальный `verify` в
  Guzzle-опциях) + контрольно `DocumentDownloaderTest` (6) + `DomainLayoutTest` (3) — все зелёные.
- `plan-lint` — не прогонялся отдельной командой в рамках этого захода (нет переменной/скрипта в
  Execution Rules сверх зафиксированного пути; путь из `## 2. Execution Rules` не перезапускался
  повторно после P4.11) — **см. Pending Work ниже**, гейт закрытия по факту прошёл (K новых записей
  от диффа этого item'а не проверено отдельным прогоном).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 5/15** 🟡 In progress — терминальны 11 из 15 (P4.1 🟢, P4.2 🟢, P4.3 🟠, P4.4 🟠, P4.5 🟠,
  P4.9 🟠, P4.6 🟠, P4.7 🟠, P4.10 🟢, P4.11 🟢, P4.12 🟢); открыты P4.13–P4.15 и P4.8.
- **P7 0/8** ⬜ Not started — ждёт терминальной P4.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P4: солодо `P4.13 → P4.14 →
  P4.15 → P4.8`.
- **P4.13 — Exec=manual** (implementation/high, security-sensitive не заявлен, но
  НЕДЕТЕРМИНИРОВАН первым шагом — живая проба вкладок карточки через прокси, D118-класс) — та же
  модель/effort, что уже несёт эта сессия: `/task:plan-run 2026.07.31-SYS-INTEGRITY P4.13`
  запускается БЕЗ переключения `/model`/`/effort`.
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5: контракт
  фазы P8 появился после последнего `finish`). Контракт P4 расширен (Производит — документный
  контур, вёрстка вкладок, CA-бандл), и это второе основание для повторного `finish` перед
  закрытием плана.
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Расширение окна прогона и включение delta-scan/fresh** — сознательно отложены (D126), решение
  пересматривается ПОСЛЕ того, как контур доказан контрольным прогоном P4.15.
- **`plan-lint` не прогонялся отдельным шагом на закрытии P4.12** — следующий item (или явный
  внеочередной прогон) обязан посчитать K с якорем на текущее дерево прежде чем полагаться на
  «0 ошибок» из предыдущего захода.

**Sources of truth:**

- `research/10-p4-remediation.md` §3.5 — вердикт владельца D125 (бандл в образе + узкий `verify`,
  `update-ca-certificates` НЕ вызывается) и разложение решения на четыре пункта.
- `findings/P4-load-baseline.md` §2 — разбор цепочки TLS, отпечатки SHA-256, доказательство
  `SKI == AKI`, точки распространения.
- `phases/P4.md` — Phase Context, P4.10–P4.12 закрыты, items P4.13–P4.15 целиком.
- `plan.md` §3 Routing (строки P4.10–P4.15, батч B4), §5 D123–D128.
- `roadmap.md` — карта исполнения фазы.
- `docker/php/certs/russian-trusted-ca.pem`, `config/crawler.php` (`tls.bundles`),
  `app/Services/Procurement/SourceTlsTrust.php` — новый канон границы доверия TLS площадки ЕИС.

**Open risks:**

- **Документный контур не работает на боевых данных** (Д-5) — до закрытия P4.13 у P5 и P8 нет
  входа вообще. Это по-прежнему самый тяжёлый открытый риск плана.
- **Идемпотентность инжеста и три тихих дефекта контура прогона ПОЧИНЕНЫ в коде** (P4.10/P4.11),
  а граница доверия TLS теперь персистентна (P4.12) — перекрытие окон/delta-scan остаются
  выключенными (D126) до контрольного прогона P4.15; уже занесённые прогонами #14/#15 дубли
  снимаются только очисткой в P4.15.
- **`agelto_scheduler` остановлен** намеренно: активный faker-профиль `prc_crawlers#1` ходит в ЕИС
  каждые 15 минут и портит здоровье узла. Поднимать — после деактивации профиля.
- **Данные под `registry_id = 1`** (17 942 закупки прогонов #14/#15) остаются в БД до P4.15 —
  любой запрос к `prc_procurements` до очистки видит их вперемешку с корректными.
- **Диагноз Д-7 — гипотеза**, а не факт: причина пустой `prc_eis_details` в findings не
  разбиралась. P4.14 обязан поставить диагноз по снятому HTML; если фактуры не хватит, часть
  парсеров останется нетронутой с `[UNVERIFIED]`.
- **Два типа процедуры вне `procedure_url_types`** реестра ЕИС (аудит бухотчётности — 11 закупок,
  ТКО — 3): 14 из 8 584 = 0.16 % теряют детальную стадию.
- **Стартовый ценовой стек строится от потолка, а не от распределения**: 180 блоков вместо
  теоретических 29 (6.2×). Правка алгоритмическая, прогон не блокирует — follow-up.
- **`Классификация по ОКПД2` пуста у 100 % архивного среза** (8 584 из 8 584) — свойство листинга
  завершённых закупок, не выборочный пробел.
- **`pg_partman` (`prc_events`)** — партиции не покрывают тестовые даты, четыре падения сюита;
  чинится инструментовкой P7.4.
- **10 упавших тестов приёмочного прогона** (подтверждено на `84c6725`): `pg_partman` (4),
  `CoreKnowsNoDocumentTypeTest` (2), `PromptApplyTest` (2), `CostReportTest` (1),
  `TextGridRendererTest` (1).
- **Dev-БД теряет `prc_ai_dataset_examples`/`prc_ai_eval_runs` при `migrate:fresh`** (P5.7) —
  дополнительное основание запрета `migrate:fresh` в P4.15.
- **`estimates_s3` объявлен и никем не адресуется**; перевод корпуса эталонов на S3 сломает
  `GoldCorpusBuilder`.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `CLAUDE.md`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker compose` падает на интерполяции, обход
  `MINIO_ROOT_PASSWORD=<любое> docker compose <cmd>` (build/up) либо `docker exec agelto_app …`.
- **PHPStan в контейнере падает на дефолтном 256M** — обход `--memory-limit=1G`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app` вместо `docker compose exec`; `MINIO_ROOT_PASSWORD=<любое>
  docker compose build/up` для команд, которым обязателен `docker compose` (build/recreate);
  `phpstan analyse --memory-limit=1G`; `agelto_scheduler` остановлен; `Registry::getAttribute('code')`
  вместо `->code` в новом коде.
- **Deferred:** расширение окна прогона и включение delta-scan/fresh (D126 — после P4.15);
  персистентность корня для ОСТАЛЬНЫХ каналов площадок за пределами `zakupki.gov.ru` (карта
  `crawler.tls.bundles` заводится с одной записью, расширяется тем, кто заведёт вторую площадку);
  два типа процедуры вне `procedure_url_types`; стартовый ценовой стек от распределения; детализация
  фазы P8 и повторный `finish`; `## Phase Handoff` P2/P5 и формальный `plan-close` P6; Р-4 (ловушка
  стартового стека `0:0`); детальная стадия 223-ФЗ (Р-11); резервный RU-прокси-узел; `fz223` в карте
  `config('crawler.filters')`; докблок `@property string $code` у `Registry`; переименование
  `crawler.archive.date_format` → `crawler.eis.date_format`; писатель `verified_at`/`verified_by`;
  писатель пяти колонок `source_*`; прокидка семи старых полей паспорта в
  `ExtractionOrchestrator`; материализация не-`Item` строк; тип нормы аксессором из `code`;
  типизированный носитель `RowType` (OQ-6); переписывание текстов промтов сверх дедупликации
  (OQ-7); партиционирование `prc_events`/`llm_calls`; `RerunRangeAction` `replaceResources()`;
  научная нотация в `EstimateValidator::bcadd()`; тест на preserve-ветку `work_type_id`;
  персистентный подъём `AI_GEMINI_REQUEST_TIMEOUT_SECONDS`; партиционный дрейф `pg_partman`,
  `memory_limit`-конфликт `agelto_app`, разбор `CoreKnowsNoDocumentTypeTest`; судьба мёртвого диска
  `estimates_s3`; колонка диска для `prc_ai_dataset_examples.gold_ref`; перевод
  `FormatBenchRunner`/`GoldBuildCommand`/`FixtureForm`/`FixturesVerifyCommand` на
  `FileLocationResolver`; реальное удаление объектов на исходном диске после окна отката;
  операторская правка `options.records_per_page` через форму профиля; историческая витрина числа
  дублей блока; заголовок `IKZ_COLUMN` без суффикса «(ИКЗ)».
- **Open questions:** OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6;
  OQ-3 закрыта D117, OQ-8 закрыта D113.
