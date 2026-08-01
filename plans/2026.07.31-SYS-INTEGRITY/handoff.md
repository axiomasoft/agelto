# HANDOFF — 2026-08-01 — after P2.9

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P2.10

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high |
| Capabilities | — |
| Context | same-session — item |
| Суть | Пере-baseline контура фазы: два прогона `estimate:eval:run --split=all` на дереве, уже несущем item-коммит P2.9 (`b667b3e`), без единого изменения между ними — новая пара M0₂/M0₂′ и новая измеренная величина шума (D100), база для M2 → P2.4. Платный item (два прогона полного корпуса, цена утверждена владельцем) |

```
/task:plan-run 2026.07.31-SYS-INTEGRITY P2.10

РЕЖИМ: исполнение заведённого item'а P2.10 по готовому ТЗ (не design, не реопен — фаза P2
  🟡 In progress, P2.10 ⬜ Not started).
ВХОД: `plans/2026.07.31-SYS-INTEGRITY/phases/P2.md` item P2.10 (все 16 полей) +
  `plans/2026.07.31-SYS-INTEGRITY/findings/P2-eval-baseline.md` (процедура и команды парного
  baseline, состав корпуса прошлой пары) + `research/03-prompt-contract.md` §8 (лестница
  замеров) + `plan.md` §5 D91/D100.
СКОУП: два прогона `estimate:eval:run --split=all` на дереве, уже несущем item-коммит P2.9
  (`b667b3e`), БЕЗ единого изменения между ними (M0₂/M0₂′); сравнение M0₂↔M0₂′ обоими критериями
  (`--criterion=no-regression` и штатным) как ИЗМЕРЕНИЕ разброса; фиксация новой величины шума
  (по подвыборке/агрегату/эталону) и числа обработанных эталонов; запись результата в
  `findings/P2-eval-baseline.md` новым разделом и обновление `research/03-prompt-contract.md` §8
  (строки M0₂/M0₂′ + указание, что база для M2 — M0₂).
НЕ ТРОГАТЬ: любая правка кода/промтов/схемы/контрактов/корпуса/эталонов/порогов
  `EvalRunComparator` (item ИЗМЕРЯЕТ, дифф кода не содержит вовсе); повторный замер дельты P2.3
  (P2.3 закрыт 🟠 и не переоткрывается — его дифф структурно неотделим от фикса P2.9); третий
  прогон «для верности» (пара — это определение шума по D91); гидрация/пересборка корпуса
  (сделана P2.2, корпус — замороженный вход).
```

**Done:** **P2.1 🟢** (`ced2a4f`) · **P2.8 🟠** (`b51ce3a`+`b05235f`) · **P2.2 🟢**
(`19f9808`+`684ddfb`) · **P2.3 🟠** (`bcd7ad4`) · **P2.9 🟠** (`b667b3e`).

