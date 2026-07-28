# HANDOFF — 2026-07-28 — after P6.2 (waiting: доступ провайдера не подтвердился фактом)

**Next:** waiting: OQ-3 разрешён → exec-items 2026.07.26-EST-TRAIN P6.2

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — предписано Routing (P6.2, implementation/medium, Review full) |
| Context | continue (/clear) — ручной item |
| Суть | Повторить гейт доступа P6.2 (`docker exec agelto_app`, минимальный вызов `PrismGeminiProvider::complete()`) после починки исходящей сети/прокси dev-контейнера; апрув цены владельцем УЖЕ получен (см. Done) и повторного запроса не требует |

```
Исполни P6.2 плана 2026.07.26-EST-TRAIN («Боевой прогон на подвыборке и отчёт с цифрами») строго
по его ТЗ в plans/2026.07.26-EST-TRAIN/phases/P6.md.

ПРЕДУСЛОВИЕ (изменилось только оно): апрув цены прогона УЖЕ получен 2026-07-28 (владелец Dmitry
Vostrikov, сумма ~$0.53 / 534 118 микро-$ — нижняя граница input-объёма из
research/11-format-bench-method.md «ИТОГО предсказано»; см. handoff Done ниже) — повторно запрашивать
апрув НЕ требуется, если состав подвыборки/критерий/цены не изменились. Гейт, который НЕ пройден
2026-07-28, — гейт ДОСТУПА (первый шаг Implementation Rules item'а): ключ `GEMINI_API_KEY` заполнен
и Prism его читает (`config:show prism.providers.gemini` → api_key непустой), `AI_GEMINI_PROXY` не
задан (D122) — но минимальный боевой вызов через `PrismGeminiProvider::complete()`
(`docker exec agelto_app php artisan tinker`) ДВАЖДЫ упёрся в `cURL error 28: Connection timed out
after 30002/45000 ms` на POST
`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent` — при
этом GET-обращение к корню того же хоста отвечает `404` быстро (<10 с), то есть DNS/TCP к хосту
работает, а именно POST-вызов генерации не проходит (похоже на DPI/SNI-фильтрацию по паттерну
запроса, тот же класс риска, что исходно стоял за OQ-3, несмотря на ответ владельца в D122).

ВХОД (порядок чтения): 1) plans/2026.07.26-EST-TRAIN/plan.md — Context, Execution Rules, §3
Routing (строка P6.2), Status Board 2) plans/2026.07.26-EST-TRAIN/handoff.md (этот файл)
3) plans/2026.07.26-EST-TRAIN/phases/P6.md → Phase Context + item P6.2 целиком 4) Required Reads
P6.2 в указанном там порядке (research/11-format-bench-method.md целиком; plan.md §5 D3/D53/D54;
open-questions.md; findings/rag-P6-tokens-2026-07-27.md и findings/rag-2026-07-26.md;
docs/specs/v2/08-estimate-extraction.md §5.1) — уже перепрочитаны в заходе 2026-07-28, повторное
чтение не требуется тем же исполнителем в той же сессии.

СКОУП — строго Scope Included P6.2 (перепроверка цен/лимитов на дату прогона — СДЕЛАНА 2026-07-28,
см. Done; апрув цены — ПОЛУЧЕН 2026-07-28, см. Done; боевой прогон `ai:bench:formats` по
ЗАФИКСИРОВАННОЙ P6.1 подвыборке; машинный артефакт; отчёт research/12-format-benchmark.md с
паспортом прогона). НЕ ТРОГАТЬ: тексты промтов (P7), критерий победителя (зафиксирован P6.1),
`config('ai.capabilities')` (D26), harness (доработка = дефект P6.1). Первый шаг ЭТОГО захода —
ПОВТОРИТЬ гейт доступа (не апрув цены — он уже получен); гейта доступа нет → item остаётся
`⬬ Not started`, handoff остаётся `waiting:`.
```

**Done:** P1 — 🟢 4/4, терминальна. P2/P3/P4/P5 — 🟠 терминальны. P6 — 🟡 1/3 (P6.1 🟢; P6.2/P6.3
`⬬ Not started`). **P7.1–P7.5 — 🟠 Done with deviations** (терминальны), P7.6 ждёт терминальности P6
(phase-first). P8 (0/5), P9 (0/6) — не начаты.

