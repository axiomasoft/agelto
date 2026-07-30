# HANDOFF — 2026-07-30 — after P4.4

**Next:** exec-item: task:plan-exec 2026.07.28-EST-ARCH P5.1

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Первый item фазы P5 (Filament-обучалка): ресурс документа обучения — загрузка файла, код, профиль типа, право доступа ролям |

````
/task:plan-exec 2026.07.28-EST-ARCH P5.1
````

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢, проаудирована ATTENTION),
**P4 (🟠, 4/6 🟢, терминальна)** закрыты.
Фаза P4: P4.6 🟢 (`af5475d`), P4.5 🟢 (`eeb519d`), P4.1 🟢 (`f13dc43`), P4.2 🟠 (`c7313de`),
**P4.3 🟢 (`afb8fe2`), P4.4 🟠 (`8fdabbe`)**.

- **Этот заход (`plan-exec P4.3 P4.4`, 2026-07-30) — оба item'а батча B1 закрыты, фаза P4 терминальна.**
- **P4.3 🟢** — виджет `LlmSpendChartWidget` на дашборде `/admin` поверх сейма P4.2. Построчная
  ревизия черновика (D128) нашла и починила ДВЕ реальные ошибки: `getDescription()` был `protected`
  при `public`-родителе (Fatal Error на любом обращении к классу); серия по чужому дню провайдера
  читалась через nullsafe, который PHPStan счёл недостижимым — убрать его вслепую было бы неверно
  (реальный runtime-риск PHP-warning «read property on null»), переписано на явный null-check с
  регресс-тестом.
- **P4.4 🟠** — команда `ai:cost-reconcile` + сервис `BillingReconciler` поверх того же сейма:
  нормализованный CSV нашего формата (D130), порог из `config('ai.reconcile.threshold_percent')`
  (D129, дефолт 10 %), отказ без пересчёта валют (Rule 10), runbook `billing-reconcile.md`.
  Находка при первом прогоне: `CarbonImmutable::createFromFormat()` в Carbon 3 бросает
  `InvalidFormatException`, а не возвращает `false` — PHPStan поймал мёртвую ветку ДО коммита, обе
  точки разбора дат переведены на `try/catch`. **Отклонение:** item-коммит несёт дифф вне `Files` —
  `tests/Unit/Architecture/CoreKnowsNoDocumentTypeTest.php` (канарейка периметра ядра 89→92, три
  новых файла легли в `App\Services\Llm`), тот же класс, что у P4.2 → 🟠 Done with deviations.
- Оба item'а: полный `artisan test` зелёный (825 passed/1 skipped/3376 assertions на `8fdabbe`),
  `phpstan` 0 ошибок без роста baseline, `pint` зелёный, все гейты на `<base>` подтверждены красными
  (`git grep`/`git stash`), `plan-lint --baseline HEAD` — 0 новых ERROR от диффа этого захода.

**Remaining:** 12 items ⬜ — фаза P5 (6, следующая: P5.1), P6 (3), P7 (4, B2 = P7.1+P7.2). Гейтов
владельца впереди нет. **Фаза P4 терминальна — реконсилирующее закрытие (`plan-close`) не выполнено
в этом заходе**, останется отдельным шагом (образец — P3: `plan-close` заполнил `## Phase Handoff`,
агрегировал Known Deviations шести items, подтвердил docs-sync).

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (D89 форма гейта на `<base>`, D115 форма
гейта стиля, D121 форма шаблонов, D122 СТОРОЖ), §3 Routing, §5 (D1–D131) · ЗАМОРОЖЕНЫ:
`root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`, `root/service-layer-map.md` (её §4 — реестр единиц фазы P3,
НЕ закрытый белый список: D123), `root/usage-source-contract.md` (реализован P4.2/P4.3/P4.4) ·
`phases/P4.md` (ВСЕ 6 items терминальны: P4.1/P4.3/P4.5/P4.6 🟢, P4.2/P4.4 🟠; `## Phase Handoff` —
плейсхолдер, не заполнен) · `phases/P5.md` (следующая фаза, уже детализирована до DoR) ·
`roadmap.md` · `open-questions.md` (внутри плана открытых нет; OQ-14 — вне плана).

**Open risks:**
- **Фаза P4 не прошла реконсилирующее закрытие (`plan-close`)** — `## Phase Handoff` P4 не заполнен,
  агрегат Known Deviations по 6 items не собран, docs-sync не подтверждён отдельным шагом. Следующий
  проход по P4 (аудит или `plan-close Pn`) обязан это сделать перед тем, как фаза P4 будет считаться
  полностью закрытой административно (items уже терминальны и не блокируют P5).
- **Право виджета не выдаётся ролям в P4.3** — до P5 диаграмму расхода видит только суперадмин
  (Pending Work P4.3). Осознанная граница (права панели — предмет P5).
- **Счётчик периметра ядра — общий ресурс, теперь 92** (было 89 после P4.2, +3 от P4.4): любой
  следующий item, заводящий файл в восьми неймспейсах §6.2, снова покраснит
  `CoreKnowsNoDocumentTypeTest`. Канарейка, а не дефект, но `Files` items P5–P7 её не называют —
  ремонт снова пойдёт диффом вне `Files` (прецедент — P4.2, P4.4).
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
- **Тексты Validation items P5–P7 цитируют `pint --dirty --test`** — форма отменена D115.
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
- deferred: реконсилирующее закрытие фазы P4 (`plan-close`); перевод третьего харнесса (bench/eval)
  на `UsageSnapshotRecorder` (Pending Work P4.1); развёрнутое описание нового вывода `ai:cost-report`
  в `docs/03_Dev/*` (Pending Work P4.2 п.1); ужесточение формы возврата `usageByAxes()` до DTO
  репозитория (Pending Work P4.2 п.2); выдача права `admin.llm_spend_chart_widget.view` ролям (P5,
  Pending Work P4.3); выравнивание литерального текста гейтов стиля в фазах P5–P7 (D115); ревизия
  имён `ai:storage:lifecycle`/`ai:tools:doctor` — P7.3/P7.4; сквозной `plan-design finish` перед
  уходом фаз P5–P7 в исполнение; разбор 263 находок `verify-citations.py` до корня; комментарий
  `config/ai.php:180` с устаревшим именем таблицы (D126).
- open_questions: внутри плана открытых нет. Вне плана — **OQ-14** (дефекты компилятора Task
  Contract, реопен-кандидат в `swissknifeman/task`). Гейтов владельца впереди нет.
