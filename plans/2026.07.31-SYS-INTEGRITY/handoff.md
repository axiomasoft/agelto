# HANDOFF — 2026-08-04 — after P3.3

**Next:** run-items: task:plan-run 2026.07.31-SYS-INTEGRITY P3.4

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — форма гейта уже доказанно даёт ложно-зелёный на неверной форме (`core-arch-gate-2026-07-30.md`), цена ошибки — недоказанный сторож раскладки |
| Context | continue (/clear) — ручной item |
| Суть | Написать `tests/Unit/Architecture/DomainLayoutTest.php` (I1/I2/I3) с обязательной красной пробой каждой из трёх проверок по фактическому периметру P3.2+P3.3 |

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P3.4
```

Батч B2 (`P3.2-P3.3`) завершён — оба item'а закрыты 🟠 в этом заходе.

**Сделано этим заходом (P3.2+P3.3, `plan-run`, sonnet/high, 2026-08-04).**

**P3.2** — item-коммит `c25bf2e` (285 файлов): 33/40 файлов `app/Enums/Procurement` разложены по
`Crawler/Proxy/Document/Ai/Estimate` + `FinishReason`→`App\Enums\Llm\` (D113). **P3.3** —
item-коммит `4e844fe` (124 файла): три утверждённых D113 слоя класса A —
`app/Exceptions/Procurement` (13/15, включая домен-пересечение `BillingExportUnreadable`/
`UnknownProviderDriver` → `App\Exceptions\Llm\` по правилу §2.1, без поимённого research/04
ИСКЛЮЧЕНИЯ — Known Deviation), `app/Dto/Procurement` (4/7, подобласть `Crawler`),
`app/Repositories/Procurement` (13/16). Оба item'а закрыты **🟠 Done with deviations**. Полные
числа, разбор Validation-клауз и Known Deviations — `phases/P3.md` P3.2/P3.3 Completion Notes.

Оба захода нашли и починили implicit same-namespace ссылки (класс/наследник ссылался на соседа по
бывшей плоской namespace БЕЗ `use`, легально ДО переноса, сломалось после разъезда по
подкаталогам/доменам): P3.2 — `PipelineStage::fromAnalysisStep()` → `AnalysisStep`; P3.3 — 12
подклассов `OperationFailed`. Оба класса дефектов не покрывались FQN-string-replace (замена
литерала `App\...\X`) — обнаружены отдельным скриптом-сканером implicit-ссылок, весь периметр
обоих items дважды просканирован после фикса, 0 остаточных находок.

**Инцидент исполнения (устранён без потери данных, P3.2):** `git stash push -u` упёрся в
ACL-ограниченные каталоги `app/Filament/Resources/Procurement/Ai/Fixtures/**` и прервался
частично — восстановлено из `stash@{0}` (включая untracked-дерево), стэш дропнут. **`git stash`
в этом дереве больше не используется** — при необходимости точечного отката файла:
`git checkout <rev> -- <path>` (write, не unlink+directory-op, ACL не мешает). Отдельно (P3.3):
`pint --dirty` (без `--test`, автофикс) задел untracked `config/cms.php` (`single_blank_line_at_eof`)
— сразу восстановлен побайтово из исходного untracked-снапшота.

**Status:** оба 🟠. P3.2 — item-коммит несёт `routes/console.php` вне `Files` (находка полного
прогона, вне 353-файловой поверхности research/04 §5) + Validation-клаузы (2)/(3) красны по
причинам вне Scope (детектор ловит легитимные формы + 10 pre-existing тестов). P3.3 — домен-
пересечение 2 exceptions в `Llm` без поимённого research/04 ИСКЛЮЧЕНИЯ (правило §2.1 применено
единообразно, но пара не прошла тот же явный owner-facing провенанс, что `FinishReason`) — если
это расхождение с намерением владельца, нужна новая D-запись, иначе ревёрт двух файлов в
`App\Exceptions\Procurement\`.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна (все 13 items закрыты, 8 из них 🟠).
- **P3 1/5** 🟡 In progress — P3.1 🟢 (`1fc3b8b`), P3.2 🟠 (`c25bf2e`), P3.3 🟠 (`4e844fe`).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Текущая фаза — **P3**.
- **P3.4** (`Exec=manual`, implementation/high, solo — Inputs ждут фактический периметр P3.2/P3.3,
  теперь готов). Пишет `tests/Unit/Architecture/DomainLayoutTest.php` (I1/I2/I3) по периметру,
  СОБРАННОМУ этим заходом: выровненные слои — `app/Enums/Procurement/**` (P3.2, реестр I3 — 7
  файлов корня + подкаталоги `Crawler/Proxy/Document/Ai/Estimate`), `app/Enums/Llm` (получил
  `FinishReason`), `app/Exceptions/Procurement`/`app/Exceptions/Llm`, `app/Dto/Procurement`,
  `app/Repositories/Procurement` (P3.3, реестры I3 — корни по числам выше: 2/3/3 легальных файла
  каждый). Обязательная красная проба всех трёх проверок (I1/I2/I3) — Implementation Rule 4 P3.4.
  Если владелец пересмотрит домен-пересечение Exceptions (см. Known Deviations P3.3) — реестр I3
  P3.4 обязан отразить финальное решение, не черновик.
- **P3.5** — синхронизация доков/канона по факту P3.2–P3.4.
- **`## Phase Handoff` фазы P2 не собран** («—») — bookkeeping закрытия фазы; носитель —
  `close: task:plan-close 2026.07.31-SYS-INTEGRITY P2`. Фазу P3 не блокирует.
- **Follow-up вне плана (не блокирует P3):** десять pre-existing красных тестов (партиции
  `prc_events`/`llm_calls`, права на запись `resources/**/ai/prompts`, `memory_limit` контейнера,
  EIS-фикстура `DetailAnalysisProcessorTest`, канарейка-счётчик + коллизия имён `Estimate/` в
  `CoreKnowsNoDocumentTypeTest`) — все воспроизводятся на `HEAD` до P3, вне Scope P2/P3.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/04-domain-layout.md` — правило размещения (§2),
  таблица адресов enum'ов (§3), классы A/B (§4), утверждённый гейтом список (§7 + D113).
  **Приоритетнее любых прежних формулировок раскладки.**
