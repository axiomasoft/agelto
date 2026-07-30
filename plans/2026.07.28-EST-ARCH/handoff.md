# HANDOFF — 2026-07-30 — after P4.2

**Next:** батч B1 (P4.3 + P4.4) — одна сессия

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписанная реализация поверх замороженного сейма, открытых решений нет |
| Context | continue (/clear) — ручной item |
| Суть | Виджет-диаграмма расхода по дням в `/admin` (P4.3) и команда сверки трекинга с биллингом + runbook (P4.4) — оба стоят только на замороженном контракте `root/usage-source-contract.md`, друг от друга не зависят |

````
/task:plan-exec 2026.07.28-EST-ARCH P4.3 P4.4
````

**Done:** фазы P1 (🟠, 4/8 🟢), P2 (🟠, 5/7 🟢), P3 (🟠, 3/6 🟢, проаудирована ATTENTION) закрыты.
Фаза P4: P4.6 🟢 (`af5475d`), P4.5 🟢 (`eeb519d`), P4.1 🟢 (`f13dc43`), **P4.2 🟠 (`c7313de`)**.

- **P4.2 — контракт источника расхода утверждён владельцем и реализован:**
  - `root/usage-source-contract.md` заморожен (коммит контракта `127cba0`, правка владельца `1cd3bd6`,
    строка «утверждаю» — `plan.md` §6 Update Log). Пять вопросов D124 закрыты, по каждому решению —
    отвергнутая альтернатива; место второй реализации описано ТЕКСТОМ (§5), класса-заглушки нет (D34);
  - код: `App\Services\Llm\Contracts\UsageSource::usage(UsageQuery): UsageReport` + единственная
    реализация `TrackingUsageSource`; DTO `UsageQuery`/`UsageRow`/`UsageReport`/`UsageSignature`
    (последний несёт `toLine()` — правка владельца); типизированный агрегат
    `LlmCallReadRepository::usageByAxes()`, прежний `spendByProviderModelPurpose()` снят;
  - потребители: `ai:cost-report` — старая таблица/«Итого»/экономия кэша БЕЗ изменений + АДДИТИВНАЯ
    дневная секция и строка подписи (`CostReportTest` зелен, дифф файла теста ПУСТ — D38 удержан);
    `LlmSpendCard` резолвит источник внутри `render()`, печатает подпись, мёртвый FQN и
    `prc_ai_llm_calls` с экрана сняты (D126);
  - baseline: 8 записей `property.notFound` по `LlmSpendReporter.php` осиротели и удалены — 249→241
    записей, `count:` 318→310, `property.notFound` 212→204; добавленных строк ноль (D71/D125);
  - **отклонение (🟠):** дифф в ДВУХ файлах вне `Files` — биндинг `UsageSource` в
    `AppServiceProvider.php` (решение владельца: сейм без биндинга был бы декоративным) и счётчик
    периметра ядра 83→89 в `tests/Unit/Architecture/CoreKnowsNoDocumentTypeTest.php` (канарейка §6.2,
    красный сюит закрывать запрещено).

**Remaining:** 14 items ⬜. Порядок: **B1 (P4.3+P4.4) → P5 ×6 → P6 ×3 → P7 ×4 (B2 = P7.1+P7.2)**.
Гейтов владельца впереди НЕТ — P4.2 был последним из семи карты. Батчи: **B1** (`plan-exec`),
**B2** (`plan-run`) — `roadmap.md`; **B3** отработан.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (D115 форма гейта стиля, D121 форма
шаблонов, D122 СТОРОЖ), §3 Routing, §5 (D1–D126) · ЗАМОРОЖЕНЫ: `root/naming-canon.md`,
`root/document-type-axis.md`, `root/document-contour.md`, `root/document-analyzer-contract.md`,
`root/service-layer-map.md` (её §4 — реестр единиц фазы P3, НЕ закрытый белый список: D123),
**`root/usage-source-contract.md`** (заморожен 2026-07-30, вход для P4.3/P4.4) ·
`phases/P4.md` (P4.1/P4.5/P4.6 🟢, P4.2 🟠) · `findings/P4.2-usage-source-facts-2026-07-30.md` ·
`findings/usage-accounting-2026-07-29.md` · `findings/rag-provider-billing-2026-07-28.md` ·
`roadmap.md` · `open-questions.md` (внутри плана открытых нет; OQ-14 — вне плана).

**Open risks:**
- **Счётчик периметра ядра — общий ресурс:** любой следующий item, заводящий файл в восьми
  неймспейсах §6.2, снова покраснит `CoreKnowsNoDocumentTypeTest` (сейчас 89). Это канарейка, а не
  дефект, но `Files` items P5–P7 её не называют — ремонт снова пойдёт диффом вне `Files`.
- **Формулировка Validation 1 «счётчик мутации падает до 0»** выполняется для одного счётчика из
  четырёх (Known Deviations P4.2 п.2): гейты над создаваемым документом, чьё понятие цитируется
  несколькими разделами, обнулить секционной мутацией нельзя — форму стоит уточнить в §2 до P5.
- **`ai:cost-report` стал шумнее** (дневная таблица + подпись + возможное предупреждение о
  горизонте) — если оператору нужен короткий вывод, это новый флаг и новая D-запись, не правка на ходу.
- **Дефект компилятора Task Contract жив** (OQ-14): правило §2/D121 — обход, а не решение.
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
  починки пакета `swissknifeman/task`.
- deferred: перевод третьего харнесса (bench/eval) на `UsageSnapshotRecorder` (Pending Work P4.1);
  развёрнутое описание нового вывода `ai:cost-report` в `docs/03_Dev/*` (Pending Work P4.2 п.1);
  ужесточение формы возврата `usageByAxes()` до DTO репозитория (Pending Work P4.2 п.2);
  выравнивание литерального текста гейтов стиля в фазах P5–P7 (D115); ревизия имён
  `ai:storage:lifecycle`/`ai:tools:doctor` — P7.3/P7.4; сквозной `plan-design finish` перед уходом фаз
  P5–P7 в исполнение; разбор 263 находок `verify-citations.py` до корня; комментарий `config/ai.php:180`
  с устаревшим именем таблицы (D126).
- open_questions: внутри плана открытых нет. Вне плана — **OQ-14** (дефекты компилятора Task
  Contract, реопен-кандидат в `swissknifeman/task`). Гейтов владельца впереди нет.
