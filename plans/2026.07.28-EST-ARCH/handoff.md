# HANDOFF — 2026-07-30 — after P4.3

**Next:** exec-item: task:plan-exec 2026.07.28-EST-ARCH P4.4

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Батч B1, второй item: команда `ai:cost-reconcile` + `BillingReconciler` + runbook сверки трекинга с биллингом провайдера, поверх замороженного сейма P4.2 |

````
/task:plan-exec 2026.07.28-EST-ARCH P4.4

РЕЖИМ: исполнение ⬜ item'а P4.4 (второй в батче B1), закрытие полным протоколом §8
ВХОД: plans/2026.07.28-EST-ARCH/phases/P4.md → P4.4 (ТЗ переписано 2026-07-30) ·
      findings/P4.3-P4.4-gate-facts-2026-07-30.md §5–§7 (факты дерева на 80cec5f, гейты «до») ·
      root/usage-source-contract.md (заморожен) · plan.md §2, §5 D127–D131
СКОУП: команда ai:cost-reconcile + сервис BillingReconciler + блок 'reconcile' в config/ai.php +
       runbook billing-reconcile.md + фикстуры + тест, 10 строк Validation
НЕ ТРОГАТЬ: app/Providers/Filament/AdminPanelProvider.php · app/Guards/Admin/Roles/** и
      config/az-guard-filament.php (права ролям — P5) · существующие блоки config/ai.php
      (pipeline, cost_report) · планировщик · phpstan-baseline.neon (расширение запрещено) ·
      закрытые items P4.1/P4.2/P4.3/P4.5/P4.6 · untracked .swissknife/ и estimates/gemini-analys.md
````

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢, проаудирована ATTENTION) закрыты.
Фаза P4: P4.6 🟢 (`af5475d`), P4.5 🟢 (`eeb519d`), P4.1 🟢 (`f13dc43`), P4.2 🟠 (`c7313de`),
**P4.3 🟢 (`afb8fe2`)**.

- **Этот заход (`plan-exec P4.3 P4.4`, первая половина, 2026-07-30) — P4.3 закрыт.** Виджет
  `LlmSpendChartWidget` (`app/Filament/Widgets/`) поверх сейма источника P4.2: серии
  провайдер·модель по дням, подпись `UsageSignature::toLine()` в `getDescription()`, явная пометка
  «за горизонтом», право доступа `HasAzGuardWidget` (ключ `admin.llm_spend_chart_widget.view`),
  регистрация авто-discovery без правки `AdminPanelProvider.php` (D127).
- **Построчная ревизия черновика (D128, Implementation Rule 8) нашла ДВЕ реальные ошибки** —
  черновик из прошлой сессии не проходил ни одного гейта:
  1. `getDescription()` объявлен `protected`, а базовый `Filament\Widgets\ChartWidget::getDescription()`
     — `public`: сужение видимости в PHP даёт Fatal Error на любом обращении к классу (воспроизведено
     `php artisan --version` до правки). Переведён в `public`.
  2. Серия по чужому дню читалась через `$costByDay->get($day)?->costMicros ?? 0`. PHPStan
     (`nullsafe.neverNull`) счёл `?->` недостижимым и посоветовал `->`; убрать nullsafe вслепую было
     бы неверно — реальный сценарий (провайдеры пишут в разные сутки) даёт `get()===null`, и
     `->costMicros` на нём — PHP-warning «Attempt to read property on null», а не безопасный код.
     Переписано на явный `$row === null ? 0 : $row->costMicros`; поведение закрыто регресс-тестом.
  Остальной черновик (данные только через `UsageSource`, подпись, `beyondHorizon`, серии,
  `HasAzGuardWidget`) принят без правок.
- Тест — 8 кейсов / 11 ассертов: состав панели, три исхода дневного разреза (одни/разные
  сутки/пустая БД) + регресс-кейс «чужой день серии», подпись + горизонт, ДВА разных исхода права
  (суперадмин/гость). Полный сюит 817 passed/1 skipped/3337 assertions на `afb8fe2`, phpstan 0
  ошибок без роста baseline, pint зелёный (автофикс стиля затронул только тест).
- **Операционная заметка (не отклонение item'а):** при разведке поведения `get()===null` сценарий
  был по ошибке прогнан через `artisan tinker` без `MINIO_ROOT_PASSWORD`-обхода `.env.testing` —
  две строки временно попали в DEV-таблицу `llm_calls` (`agelto`, не `agelto_test`), обнаружены
  (`LlmCall::count()` = 2, единственные строки в таблице) и немедленно удалены тем же tinker-вызовом
  до какого-либо коммита. `git status`/тесты подтверждают: PostgreSQL dev-БД не несёт следов этого
  захода. Урок — разведка поведения кода идёт только тестом (`RefreshDatabase` + `agelto_test`),
  не `tinker` против `.env`.

**Remaining:** 14 items ⬜ (P4.4 следующий, затем P5 ×6, P6 ×3, P7 ×4 (B2 = P7.1+P7.2)). Гейтов
владельца впереди нет.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (D89 форма гейта на `<base>`, D115 форма
гейта стиля, D121 форма шаблонов, D122 СТОРОЖ), §3 Routing, §5 (D1–D131) · ЗАМОРОЖЕНЫ:
`root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`, `root/service-layer-map.md` (её §4 — реестр единиц фазы P3,
НЕ закрытый белый список: D123), `root/usage-source-contract.md` (вход для P4.4) ·
`phases/P4.md` (P4.1/P4.3/P4.5/P4.6 🟢, P4.2 🟠, P4.4 ⬜) ·
`findings/P4.3-P4.4-gate-facts-2026-07-30.md` §5–§7 (P4.4) · `findings/P4.2-usage-source-facts-2026-07-30.md` ·
`findings/usage-accounting-2026-07-29.md` · `findings/rag-provider-billing-2026-07-28.md` ·
`roadmap.md` · `open-questions.md` (внутри плана открытых нет; OQ-14 — вне плана).

**Open risks:**
- **Право виджета не выдаётся ролям в P4.3** — до P5 диаграмму расхода видит только суперадмин
  (Pending Work P4.3). Осознанная граница (права панели — предмет P5).
- **Счётчик периметра ядра — общий ресурс:** любой следующий item, заводящий файл в восьми
  неймспейсах §6.2, снова покраснит `CoreKnowsNoDocumentTypeTest` (сейчас 89). Это канарейка, а не
  дефект, но `Files` items P5–P7 её не называют — ремонт снова пойдёт диффом вне `Files`.
- **Формулировка Validation 1 «счётчик мутации падает до 0»** выполняется для одного счётчика из
  четырёх (Known Deviations P4.2 п.2): гейты над создаваемым документом, чьё понятие цитируется
  несколькими разделами, обнулить секционной мутацией нельзя — форму стоит уточнить в §2 до P5.
- **`ai:cost-report` стал шумнее** (дневная таблица + подпись + возможное предупреждение о
  горизонте) — если оператору нужен короткий вывод, это новый флаг и новая D-запись, не правка на ходу.
- **Дефект компилятора Task Contract жив** (OQ-14): правило §2/D121 — обход, а не решение.
  Предписанный `PYTHONPATH=packages/task/lib` НЕ резолвится в этом репо (`agelto` не вендорит пакет
  `task`) — гейты `plan-exec` прогоняются через внешний путь `swissknifeman/packages/task/lib` по
  решению владельца; постоянный путь или вендоринг — вопрос вне предмета плана.
- **`verify-citations.py` на всём плане даёт 263 находки «файл не найден»** — систематический баг
  резолва путей самого инструмента.
- **Третий харнесс (`FormatBenchRunner`/`ExtractionEvalRunner`/`ScreeningEvalRunner`) не переведён на
  `UsageSnapshotRecorder`** — Pending Work P4.1, отдельного item плана не заводит.
- **`plan-lint` даёт 1 ERROR вне диффа этого захода** — строка `write-site:` item'а P4.5 обёрнута
  `**…**`, линтер матчит без обёртки (`plan_lint.py:370`); P4.5 закрыт, история сдачи не правится.
- **A4 аудита P3 — исполнители пропускают построчный отчёт по Validation** (наследуется).
- **Тексты Validation items P5–P7 цитируют `pint --dirty --test`** — форма отменена D115 (в P4.3/P4.4
  уже исправлена).
- **`CLAUDE.md` несёт то же ложное утверждение про путь модели промта**, что чинил P4.5 — закрывает P7.4.
- **Дрейф `prc_ai_llm_calls` вне периметра P4.2** — `config/ai.php:180` и пять файлов
  `docs/specs/v2/**`: класс-закрыватель назван (P7.3/P7.4), item'а под комментарий конфига нет (D126).
- **Посторонние untracked-пути в дереве** — `.swissknife/`, `estimates/gemini-analys.md`: не входят ни
  в один `Files`, не коммитить, не удалять.
- Наследуются из P1/P3: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 · боевой прогон
  P5.2 — за владельцем (D94).

**Workarounds/Deferred/Open questions:**
- workarounds: **правило формы §2/D121** — обход дефекта компилятора Task Contract, снять после
  починки пакета `swissknifeman/task`; **внешний `PYTHONPATH` на `swissknifeman/packages/task/lib`**
  — обход отсутствия вендоринга пакета `task` в `agelto`.
- deferred: перевод третьего харнесса (bench/eval) на `UsageSnapshotRecorder` (Pending Work P4.1);
  развёрнутое описание нового вывода `ai:cost-report` в `docs/03_Dev/*` (Pending Work P4.2 п.1);
  ужесточение формы возврата `usageByAxes()` до DTO репозитория (Pending Work P4.2 п.2);
  выдача права `admin.llm_spend_chart_widget.view` ролям (P5, Pending Work P4.3); выравнивание
  литерального текста гейтов стиля в фазах P5–P7 (D115); ревизия имён
  `ai:storage:lifecycle`/`ai:tools:doctor` — P7.3/P7.4; сквозной `plan-design finish` перед уходом
  фаз P5–P7 в исполнение; разбор 263 находок `verify-citations.py` до корня; комментарий
  `config/ai.php:180` с устаревшим именем таблицы (D126).
- open_questions: внутри плана открытых нет. Вне плана — **OQ-14** (дефекты компилятора Task
  Contract, реопен-кандидат в `swissknifeman/task`). Гейтов владельца впереди нет.