**P6.2 (заход 2026-07-28, sonnet/medium, plan-run) — доступа нет, item НЕ начат (легальный исход,
не дефект, D53/Implementation Rules P6.2):**
1. Цены и лимиты провайдера перепроверены на дату прогона (2026-07-28) через ai.google.dev
   (`gemini-api/docs/pricing`, `/docs/document-processing`, `/docs/deprecations`): `gemini-2.5-flash`
   input/output/cache — `0.30`/`2.50`/`0.03` $ за 1M токенов, страница PDF — `258` токенов
   фиксированно, лимит запроса — `50 МБ`/`1000` страниц, параметра диапазона страниц нет, EOL линейки
   2.5 — `2026-10-16`. Всё совпало с `config('ai.capabilities')` и `research/11-format-bench-method.md`
   — расхождений нет, строка отчёта не нужна. Единственная не-числовая мелочь: страница
   deprecations называет преемника `gemini-2.5-flash` как `gemini-3.6-flash`, тогда как
   `config('ai.capabilities.gemini-2.5-flash.successor')` несёт `gemini-3.5-flash` — это `successor`
   (справочное поле, не цена/лимит), Scope P6.2 числа `ai.capabilities.*` не правит (D26); отмечено
   как наблюдение, не как гейт.
2. Апрув цены получен: владелец (Dmitry Vostrikov) в этой сессии подтвердил «Апрувить, запускать
   прогон» на предсказанную нижнюю границу input-объёма **534 118 микро-$ ≈ $0.53** (таблица «ИТОГО
   предсказано», `research/11-format-bench-method.md`), с пониманием, что это НЕ полная цена
   (реальный прогон несёт несколько вызовов на документ и completion-токены сверх этой границы).
3. Гейт доступа (первый шаг Implementation Rules) НЕ пройден: `docker exec agelto_app php artisan
   tinker --execute="echo config('ai.gemini.proxy') ?: 'нет прокси';"` → `нет прокси` (согласуется с
   D122). Минимальный боевой вызов —
   `app(\App\Services\Llm\Providers\PrismGeminiProvider::class)->complete(...)` на `gemini-2.5-flash`,
   `maxTokens=16` — ДВАЖДЫ упал `Illuminate\Http\Client\ConnectionException: cURL error 28:
   Connection timed out` (30 с, затем повтор с явным 45-секундным `curl -X POST ... -d '{}'` — тоже
   таймаут). Контрольный `curl -m 10` на `GET https://generativelanguage.googleapis.com/` вернулся
   `404` быстро — базовая сетевая связность к хосту есть, обрыв специфичен именно вызову генерации.
   Item НЕ переведён в 🔴 (Implementation Rules P6.2, тот же класс исхода, что D53): статус вернул на
   `⬬ Not started` (был кратко помечен `🟡 In progress` на время диагностики, откат сделан этим же
   заходом), запись в `journal.jsonl` — `result=no-op, friction=no-op`.

Никакого боевого прогона `ai:bench:formats` не запускалось — деньги владельца не потрачены сверх
одного тестового вызова (`maxTokens=16`, вызов упал ДО отправки — таймаут на established/handshake
уровне, `prc_ai_llm_calls` записи не создал, `LlmCallRecorder` не вызывался при ConnectionException).

