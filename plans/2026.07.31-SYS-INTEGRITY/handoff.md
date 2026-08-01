# HANDOFF — 2026-08-01 — after P2.5

**Next:** исполнить P2.6 (тексты промтов — поведенческие правила вместо описания структуры)

| Параметр | Значение |
|:--|:--|
| Model | Opus |
| Thinking | high — авторский текст поведенческих правил боевых промтов, качество проверяется только замером (Routing §3) |
| Context | continue (/clear) — ручной item |
| Суть | Переписать 10 файлов промтов в обоих канонических корнях под целевой скелет `research/03` §7 (только поведенческие правила, структуру несёт схема), `ai:prompts:sync`, один eval-замер против кандидата P2.5 |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P2.6
```

**P2.5 закрыт 🟠 2026-08-01** (item-коммит `eee4f64` + bookkeeping): печать PHP-исходника
контрактов снята из системной части (`PromptContextGenerator::generate()` — единственное тело,
вариант C OQ-2, D101/D103), временная ветка замера `env('P25_OQ2_VARIANT')` (A/B) удалена целиком,
осиротевший golden-снапшот `screening-result.prompt.txt` удалён.

**Два инфраструктурных препятствия сняты по ходу исполнения (вне Scope, не часть диффа item'а):**

1. **Локальный MTU докер-сети** — VPN хоста (`tailscale0`, MTU 1280) против дефолтных 1500 у
   докеровской bridge-сети `agelto_network` давал PMTU-blackhole на крупных запросах к Gemini
   (мелкие проходили — прокси-хендшейк работал, `generateContent` висел 150с). Фикс —
   некоммитящийся `docker-compose.override.yml` (`.gitignore`, шаблон —
   `docker-compose.override.example.yml`): `com.docker.network.driver.mtu: "1200"` на сети
   `agelto_network`, стек пересоздан (`down && up -d`). Хостовая инфраструктура, воспроизводима
   на любой машине с похожим VPN-паттерном.
2. **`AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (150с, `config/ai.php`) недостаточен под бюджет P2.9**
   (`max_tokens=65536`/`thinking_level=LOW`) — часть эталонов не укладывалась в 150с. Оба замера
   ниже сняты с временным `-e AI_GEMINI_REQUEST_TIMEOUT_SECONDS=300` (только для вызова, не
   персистентно, вне `Files` item'а) — **PROMOTE-CANDIDATE на постоянный bump**, иначе боевой
   контур будет систематически терять документы на таймауте именно на тех маршрутах, где P2.9
   расширил бюджет.

**M0₃ = EvalRun #11** (дерево P2.4 + сид, дифф P2.5 застэшен поимённо на момент снятия) — **4/6**
эталонов (упали `#7` — Prism `unhandled finish reason`, PROMOTE-CANDIDATE, вне Scope; `#10` —
`Unable to cast value to a decimal.`, вероятностный, вне Scope), f1=0.1774, row_coverage=0.5822,
54 вызова. **M3c = EvalRun #12** (дерево P2.5 после `git stash pop`, сверено `diff -r` с бэкапом) —
**6/6** (оба прежних отказа не воспроизвелись — вероятностны, не регрессия P2.5), f1=0.2373,
row_coverage=0.7634, 46 вызовов. `estimate:eval:compare --base=11 --candidate=12
--criterion=no-regression` → `reject` (единственная причина — `EST-XLSX-VOR-001` −0.2393 f1,
меньше шума D100 0.2886); все четыре диагностических признака §8.11 — «нет» (покрытие выросло,
новый класс отказа не появился, вызовы не выросли, обе подвыборки положительны). Код НЕ
откатывается — тот же случай, что M2 (§8.8). Полный разбор — `research/03-prompt-contract.md`
§8.12; тексты/Known Deviations — `phases/P2.md` P2.5.

Тесты зелёные (23, 128 assertions), `pint --dirty --test`/`phpstan`/`plan-lint` чисты по путям
`Files`. OQ-2 — `Resolved → D103`, без изменений.

**Сделано этим заходом:**

- `app/Services/Llm/Schema/PromptContextGenerator.php` — TEMP-ветка замера удалена, вариант C —
  единственное тело `generate()`.
- `tests/Unit/Llm/PromptContextGeneratorTest.php`, `tests/Feature/Llm/PromptComposerTest.php`,
  `tests/Feature/Ai/PromptCompositionTest.php` — под новую форму (без изменений с design-захода,
  уже были зелёные).
- `tests/Fixtures/Llm/screening-result.prompt.txt` — удалён.
- `research/03-prompt-contract.md` — **§8.12** (числа M0₃/M3c, вердикт дословно, чтение по
  правилу §8.11, инфраструктурные препятствия).
- `phases/P2.md` — P2.5 → `🟠 Done with deviations`, Completion Notes/Pending
  Work/Known Deviations, Phase Status.
- `plan.md` — Status Board (без изменений в числителе — P2.5 не 🟢), примечание под доской,
  Update Log.
- `journal.jsonl` — старт+финал записи.
- Локально (не в плане): `docker-compose.override.yml` (MTU-фикс, gitignored).

**Done:** **P2.1 🟢** (`ced2a4f`) · **P2.8 🟠** (`b51ce3a`+`b05235f`) · **P2.2 🟢**
(`19f9808`+`684ddfb`) · **P2.3 🟠** (`bcd7ad4`) · **P2.9 🟠** (`b667b3e`) · **P2.10 🟢** (`ed202ac`) ·
**P2.4 🟠** (`4a4ce75`) · **P2.5 🟠** (`eee4f64`+bookkeeping).

**Remaining:** 37 items из 55. Внутри фазы: P2.6 → P2.7. Дальше по очереди D63:
`P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.17.0; §3 Routing поitem'ный, строка P2.6 —
frontier/high/manual/full; §5 D1–D103; §4 Status Board — P2 3/10 🟢 (числитель считает только
`🟢`), фаза 🟡) · `phases/P2.md` (ТЗ items) · **`research/03-prompt-contract.md`** §7 (целевой
скелет системной инструкции для P2.6), §8.12 (методика/числа P2.5), §8.9–§8.11 (провенанс) ·
`findings/P2-eval-baseline.md` §7 (шум D100) · `findings/P2-vendor-structured-output.md` §5, §12
(пороги кэша, ставки) · `roadmap.md` · `index.md` · `open-questions.md` · `journal.jsonl`.

**Open risks:**

- **PROMOTE-CANDIDATE: `AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (150с) недостаточен под бюджет P2.9**
  (`max_tokens=65536`) — систематическая потеря документов на таймауте на extraction-маршрутах
  без постоянного bump'а; наблюдалось на M0₃/M3c P2.5 (детали `phases/P2.md` P2.5 Pending Work).
- **PROMOTE-CANDIDATE: `vendor/prism-php` `Structured::handle()` не отображает
  `ContentFilter`/`Other`** finish reason (`Providers/Gemini/Handlers/Structured.php:71`) — бросает
  `PrismException('Gemini: unhandled finish reason')`; наблюдалось один раз на M0₃, вероятностно,
  вне нашего кода.
- **Разово наблюдавшийся `Unable to cast value to a decimal.`** на `dataset_example #10` (M0₃,
  не воспроизвёлся на M3c) — вероятностный дефект парсинга ответа вендора, требует отдельного
  расследования.
- **Локальный MTU-фикс хоста** (`docker-compose.override.yml`, gitignored) — если у других
  участников тот же VPN-паттерн (MTU туннеля ниже 1500), им нужен тот же приём; не
  задокументирован в раннбуке разработчика (вне Scope P2.5).
- **Повторный замер префикса после P2.6** — условие пересмотра D101: если новые тексты правил сами
  поднимут системную часть выше 4096, implicit-кэш возвращается без работы.
- **implicit-кэш системной части на `extraction:passport`/`extraction:rows` выключен сознательно**
  (D101).
- **Чисел по вариантам A и B у фазы не будет никогда** (D103 Consequences).
- **EvalRun #7 незавершён** (`finished_at = NULL`, 2/6) — в сравнение не подаётся, не чистится.
- **Известный флейк `bcsub()` на `EST-XLSX-KS2-001`** (P2.10 Pending Work) — доминирует в шуме D100.
- **Потолки вывода `gemini-3.1-flash-lite` и `gemini-3.1-pro-preview` `[UNVERIFIED]`** (D99).
- **Отдельная величина «дельта P2.3» утрачена навсегда** (D100 Consequences).
- **`estimate:gold:import --prepare` не переводит документ в `prepared`** (P2.8 Known Deviations).
- **Дешёвый size-gate слеп на легаси `.xls`** (P2.8 Scope Excluded).
- **DB частично гидрирована, НЕ откатана**: `prc_ai_fixtures`=24, `prc_ai_dataset_examples`=17,
  `prc_ai_eval_runs`=12. **Не запускать `migrate:fresh` до закрытия фазы P2.**
- **`EST-PDF-SCAN-001` (id 7 в манифесте фикстур)** в шестёрку обязательных gold НЕ входит.
- **Представления пересоздаются на ТЕКУЩЕМ образе** — сопоставимо внутри P2, не с числами донора.
- **Деньги.** M0₃+M3c этого захода — два прогона (54+46=100 вызовов, вкл. неудачные попытки на
  MTU/таймауте до фикса — ещё ~2 частичных прогона, EvalRun #8/#9/#10 сожжены не полностью).
- **`rules_hash` инвалидирован P2.4 и P2.5** (второй раз в фазе, D89/этот заход) — оба раза разово.
- **Канал проекта помечен вендором Legacy** — вход EOL-мониторинга, P2.7 фиксирует строкой риска.
- **Строка Update Log для `plan-audit P1` (заход 2) отсутствует** — вне скоупа.
- **A1 аудита P1 (Major) не закрыт**: потребление `ProxyRequirements->country` не покрыто
  дискриминирующим тестом — решение владельца.
- **Partition-drift — падения полного сюита вне предмета плана** (`llm_calls` — нет партиции под
  текущую дату; 4 теста в прогонах прошлого захода) — фикс-предложение `phases/P1.md` P1.10 Pending
  Work. Два красных arch-теста `CoreKnowsNoDocumentTypeTest` исключены поимённо (D86).
- **`verify-citations.py` — находки вне диффа**; инструмента в `plans/` этого репозитория нет (D76).
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри
  `Llm` — PROMOTE-CANDIDATE, не предмет фазы.
- **`(array) $provider->options` в `LlmRouteResolver::toResolvedRoute()` несёт ТОТ ЖЕ класс
  дефекта**, что был исправлен для `$route->options` в P2.9 — дормантно, PROMOTE-CANDIDATE.
- **`docker compose` на хосте**: `MINIO_ROOT_PASSWORD`-интерполяция падает — обходка
  `docker exec agelto_app` рабочая; теперь требует ТАКЖЕ `--env-file .env --env-file .env.docker`
  при вызове `docker compose` напрямую (см. MTU-фикс выше) — `docker exec` для повседневных команд
  по-прежнему достаточен.
- **Незапланированные файлы в рабочем дереве**: `M .env.example`, untracked `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife/`,
  `plans/2026.07.31-SYS-INTEGRITY/.claude/`, `plans/2026.07.31-SYS-INTEGRITY/.swissknife/` — чужой
  дифф из `plan.md` §2, не трогать. Плюс `storage/app/p25-measure.php` (одноразовый скрипт замера,
  gitignored) и `storage/app/p25-backup/` (бэкап диффа шага 1, можно удалить — item закрыт, стэш
  вернулся и сверен) и `docker-compose.override.yml` (MTU-фикс, gitignored, оставлен — нужен для
  работы сети). **`git stash` без путей запрещён — утащит чужой дифф.**
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — сверяется перед стартом P5.
  **P4.7 — точка невозврата по схеме**: P5 и P6 обязаны быть терминальны до него.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* `docker exec agelto_app` вместо `docker compose exec app` в неинтерактивных
  оболочках агента (`docker compose` командам down/up — нужны `--env-file .env --env-file
  .env.docker`); одноразовый `storage/app/p25-measure.php` (метода `countTokens` в контракте
  `LlmProvider` нет) · временный `-e AI_GEMINI_REQUEST_TIMEOUT_SECONDS=300` для платных прогонов
  до постоянного bump'а (PROMOTE-CANDIDATE) · локальный `docker-compose.override.yml` для MTU
  VPN-хоста.
- *Deferred:* переименование `PromptContextGenerator` под новую роль (развязка с `rulesHash()`) ·
  чистка/переиспользование незавершённого EvalRun #7 · повторный замер префикса после P2.6
  (условие пересмотра D101) · строка Update Log для `plan-audit P1` захода 2 · A1/A2/A3/A6 аудита
  P1 · правка ТЗ P1.2/P1.3 (D20) · перенос слоёв класса B (D28) · сплошное покрытие тестами команд
  и job'ов (D59) · положения (3)–(5) целевой модели хранения (D72) · переход транспорта ЕИС на
  XML/SOAP (D14) · переименование дисков (D53) · судьба таблицы кэша (D75) · связь «выделенные узлы
  под конкретный профиль» (D80) · `ProxyForm::COUNTRIES` (D86) · починка двух pre-existing
  `CoreKnowsNoDocumentTypeTest` (D86) · разовый флейк `CsvBlockIngestorTest` · partition-drift
  (P1.10 Pending Work) · миграция на Interactions API вендора (мониторинг) · починка слепого
  size-gate на легаси `.xls` (P2.8 Scope Excluded) · статус `EST-PDF-SCAN-001` · решение о диспетче
  `PrepareDocumentJob` из `estimate:gold:import --prepare` (P2.8 Pending Work) · деградация оконного
  пути при MAX_TOKENS (P2.9 Scope Excluded) · потолки вывода `gemini-3.1-*` (`[UNVERIFIED]`, D99) ·
  `(array) $provider->options` в `LlmRouteResolver` · дефект `bcsub()` на `EST-XLSX-KS2-001`
  (P2.10 Pending Work) · механический `reject` M2/M3c на разных эталонах (P2.4/P2.5 Pending Work) ·
  bump `AI_GEMINI_REQUEST_TIMEOUT_SECONDS` (PROMOTE-CANDIDATE, P2.5 Pending Work) · пробел Prism
  `unhandled finish reason` (PROMOTE-CANDIDATE, P2.5 Pending Work) · вероятностный decimal-cast на
  `dataset_example #10` (P2.5 Pending Work) · MTU-раннбук для разработчиков с похожим VPN
  (P2.5 Open risks).
- *Open questions:* OQ-1 **закрыт** (D71) · **OQ-2 закрыт** (D103 — вариант C, один замер) ·
  OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал) · OQ-5 (продуктовый контур,
  сверяется в P7.8).
