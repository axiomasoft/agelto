# HANDOFF — 2026-08-05 — after P4.10

**Next:** exec-item: task:plan-exec 2026.07.31-SYS-INTEGRITY P4.11

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | same-session — item |
| Суть | Батч B4, второй item: три тихих дефекта контура прогона (Д-2 сид резолвит реестр без площадки, Д-3 латентность узла не пишется, Д-4 креды прокси не URL-кодируются). Оффлайн, предписан диагнозами, гейт детерминированный — «красное без фикса» на каждый из трёх через `git stash` |
| Команда | `/task:plan-exec 2026.07.31-SYS-INTEGRITY P4.11` |

**Сделано этим заходом (`plan-exec P4.10`, sonnet/medium, 2026-08-05).**

- **P4.10 закрыт 🟢**, item-коммит `cd4248b`: модификатор `!` добавлен на все три формата
  `EisCsvRowMapper::parseDate()` и на `EventJournalParser::DATE_FORMAT` — снята подстановка
  текущего системного времени в разобранные даты источника, корень недетерминированности
  `snapshot_hash` и отказа guard'а идемпотентности инжеста.
- Новый тест `RevisionTest`: повторный инжест ИДЕНТИЧНОЙ строки CSV через реальный
  `EisCsvRowMapper::map()` (не `SnapshotFactory` — он строит `Carbon` напрямую, минуя `parseDate`)
  с паузой 1.1 с между вызовами — `revision_count`/`prc_revisions`/`prc_events` не растут на
  второй вызов. Датасет-тест на три формы формата в `EisCsvRowMapperTest`.
- Проба «красное без фикса» (`git stash push` двух файлов фикса → `--filter=RevisionTest`)
  подтверждена: `1 failed, 2 passed`, ненулевой exit code, `git stash pop` восстановил фикс.
- **Находка вне ТЗ (к сведению, не отклонение):** для формата `EventJournalParser` (`d.m.Y H:i`)
  PHP/Carbon и без модификатора `!` уже отдаёт секунды `00` (эмпирически проверено через tinker) —
  заявленная в `research/10-p4-remediation.md` §3.1 недетерминированность секунд для ЭТОГО
  конкретного формата не подтвердилась; модификатор добавлен по ТЗ и корректен, реальный
  недетерминизм есть только у чистого `d.m.Y` в `EisCsvRowMapper` (что и ловит `RevisionTest`).
  Деталь — `phases/P4.md` P4.10 Completion Notes.
- Validation: `EisCsvRowMapperTest` — `15 passed`; `EventJournalParserTest` — `3 passed`;
  `RevisionTest` — `3 passed`; `phpstan --memory-limit=1G` — `[OK] No errors`, baseline `56`
  записей без роста; `pint --dirty --test` — зелёный.
- Bookkeeping-коммит следом (`plans/2026.07.31-SYS-INTEGRITY`, `plan.md` Status Board `3/15`,
  Update Log, `phases/P4.md` Phase Status + item Status → 🟢).

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 3/15** 🟡 In progress — терминальны 9 из 15 (P4.1 🟢, P4.2 🟢, P4.3 🟠, P4.4 🟠, P4.5 🟠,
  P4.9 🟠, P4.6 🟠, P4.7 🟠, P4.10 🟢); открыты P4.11–P4.15 и P4.8.
- **P7 0/8** ⬜ Not started — ждёт терминальной P4.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Идёт P4: остаток батча B4 (P4.11),
  затем solo P4.12 → P4.13 → P4.14 → P4.15 → P4.8.
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5: контракт
  фазы P8 появился после последнего `finish`). Контракт P4 расширен (Производит — документный
  контур, вёрстка вкладок, CA-бандл), и это второе основание для повторного `finish` перед
  закрытием плана.
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Расширение окна прогона и включение delta-scan/fresh** — сознательно отложены (D126), решение
  пересматривается ПОСЛЕ того, как контур доказан контрольным прогоном P4.15.

**Sources of truth:**

- `research/10-p4-remediation.md` — вердикт по каждому из семи дефектов с кодовой фактурой,
  раскрой на items и критерий раскроя, обязательные числа контрольного прогона и их потребители.
- `phases/P4.md` — Phase Context (очередь, обоснование «ремедиация внутрь фазы»), P4.10 закрыт,
  items P4.11–P4.15 целиком.
- `plan.md` §3 Routing (строки P4.10–P4.15, батч B4), §5 D123–D128.
- `findings/P4-load-baseline.md` §5 (семь дефектов с точными строками и снятой фактурой), §7
  (калибровка), §8 (три гейта — все закрыты).
- `roadmap.md` — карта исполнения фазы и launch-block батча B4.
- `brief/01-refinements.md` блок «2026-08-05 (второе за день)» — четыре решения владельца с
  трассировкой «мысль → куда ушла».

**Open risks:**

- **Документный контур не работает на боевых данных** (Д-5) — до закрытия P4.13 у P5 и P8 нет
  входа вообще. Это по-прежнему самый тяжёлый открытый риск плана.
- **Идемпотентность инжеста ПОЧИНЕНА в коде (P4.10)**, но перекрытие окон/delta-scan остаются
  выключенными (D126) до контрольного прогона P4.15 — уже занесённые прогонами #14/#15 дубли
  снимаются только очисткой в P4.15, не этим item'ом.
- **Три тихих дефекта контура прогона (Д-2/Д-3/Д-4) ещё не починены** — вход P4.11: сид
  привязывает профили ЕИС к чужому реестру `fz44` без площадки, латентность узла никогда не
  пишется, креды прокси не URL-кодируются (падение на «неудобном» пароле).
- **Корень Минцифры внесён непepсистентно** — в writable-слое `agelto_app`/`agelto_horizon`,
  исчезнет при пересоздании контейнера или ребилде; после этого боевой клиент не подключится к ЕИС
  (`tls=20`). Закрывается P4.12; до него любой сетевой шаг фазы зависит от ручной правки. Отпечаток
  корня SHA-256
  `D2:6D:2D:02:31:B7:C3:9F:92:CC:73:85:12:BA:54:10:35:19:E4:40:5D:68:B5:BD:70:3E:97:88:CA:8E:CF:31`.
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
  состав снимать `git status --porcelain` перед каждым item-коммитом. Особая осторожность с
  `database/seeders/User/`: P4.11 пишет в `database/seeders/Procurement/`, коммитятся только
  поимённые пути из `Files`.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker compose` падает на интерполяции, обход
  `docker exec agelto_app …`.
- **PHPStan в контейнере падает на дефолтном 256M** — обход `--memory-limit=1G`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app` вместо `docker compose exec`; `phpstan analyse
  --memory-limit=1G`; корень Минцифры + Sub CA 2024 в доверенном хранилище контейнеров
  (непepсистентно — снимается P4.12); `agelto_scheduler` остановлен; `update prc_crawlers set
  registry_id = 3` вместо фикса сида (снимается P4.11 — в код НЕ переносится);
  `Registry::getAttribute('code')` вместо `->code` в новом коде.
- **Deferred:** расширение окна прогона и включение delta-scan/fresh (D126 — после P4.15);
  персистентность корня для ОСТАЛЬНЫХ каналов (P4.12 закрывает только площадку); два типа процедуры
  вне `procedure_url_types`; стартовый ценовой стек от распределения; детализация фазы P8 и
  повторный `finish`; `## Phase Handoff` P2/P5 и формальный `plan-close` P6; Р-4 (ловушка стартового
  стека `0:0`); детальная стадия 223-ФЗ (Р-11); резервный RU-прокси-узел; `fz223` в карте
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