**Remaining:** P6.2 — доступ к провайдеру ИЗ ЭТОГО dev-контейнера нужно почтить/дать (проверить
исходящий firewall/DPI хоста и контейнера на путь POST `generativelanguage.googleapis.com/.../
generateContent`; если поможет прокси — завести `AI_GEMINI_PROXY`, D122 тогда пересматривается новым
D#, а не тихой правкой). P6.3 — ждёт P6.2. P7.6 — ждёт терминальности P6 (phase-first). P8 (0/5), P9
(0/6) — не начаты.

**Open risks:**
- **НОВОЕ: D122 («доступ локальный, без РФ-egress проблемы») не подтвердился фактом сети
  2026-07-28.** Ключ есть и читается Prism-ом, `AI_GEMINI_PROXY` не задан — но POST-вызов
  `generateContent` в `generativelanguage.googleapis.com` таймаутится дважды (30 с/45 с) из
  `docker exec agelto_app`, при этом GET на корень того же хоста отвечает быстро. Это НЕ дефект
  плана и НЕ повод переоткрывать D122 самовольно — правку доступа/прокси и/или новый D# по факту
  чинит владелец или инфраструктурный item вне Scope P6.2 (доработка сети — не «доработка harness'а»
  из Code Guidance, но и не то, что чинит исполнитель item'а по месту). До починки P6.2/P6.3/P7.6
  остаются `waiting:`.
- **`holdout` из двух кодов — сигнализация, а не статистика (D121).** `overall.f1` на нём на 71 %
  определяется одним эталоном (`EST-XLSX-LSR-001`, 155 из 219 строк), регресс мелких эталонов ловит
  порог (г), а не (б); `holdout` крупнее `tune` по строкам (219 против 199); два крупнейших
  `xlsx`-эталона критик не увидит. Лечение — рост числа эталонов с ДЕТЕРМИНИРОВАННЫМ gold (путь
  D42/D117: разметка профиля переводит `llm_assisted` → `deterministic`), это работа вне P7.5 с
  гейтом владельца и календарным окном. Раздел «Ограничения разбиения»
  (`research/14-prompt-tuning-protocol.md` §5) обязателен к прочтению ПЕРЕД P7.6/P9 —трактовать
  вердикт `accept` как «качество выросло» без него — ошибка.
- **`research/10-gold-corpus-report.md` дискредитирован как источник состава пула:** отчёт снят на
  ТЕСТОВОЙ БД и показывает два несуществующих кода (`EST-XLSX-GOOD-001`, `EST-XLSX-BROKEN-001`).
  Источник истины по пулу — реестр `prc_ai_fixtures`. Перегенерация отчёта на живой БД владельца не
  назначена — кандидат в follow-up item.
- **dev-контейнер: bind-mount не даёт `www-data` право записи в `resources/ai/prompts/**`**
  (владелец файлов — хостовый uid 1000; прод-образ делает `chown -R www-data:www-data
  /var/www/html` при сборке — там `ai:prompts:apply` пишет штатно). `www-data` добавлен в группу
  `hostgrp` (gid 1000) ВНУТРИ уже запущенного контейнера `agelto_app` — не переживёт пересборку.
- **Долг: `PromptsSuggestCommand` дублирует резолв `Analysis`/gold-файла из `EvalDiffCommand`**
  (~40 строк) — кандидат в отдельный item/`plan-design`.
- **Долг D54 (P7.1+P7.2):** версии восьми промтов подняты при несуществующем ADR — строка «набор
  промтов изменился → перезамер победителя» обязана появиться в ADR при его создании (P6.3).
- **Словари `#[Alias]` не доезжают до модели на боевых вызовах** (Pending P7.1 №2) — fix-item через
  `plan-design` вне Scope P7.1/P7.2.
- **Словарь `ModifierKind` не сделан** (Pending P7.1 №1) — ре-дизайн follow-up.
- **Позиционное сопоставление смет (`ordinal`) — граница `EvalMetrics`/`EstimateDiffReport`**
  (Pending P7.3) — не реализовано.
- Семантический дрейф `row_index` ↔ `source_position_number` в `EstimateHeaderMap` (v1-алиас).
- **БЛОКЕР `doc_type` (P5.4, унаследован):** `false_positive_rate` скрининга не измерим.
- **P3 OOM на `--prepare` (P5.5, унаследован).**
- `soffice` PATH-квирк (P5.5, унаследован) — в текущем контейнере работает.
- Посторонний незакоммиченный дифф в дереве (не мой, не трогал): `.gitignore`,
  `app/Models/Procurement/Ai/Fixture.php`, `estimates/gemini-analys.md`, `.swissknife/`.
- `phpstan-baseline.neon` растёт вне items (унаследованный кандидат в отдельный item, P5.3) — P7.5
  добавил ещё 13 записей известного паттерна, долг тот же по классу.
- Docs-sync follow-up (P3) и долг `prc_estimate_totals.kind` по `label`/`@Caption` — владелец
  не назван.

**Workarounds / Deferred / Open questions:** OQ-3 — формально `Resolved → D122`, но факт сети
2026-07-28 противоречит его формулировке (см. Open risks выше); НЕ переоткрыт самовольно — решение
о новом D#/фиксе сети за владельцем. OQ-4 (переход на 3.x-модели) — Exploration, не блокирует.
compose-CLI сессии падает на интерполяции `MINIO_ROOT_PASSWORD` — команды гонялись
`docker exec agelto_app …` (тот же контейнер).

**Sources of truth:** `plans/2026.07.26-EST-TRAIN/plan.md` (Status Board §4, D1–D122, §3 Routing) ·
`plans/2026.07.26-EST-TRAIN/phases/P7.md` (P7.1–P7.5 Completion Notes/Pending Work; P7.6
детализирован до DoR) · `plans/2026.07.26-EST-TRAIN/phases/P6.md` (P6.2 — следующий item) ·
`plans/2026.07.26-EST-TRAIN/research/11-format-bench-method.md` (методика, критерий, «ИТОГО
предсказано») · `plans/2026.07.26-EST-TRAIN/research/14-prompt-tuning-protocol.md` ·
`plans/2026.07.26-EST-TRAIN/research/18-holdout-split-rule.md` ·
`plans/2026.07.26-EST-TRAIN/findings/P7.5-splits-corpus-fact-2026-07-28.md` ·
`estimates/eval-splits.json` · `docs/specs/v2/08-estimate-extraction.md` §5.1/§6.1/§8.4/§8.6 ·
`plans/2026.07.26-EST-TRAIN/open-questions.md` (OQ-3 формально закрыт → D122, факт сети 2026-07-28
ему противоречит; OQ-4 открыт) · `plans/2026.07.26-EST-TRAIN/roadmap.md` ·
`plans/2026.07.26-EST-TRAIN/journal.jsonl`.