**Этим заходом (`plan-run`, sonnet/high) закрыт P2.9** — корень блокера P2.4 снят целиком (D98):
бюджет вывода и уровень размышления extraction-маршрутов переведены из константы резолвера
(`LlmRouteResolver::DEFAULT_MAX_TOKENS = 32768`, общая на скрининг и извлечение) в настройку
`llm_routes.options` (`max_tokens`/`thinking_level`), с fail-fast в резолвере при превышении
RAG-подтверждённого потолка модели (`config('ai.capabilities.<model>.max_output_tokens')`).
Значения взяты из фактуры, не из вкуса (D99): семь падений EvalRun #1–3 (`llm_calls.error`) дали
медианную долю `thinking/total` = 0.5303 (≥ порога 0.4) → `thinking_level=LOW` на трёх
extraction-маршрутах (обе строки приоритетов); потолок вывода — 65536 у `gemini-3.5-flash`
(RAG:✅, единственная подтверждённая модель). Второй, более тяжёлый дефект — спроектированный
предохранитель «MAX_TOKENS → делим окно диапазона пополам» не срабатывал НИ РАЗУ, потому что Prism
на этом исходе бросал `PrismException` вместо ответа с признаком обрезки: `PrismGeminiProvider::
completeStructured()` теперь узко ловит именно эту вендорскую сигнатуру и переводит в
`LlmStructuredResponse(finishReason: FinishReason::MaxTokens)` с числами best-effort из текста
исключения; любой другой `PrismException` пробрасывается без изменений. Доказано `git stash`
реализации (5 файлов) — новые тесты трёх файлов (`PrismGeminiProviderTest`/`LlmRouteResolverTest`/
`AiRoutingSeederTest`) корректно краснели без фикса, зеленели после `git stash pop`. Попутно
найден и исправлен смежный дефект, блокировавший собственную Deliverable item'а: `(array) $route
->options` на заполненной `AsCollection`-коллекции кастовал protected-свойства объекта, а не
данные — молча терял `max_tokens`/`thinking_level` у ЛЮБОЙ непустой записи `options` (дормантно до
P2.9, т.к. `options` был `null` у ВСЕХ строк сидера до этого item'а).

**Material-отклонение (Known Deviations, статус 🟠):** `tests/Feature/Procurement/Analyzers/
Estimate/ExtractionOrchestratorTest.php` — диффа НЕТ, хотя файл в `Files` item'а и Implementation
Rules прямо предписывали новый тест на деление окна (`FakeLlmProvider::truncated()` →
`succeeding()`). Причина — диагноз item'а неточен: пред-существующий тест `MAX_TOKENS на
диапазоне строк — окно уменьшается вдвое...` (`ExtractionOrchestratorTest.php:217`, коммит
`75017200`, 2026-07-08) уже покрывает СЕМАНТИЧЕСКИ тот же кейс через ручной helper
`extractionMaxTokens()` — диагноз искал буквальную подстроку `"truncated("` и не нашёл её, отсюда
ложный вывод «покрытия нет вовсе». Байт-дубликат этого теста НЕ зависел бы от диффа P2.9
(halving — код `ExtractionOrchestrator`/`RowRangeChunkSource`, ни один не в `Files` этого item'а)
и не доказал бы «краснеет без фикса» — реальный gate-proof лежит в `PrismGeminiProviderTest.php`
(реальный Prism-хендлер через `Http::fake`).

**Lint:** `python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py
plans/2026.07.31-SYS-INTEGRITY` — итог прогона этого захода назван в финальном сообщении сессии.

**Remaining:** 40 item из 55. Внутри фазы: P2.10 → P2.4 → P2.5 → P2.6 → P2.7. Дальше по очереди
D63: `P3 → P5 → P6 → P4 → P7`.

**Sources of truth:** `plan.md` (Meta v0.16.0; §3 Routing поitem'ный, строка P2.10 —
implementation/high/manual/full; §5 D1–D100; §4 Status Board — P2 2/10 🟢, счётчик не включает 🟠) ·
`phases/P2.md` (ТЗ items; Phase Context — очередь и узкое исключение D98) ·
**`findings/P2-extraction-token-budget-2026-08-01.md`** (диагноз блокера + §4 собранная фактура и
решения P2.9) · `research/03-prompt-contract.md` §8.5 (красный M1) и §8.6 (пересборка лестницы,
база M2 = M0₂ — снимет P2.10) · `findings/P2-eval-baseline.md` (старая пара M0/M0′, процедура
парного baseline — вход P2.10) · `findings/P2-textgrid-oom-2026-08-01.md` ·
`findings/P2-corpus-state-2026-08-01.md` · `findings/P2-vendor-structured-output.md` ·
`roadmap.md` · `index.md` · `open-questions.md` · `journal.jsonl` ·
`brief/01-refinements.md` (блок 2026-08-01, заход снятия блокера P2.4).

**Open risks:**

- **Потолки вывода `gemini-3.1-flash-lite` и `gemini-3.1-pro-preview` `[UNVERIFIED]`** — RAG их не
  подтвердил, ключ `max_output_tokens` этим моделям НЕ поставлен (D99, осознанная граница);
  эскалационная строка `gemini-3.1-pro-preview` extraction-маршрутов несёт `max_tokens=65536` БЕЗ
  fail-fast проверки (ключа потолка у модели нет).
- **Отдельная величина «дельта P2.3» утрачена навсегда** (D100 Consequences) — дифф P2.3
  структурно неотделим от фикса P2.9.
- **После фикса могут обрабатываться не все 6 эталонов** — правило P2.10 запрещает лечить это
  внутри item'а: назвать поимённо и вынести в Pending Work.
- **`estimate:gold:import --prepare` не переводит документ в `prepared`** (P2.8 Known Deviations).
- **Дешёвый size-gate слеп на легаси `.xls`** (P2.8 Scope Excluded).
- **DB частично гидрирована, НЕ откатана**: `prc_ai_fixtures`=24, `prc_ai_dataset_examples`=17,
  `prc_ai_eval_runs`≥3 (M0, M0′, M1). **Не запускать `migrate:fresh` до закрытия P2.10.**
- **`EST-PDF-SCAN-001` (id 7)** в шестёрку обязательных gold НЕ входит, baseline не блокирует.
- **Представления пересоздаются на ТЕКУЩЕМ образе** — сопоставимо внутри P2, не с числами донора.
- **Деньги.** P2.10 — два прогона полного корпуса (цена утверждена владельцем, D100). Дальше — по
  одному на шаг (P2.4–P2.6), плюс три на варианты OQ-2 в P2.5.
- **Расхождение порядка свойств между двумя носителями живёт в боевом коде СЕЙЧАС** — чинится P2.4.
- **Пол префикса может не сработать как защита** — байтовый якорь не переведён в токены,
  `[UNVERIFIED]` для `gemini-3.1-flash-lite`. Обязанность P2.5 (D90).
- **Канал проекта помечен вендором Legacy** — вход EOL-мониторинга, P2.7 фиксирует строкой риска.
- **Строка Update Log для `plan-audit P1` (заход 2) отсутствует** — вне скоупа.
- **A1 аудита P1 (Major) не закрыт**: потребление `ProxyRequirements->country` не покрыто
  дискриминирующим тестом — решение владельца.
- **Partition-drift — падения полного сюита вне предмета плана** (`llm_calls` — нет партиции под
  текущую дату; ~9 тестов) — фикс-предложение `phases/P1.md` P1.10 Pending Work. Два красных
  arch-теста `CoreKnowsNoDocumentTypeTest` исключены поимённо (D86).
- **`verify-citations.py` — находки вне диффа**; инструмента в `plans/` этого репозитория нет (D76).
- **`PromptCanonReader.php`** несёт пред-существующую ссылку на `Services\Procurement` внутри
  `Llm` — PROMOTE-CANDIDATE, не предмет фазы.
- **`(array) $provider->options` в `LlmRouteResolver::toResolvedRoute()` несёт ТОТ ЖЕ класс
  дефекта**, что был исправлен для `$route->options` в P2.9 (кастует protected-свойства
  `AsCollection`, а не данные) — дормантно, т.к. `Provider.options` всегда `null` у
  `AiRoutingSeeder`; вне Scope P2.9 (не блокирует его Deliverable), PROMOTE-CANDIDATE для будущего
  item'а, если `apiKeyEnv`/`OpenAiCompatibleProvider` когда-нибудь получит непустые provider-опции.
- **`docker compose` на хосте**: `MINIO_ROOT_PASSWORD`-интерполяция падает — обходка
  `docker exec agelto_app` рабочая.
- **Незапланированные файлы в рабочем дереве**: `M .env.example`, untracked `config/cms.php`,
  `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife/`,
  `plans/2026.07.31-SYS-INTEGRITY/.claude/`, `plans/2026.07.31-SYS-INTEGRITY/.swissknife/` — чужой
  дифф из `plan.md` §2, не трогать.
- **Кросс-планная зависимость** P5 от `2026.07.26-EST-TRAIN` — сверяется перед стартом P5.
  **P4.7 — точка невозврата по схеме**: P5 и P6 обязаны быть терминальны до него.

**Workarounds/Deferred/Open questions:**

- *Workarounds:* `docker exec agelto_app` вместо `docker compose exec app` в неинтерактивных
  оболочках агента.
- *Deferred:* строка Update Log для `plan-audit P1` захода 2 · A1/A2/A3/A6 аудита P1 · правка ТЗ
  P1.2/P1.3 (D20) · перенос слоёв класса B (D28) · сплошное покрытие тестами команд и job'ов (D59) ·
  положения (3)–(5) целевой модели хранения (D72) · переход транспорта ЕИС на XML/SOAP (D14) ·
  переименование дисков (D53) · судьба таблицы кэша (D75) · связь «выделенные узлы под конкретный
  профиль» (D80) · `ProxyForm::COUNTRIES` (D86) · починка двух pre-existing
  `CoreKnowsNoDocumentTypeTest` (D86) · разовый флейк `CsvBlockIngestorTest` · partition-drift
  (P1.10 Pending Work) · миграция на Interactions API вендора (мониторинг) · починка слепого
  size-gate на легаси `.xls` (P2.8 Scope Excluded) · статус `EST-PDF-SCAN-001` (вне шестёрки gold) ·
  решение о диспетче `PrepareDocumentJob` из `estimate:gold:import --prepare` (P2.8 Pending Work) ·
  деградация оконного пути (`WindowedChunkSource`) при MAX_TOKENS — колбэка нет осознанно (P2.9
  Scope Excluded) · потолки вывода `gemini-3.1-*` (`[UNVERIFIED]`, D99) ·
  `(array) $provider->options` в `LlmRouteResolver` — тот же класс дефекта, что чинен для
  `$route->options` в P2.9, дормантен (см. Open risks).
- *Open questions:* OQ-1 **закрыт** (D71) · **OQ-2** (судьба `PromptContextGenerator`) — снимает
  P2.5 · OQ-3 (источник истины ЕИС, снимает P4.1) · OQ-4 (браузерный канал) · OQ-5 (продуктовый
  контур, сверяется в P7.8).