- `plans/2026.07.31-SYS-INTEGRITY/plan.md` — §3 Routing (строка P3.4), §4 Status Board, D113,
  Update Log; `phases/P3.md` — ТЗ P3.4–P3.5 и Completion Notes P3.1–P3.3 (фактический периметр —
  единственный источник для реестра I3 P3.4, числа §3 research/04 для P3.2 совпали 40/40, для
  P3.3 таблицы адресов не было — адрес выведен исполнителем P3.3 по правилу §2.1, поимённо в её
  Completion Notes).
- `plans/2026.07.31-SYS-INTEGRITY/open-questions.md` — OQ-8 закрыта D113.

**Open risks:**

- **`app/Filament/Resources/Procurement/**` (и, вероятно, другие контейнер-созданные каталоги)
  несут restrictive ACL (`mask::r-x` для группы) — блокируют `git stash`/полнодеревные
  reset-подобные операции для пользователя `vostrikov`. Точечные `git checkout <rev> -- <path>` и
  прямые правки файлов работают штатно.
- **Домен-пересечение Exceptions (P3.3 Known Deviations)** — реестр I3 P3.4 зависит от того,
  устоит ли решение `BillingExportUnreadable`/`UnknownProviderDriver` → `App\Exceptions\Llm\`.
- Незакоммиченный чужой диф в дереве (не тронут): `M .env.example`, `M CLAUDE.md` · untracked
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`, `.swissknife.json`,
  `.swissknife/`, `.swissknifeman/`. Состав снимать `git status --porcelain` перед каждым
  item-коммитом; `git add -A` запрещён; `pint --dirty` без `--test` (автофикс) на всё дерево тоже
  может задеть untracked чужой диф форматированием — после автофикса сверять чужие пути отдельно.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — `docker-compose.yml:180` требует его через `${...:?}`;
  обход — `docker exec agelto_app …` вместо `docker compose exec …`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** `docker exec agelto_app …` вместо `docker compose exec …`; `git add -p` для
  файлов с посторонним дифом (`CLAUDE.md`); `git checkout <rev> -- <path>` вместо `git stash`/
  `reset --hard` в дереве с ACL-каталогами; `pint --dirty` (автофикс) с хоста — сверять untracked
  чужой диф после прогона.
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close P2`); слои класса B и не утверждённые
  D113 слои класса A (`Services/Procurement`, `Services/Llm`, `Attributes/Ai`) — отдельный план
  (OQ-8 п.3); типизированный носитель семантики кейсов `RowType` — OQ-6; переписывание текстов
  промтов сверх дедупликации — OQ-7 (D110); партиционирование `prc_events`/`llm_calls` под даты
  корпуса; follow-up по `CoreKnowsNoDocumentTypeTest` (канарейка + коллизия имён `Estimate/`) —
  вне плана.
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
