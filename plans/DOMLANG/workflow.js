// DOMLANG phase runner — самодостаточный, живёт В ПАПКЕ ПЛАНА.
// План закрыт и удалён -> удалён и этот файл. Хвостов в .claude/workflows/ не остаётся.
//
// Запуск:
//   Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phase: 'P1' } })
//   Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phases: ['P1','P2'] } })
//   Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phases: 'all',
//              approvals: { 'P1.3': 'ok', 'P2.1': 'ok' } } })
//
// Отличия от общего .claude/workflows/estai-phase-runner.js (сознательные):
//   1. Лейны НЕ выводятся из riskLevel — они декларированы поимённо по plan.md §3.
//      Общий runner дал бы P1.1 sonnet/medium вместо haiku/low, а P1.2/P9 — вместо opus/xhigh.
//   2. commitScope = DOMLANG (общий runner хардкодит 'EST-AI' в сообщениях коммитов).
//   3. Гейты стоят там, где дифф НЕОБРАТИМ по объёму, а не там, где «страшно»:
//      - (снят D14) hard-гейт P3.1 «миграции не в main» -> теперь preflight-диагностика:
//        владелец подтвердил, что применённых сред нет, edit-in-place безопасен;
//      - phase-гейт ПОСЛЕ P3 (ренейм колонок в 341 файле — глаза человека на git diff,
//        прежде чем P4-P8 начнут на него опираться, а P6 зафиксирует его в доках как канон);
//      - эскалация исполнителя (план разошёлся с кодом).
//      Всё остальное — decision rules, а не усмотрение. Проскочить гейт нельзя.
//   4. Пункты ownerOnly (P9.3 — /code-review ultra) не исполняются агентом вовсе.
//
// Почему НЕ гейт на P1.3/P2.1 (было, снято): Q3 противоречил D5 (решение уже принято),
// а судьба ProcurementStatus::Announced выражается детерминированным правилом (см. DECISION_RULES).

export const meta = {
  name: 'domlang-phase-runner',
  description: 'Прогон фаз плана DOMLANG: per-item impl -> review -> fix, owner-гейты, mid-phase checkpoint, phase-review веером',
  phases: [
    { title: 'Implement', detail: 'plan-exec-эквивалент по пункту Pn.m' },
    { title: 'Item Review', detail: 'read-only ревью диффа пункта свежим контекстом' },
    { title: 'Fix', detail: 'починка blocking-находок строго в Scope пункта' },
    { title: 'Mid-phase Checkpoint', detail: 'сверка согласованности между закрытыми пунктами фазы' },
    { title: 'Phase Review', detail: 'параллельный fan-out ревьюеров по фазе целиком' },
  ],
}

const PLAN_ID = 'DOMLANG'

let ARGS = args
if (typeof ARGS === 'string') {
  try { ARGS = JSON.parse(ARGS) } catch (e) { log(`args пришёл строкой и не распарсился: ${String(e)}`); ARGS = {} }
}
ARGS = ARGS || {}

const APPROVALS = ARGS.approvals || {}

// ---------------------------------------------------------------------------
// Декларация фаз. Источник истины — plans/DOMLANG/plan.md §3 Routing + §5 Decision Log.
// Меняешь Routing в плане -> меняешь здесь. Расхождение = дефект.
// ---------------------------------------------------------------------------
const OPUS_XHIGH = { model: 'opus', effort: 'xhigh' }
const OPUS_HIGH = { model: 'opus', effort: 'high' }
const SONNET_MED = { model: 'sonnet', effort: 'medium' }
const HAIKU_LOW = { model: 'haiku', effort: 'low' }

// Правила решения вместо owner-гейтов: детерминированы и проверяемы, поэтому исполнимы агентом.
// Правило НЕ применимо к фактам, которых нет -> тогда эскалация, а не догадка.
const DECISION_RULES = {
  // Правило ИСПОЛНЕНО на этапе plan-design (2026-07-10): оба условия проверены, решение -> D16.
  // Q2 закрыт там же: «не состоялась» не приходит на уровне этапа вовсе (findings/P2-eis-stages.md §3),
  // конкуренции за одно значение источника нет -> приоритет выбирать не нужно.
  // Исполнителю остаётся ПРОВЕРИТЬ предпосылку и применить, а не выводить решение заново.
  'P2.1': `РЕШЕНИЕ УЖЕ ПРИНЯТО (plan.md D16, обоснование — findings/P2-eis-stages.md). Твоя задача — проверить предпосылку и применить, НЕ выводить заново.
Проверка: 'grep -rn "Announced" app/ database/ tests/ --include=*.php' должен дать РОВНО 3 вхождения — объявление кейса + 2 присваивания, оба внутри database/factories/.
Совпало -> применяй D16: удали case Announced, переведи ProcurementFactory/LotFactory на Bidding, ProcurementFactory.source_status -> 'Подача заявок', добавь PHPDoc-матрицу достижимости.
НЕ совпало (нашлось присваивание вне фабрик, или тест требует кейс по существу) -> предпосылка D16 опровергнута фактом: НИЧЕГО не удаляй, статус 🔴 Blocked, escalated=true, вывод grep'а в escalationReason. Ослаблять/удалять мешающий тест ЗАПРЕЩЕНО.
НЕ трогай Awarded/Unsuccessful: они тоже сейчас недостижимы, но критерий удаления — отсутствие НАЗВАННОГО будущего продюсера, а у них он есть (detail-слой, prc_protocols). См. findings/P2-eis-stages.md §5.`,
}

const PHASES = {
  P1: {
    title: 'Решения, ADR, карта фактических имён',
    phaseReviewers: ['general-purpose'], // документная фаза: кода нет, ревьюим смысл
    items: [
      { id: 'P1.1', impl: HAIKU_LOW, review: SONNET_MED },
      { id: 'P1.2', impl: OPUS_XHIGH, review: OPUS_HIGH },
      { id: 'P1.3', impl: OPUS_XHIGH, review: OPUS_HIGH }, // Q3 закрыт (Resolved → D5), гейт снят
    ],
  },
  P2: {
    title: 'Сиды prc_status_mappings + аудит ProcurementStatus',
    phaseReviewers: ['agelto-reviewer', 'agelto-db-impact'],
    items: [
      { id: 'P2.1', impl: OPUS_HIGH, review: OPUS_HIGH, decisionRule: DECISION_RULES['P2.1'] },
      { id: 'P2.2', impl: SONNET_MED, review: OPUS_HIGH },
    ],
  },
  P3: {
    title: 'Точечный семантический ренейм БД и кода',
    phaseReviewers: ['agelto-db-impact', 'agelto-reviewer'],
    // Единственный phase-гейт плана. Ренейм колонок затрагивает 341 PHP-файл и 99 тестов;
    // phase-review умеет пометить фазу 🟠, но НЕ умеет её откатить. Если P3 закроется 🟠,
    // фазы P4-P8 лягут поверх полусломанного ренейма, а P6 зафиксирует его в доках как канон.
    // Поэтому здесь — глаза человека на 'git diff', прежде чем прогон катится дальше.
    gateAfter: 'P3 переименовал колонки в 341 PHP-файле и 99 тестах. Посмотри `git diff` и статус фазы в Status Board. Фаза закрылась 🟠 Done with deviations -> разберись ДО того, как P4-P8 начнут на неё опираться (P6 зафиксирует ренейм в документации как канон).',
    items: [
      {
        id: 'P3.1', impl: SONNET_MED, review: OPUS_HIGH,
        // D14 (владелец, 2026-07-10): edit-in-place разрешён — проект не развёрнут, применённых сред нет.
        // Прежний hard-гейт («миграции не в main») снят: он защищал от рассинхрона схема↔файл на средах,
        // применивших старую версию файла; таких сред не существует. Проверка осталась ДИАГНОСТИКОЙ.
        // Гейт возвращается, как только появится первая persistent-среда, применившая миграции.
        hardGate: null,
        preflight: "Диагностика (НЕ блокирует): прогони `git cat-file -e origin/main:<файл>` по каждому целевому файлу миграции и зафиксируй результат в Completion Notes. Стратегия — edit-in-place правка create-table-миграций (D14), forward alter-миграции (renameColumn) НЕ заводить. Верификация фазы обязана включать полный `migrate:fresh` + прогон сюита, а не только grep.",
      },
      { id: 'P3.2', impl: SONNET_MED, review: OPUS_XHIGH },
      { id: 'P3.3', impl: SONNET_MED, review: OPUS_HIGH },
    ],
  },
  P4: {
    title: 'Provenance: тонкий срез по конфликтным полям',
    phaseReviewers: ['agelto-db-impact', 'agelto-reviewer'],
    items: [
      // P4.1 может доказать ОТСУТСТВИЕ конфликтов -> P4.2 закрывается ⛔ Skipped by decision.
      { id: 'P4.1', impl: OPUS_HIGH, review: OPUS_HIGH, mayZeroOutRest: true },
      { id: 'P4.2', impl: SONNET_MED, review: OPUS_XHIGH },
    ],
  },
  P5: {
    title: 'Закрытие реальных дефектов пайплайна',
    phaseReviewers: ['agelto-security-review', 'agelto-reviewer'],
    items: [
      { id: 'P5.1', impl: SONNET_MED, review: OPUS_XHIGH }, // идемпотентность, двойной dispatch
      { id: 'P5.2', impl: SONNET_MED, review: OPUS_HIGH },
      { id: 'P5.3', impl: SONNET_MED, review: OPUS_HIGH },
    ],
  },
  P6: {
    title: 'Убить spec drift',
    phaseReviewers: ['general-purpose'],
    items: [
      { id: 'P6.1', impl: HAIKU_LOW, review: SONNET_MED },
      { id: 'P6.2', impl: SONNET_MED, review: OPUS_HIGH },
    ],
  },
  P7: {
    title: 'Глоссарий v2 + OCDS mapping',
    phaseReviewers: ['general-purpose'],
    items: [
      { id: 'P7.1', impl: SONNET_MED, review: OPUS_HIGH },
      { id: 'P7.2', impl: SONNET_MED, review: OPUS_HIGH },
    ],
  },
  P8: {
    title: 'Lifecycle-map и state machines единым документом',
    phaseReviewers: ['general-purpose'],
    items: [
      { id: 'P8.1', impl: SONNET_MED, review: OPUS_HIGH },
      { id: 'P8.2', impl: SONNET_MED, review: OPUS_HIGH },
    ],
  },
  P9: {
    title: 'Финальный cross-phase review',
    phaseReviewers: ['agelto-reviewer', 'agelto-security-review', 'agelto-db-impact'],
    items: [
      { id: 'P9.1', impl: OPUS_XHIGH, review: OPUS_XHIGH },
      { id: 'P9.2', impl: OPUS_XHIGH, review: OPUS_HIGH },
      {
        id: 'P9.3', ownerOnly: true,
        note: '/code-review ultra запускает ВЛАДЕЛЕЦ (user-triggered, billed). Агент не может — D9.',
      },
    ],
  },
}

const PHASE_ORDER = ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9']

function requestedPhases() {
  if (ARGS.phases === 'all') return PHASE_ORDER
  if (Array.isArray(ARGS.phases) && ARGS.phases.length) return ARGS.phases
  if (ARGS.phase) return [ARGS.phase]
  return []
}

const TARGET = requestedPhases()
if (!TARGET.length) {
  log('Нужен args.phase ("P1") либо args.phases (["P1","P2"] или "all"). Нечего исполнять.')
  return { note: 'no phase provided', available: PHASE_ORDER }
}
const unknown = TARGET.filter((p) => !PHASES[p])
if (unknown.length) {
  log(`Неизвестные фазы: ${unknown.join(', ')}. Доступны: ${PHASE_ORDER.join(', ')}`)
  return { note: 'unknown phase', unknown }
}

const COMMIT_RULES = `Дисциплина коммитов (обязательна): ветка — та, что уже checked out (НЕ создавай новую, НЕ переключайся). Перед коммитом 'git status'/'git diff --stat' — коммить ТОЛЬКО то, что относится к этому шагу. 'git log --oneline -10' — сверь формат (conventional commits, русское описание). Коммить через heredoc-сообщение. НЕ push, НЕ --no-verify, НЕ amend чужих коммитов, НЕ force. Менять нечего — не создавай пустой коммит.`

const PLAN_CANON = `Канон проекта: CLAUDE.md, .claude/rules/*, .ai/guidelines/glossary.md. ВАЖНО: до закрытия фазы P6 спеки docs/specs/v2/** — НЕНАДЁЖНЫЙ источник имён таблиц (166 вхождений мёртвых префиксов doc_/est_/ai_/crw_/sys_/plt_). Канон имён — plans/DOMLANG/findings/P1-actual-names.md и сами database/migrations/**. Не доверяй docs/03_Dev/database.md — он тоже в дрейфе.`

async function safeAgent(prompt, opts) {
  try {
    return await agent(prompt, opts)
  } catch (e) {
    log(`agent ${(opts && opts.label) || '?'} упал (${String((e && e.message) || e).slice(0, 160)}) — infra-failed`)
    return null
  }
}

const REVIEW_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['findings', 'blocking'],
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object', additionalProperties: false,
        required: ['severity', 'description'],
        properties: {
          severity: { type: 'string', enum: ['LOW', 'MED', 'HIGH', 'CRITICAL'] },
          file: { type: 'string' },
          description: { type: 'string' },
        },
      },
    },
    blocking: { type: 'boolean', description: 'true если есть хотя бы одна находка HIGH/CRITICAL' },
    summary: { type: 'string' },
  },
}

// Impl возвращает структуру, чтобы runner мог ОТЛИЧИТЬ эскалацию от успеха
// и остановить прогон, а не катиться дальше по сломанному предположению.
const IMPL_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['status', 'escalated', 'summary'],
  properties: {
    status: { type: 'string', enum: ['Done', 'DoneWithDeviations', 'Blocked', 'Skipped'] },
    escalated: { type: 'boolean', description: 'true если сработал протокол эскалации (plan-protocol §10)' },
    escalationReason: { type: 'string' },
    remainingItemsMoot: { type: 'boolean', description: 'true если результат делает остальные пункты фазы беспредметными (напр. P4.1 не нашёл конфликтов)' },
    summary: { type: 'string' },
    committed: { type: 'string' },
  },
}

function implementPrompt(item, phaseKey) {
  const gate = item.hardGate
    ? `\n\nЖЁСТКИЙ ГЕЙТ ПУНКТА (выполни ПЕРВЫМ делом, до любых правок): ${item.hardGate}\nГейт не пройден -> НИЧЕГО не меняй, статус пункта 🔴 Blocked, escalated=true, escalationReason с фактическим выводом команды. Обходить гейт заглушкой/«проверю потом»/ослаблением Validation ЗАПРЕЩЕНО.`
    : ''
  const pre = item.preflight ? `\n\nPREFLIGHT ПУНКТА (выполни первым делом; НЕ блокирует): ${item.preflight}` : ''
  const moot = item.mayZeroOutRest
    ? `\n\nОТРИЦАТЕЛЬНЫЙ РЕЗУЛЬТАТ ЛЕГАЛЕН: если доказано, что конфликтных полей НЕТ — это успех пункта, а не провал. Тогда verdict фиксируется, remainingItemsMoot=true, а следующий пункт фазы закрывается '⛔ Skipped by decision' со ссылкой на ADR-0008. Не выдумывай конфликт, чтобы «было что делать».\nАСИММЕТРИЯ ОШИБКИ (open-questions.md Q4): ложное «конфликты есть» -> provenance материализуется без потребителя, прямое нарушение ADR-0008. Ложное «конфликтов нет» -> дёшево, триггер возврата записан в ADR. При неуверенности склоняйся к «нет» и зафиксируй сомнение в Completion Notes.`
    : ''
  const rule = item.decisionRule ? `\n\n${item.decisionRule}` : ''
  return `Ты исполняешь пункт ${item.id} мастер-плана ${PLAN_ID} строго по plan-protocol (эквивалент /task:plan-exec ${PLAN_ID} ${item.id}).

Порядок чтения РОВНО такой: 1) plans/${PLAN_ID}/plan.md (Context / Execution Rules / Routing / Decision Log / Status Board) 2) plans/${PLAN_ID}/handoff.md 3) plans/${PLAN_ID}/phases/${phaseKey}.md — Phase Context + пункт ${item.id} целиком 4) plans/${PLAN_ID}/findings/P1-audit-verification.md 5) Required Reads пункта в указанном порядке. Уже прочитанное повторно не читай.

Предусловие: статус пункта ⬜/🟡 и предыдущий пункт терминален — иначе стоп + отчёт, ничего не меняй.
До работы: статус пункта -> 🟡 In progress (в phases/${phaseKey}.md И в Phase Status).

Исполнение СТРОГО в рамках Scope Included. Идея «улучшить бы заодно» -> Pending Work, не делай.
План разошёлся с кодом (файла нет / сигнатура другая) -> НЕ чини план сам, эскалируй: blockquote '> 🔴 ESCALATION ${item.id}: …' в пункте, Status 🔴 Blocked, обнови handoff, верни escalated=true.
${PLAN_CANON}${gate}${pre}${rule}${moot}

Прогони КАЖДУЮ команду из Validation пункта, зафиксируй passed/failed. Ослаблять Validation ради зелёного — запрещено; расхождение разбирай до корня и пиши вывод в Completion Notes.

Закрытие: статус 🟢 Done (или 🟠 Done with deviations + Known Deviations), заполни Completion Notes / Pending Work, синхронизируй Phase Status фазы и Status Board в plan.md, ПЕРЕЗАПИШИ handoff.md целиком (Next / Done / Remaining / Sources of truth / Open risks / Workarounds-Deferred-Open questions), Next — в форме launch-block.

После закрытия — ОДИН коммит на пункт: код/миграции/тесты + файлы плана (phases/${phaseKey}.md, plan.md, handoff.md) вместе, сообщение 'feat(${PLAN_ID}): ${item.id} — <суть>' (тип по факту: feat/fix/docs/chore/test). ${COMMIT_RULES}`
}

function itemReviewPrompt(item, phaseKey) {
  return `Read-only ревью свежим контекстом пункта ${item.id} плана ${PLAN_ID}.
Прочитай plans/${PLAN_ID}/phases/${phaseKey}.md (Completion Notes пункта ${item.id}) и сверь с 'git diff' фактических изменений.
${PLAN_CANON}
Проверь: (1) Validation пункта РЕАЛЬНО пройден — не верь Completion Notes на слово, перепрогони сам (тесты / pint); (2) Scope не расширен; (3) корректность, авторизация, N+1, канон слоёв (тонкий контроллер / Action / Repository); (4) честность тестов — подгонка ассертов под код, форс-конфиг, закомментированный тест = находка HIGH; (5) для схемных пунктов — миграции/каскады/индексы; (6) решения Decision Log плана не нарушены (особенно ADR-0006/0007/0008 после P1.2).
Верни findings[] и blocking=true, если есть HIGH/CRITICAL. Ничего не меняй.`
}

function fixPrompt(item, phaseKey, review) {
  return `Пункт ${item.id} плана ${PLAN_ID} получил blocking-находки ревью:
${JSON.stringify((review && review.findings) || [], null, 2)}
Почини СТРОГО в рамках Scope Included этого пункта (plans/${PLAN_ID}/phases/${phaseKey}.md) — не расширяй объём. Прогони Validation заново, обнови Completion Notes / Known Deviations.
Находка на самом деле вне Scope (архитектурный вопрос) -> не чини сам: зафиксируй как Pending Work и объясни, почему не в Scope.
Изменил что-то -> отдельный коммит поверх: 'fix(${PLAN_ID}): ${item.id} — <что почищено по ревью>'. ${COMMIT_RULES}`
}

function midPhaseCheckpointPrompt(phaseKey, doneCount, total) {
  return `Промежуточная сверка фазы ${phaseKey} плана ${PLAN_ID} после ${doneCount} из ${total} пунктов.
Прочитай Completion Notes закрытых пунктов в plans/${PLAN_ID}/phases/${phaseKey}.md и код по ним.
Ищи: конфликтующие решения между пунктами, дублирующиеся абстракции, разъехавшиеся контракты, нарушение Decision Log плана (D1–D9).
Верни findings[] / blocking тем же форматом, что Item Review.`
}

function phaseReviewPrompt(phaseKey, phaseTitle, reviewerKind) {
  const focus = reviewerKind === 'agelto-security-review' ? 'идемпотентность, гонки, двойная постановка в очередь, авторизация, секреты в логах'
    : reviewerKind === 'agelto-db-impact' ? 'миграции, связи, каскады, индексы, затронутые фазой; мёртвые/незаполняемые колонки'
    : reviewerKind === 'general-purpose' ? 'смысловая и фактическая согласованность документации с РЕАЛЬНЫМ кодом; мёртвые имена таблиц; противоречия между глоссарием, ADR и кодом'
    : 'канон access-layer, корректность, N+1, честность тестов'
  return `Финальное ревью фазы ${phaseKey} («${phaseTitle}») плана ${PLAN_ID} ЦЕЛИКОМ — весь дифф с начала фазы.
Фокус: ${focus}.
Прочитай plans/${PLAN_ID}/phases/${phaseKey}.md целиком (все Completion Notes) и сверь с фактическим кодом / git diff.
${PLAN_CANON}
Верни findings[] / blocking / summary.`
}

function phaseFixPrompt(phaseKey, blockingFindings) {
  return `Phase Review фазы ${phaseKey} плана ${PLAN_ID} вернул blocking-находки, не пойманные на уровне отдельных пунктов:
${JSON.stringify(blockingFindings, null, 2)}
Почини каждую, оставаясь в границах фазы ${phaseKey} (другие фазы не трогай). Находка относится к конкретному пункту -> обнови его Known Deviations / Pending Work. Прогони Validation затронутых пунктов заново.
Один коммит: 'fix(${PLAN_ID}): ${phaseKey} phase-review — <суть>'. ${COMMIT_RULES}`
}

function phaseClosePrompt(phaseKey) {
  return `Фаза ${phaseKey} плана ${PLAN_ID}: пункты закрыты, phase-review пройден.
'git status' — дерево НЕ чистое -> закоммить остаток отдельно: 'docs(${PLAN_ID}): close phase ${phaseKey}'. Дерево чистое -> ничего не делать, подтвердить.
Проверь: строка фазы ${phaseKey} в Status Board plan.md отражает фактический счётчик Items и статус (🟢 Done, если все пункты 🟢; иначе 🟠 Done with deviations); handoff.md актуален, Next указывает на следующую фазу.
Прогони линтер: python3 "$SWISSKNIFEMAN/packages/task/scripts/plan-lint.py" plans/${PLAN_ID} — красное почини. Скрипта нет -> пропусти, не блокер.
Расхождение нашёл -> поправь и закоммить вместе. ${COMMIT_RULES}`
}

// ---------------------------------------------------------------------------
const summary = []

for (const phaseKey of TARGET) {
  const spec = PHASES[phaseKey]
  log(`=== Фаза ${phaseKey}: ${spec.title} (${spec.items.length} пунктов) ===`)

  const itemResults = []
  let phaseAborted = null
  let restAreMoot = false

  phase('Implement')

  for (let i = 0; i < spec.items.length; i++) {
    const item = spec.items[i]

    // --- owner-only: агент не исполняет вовсе -------------------------------
    if (item.ownerOnly) {
      log(`${item.id}: ownerOnly — пропущен. ${item.note || ''}`)
      itemResults.push({ id: item.id, skipped: 'ownerOnly', note: item.note })
      continue
    }

    // --- пункт стал беспредметным (P4.1 не нашёл конфликтов) -----------------
    if (restAreMoot) {
      log(`${item.id}: беспредметен по результату предыдущего пункта — оставлен человеку на закрытие ⛔ Skipped by decision`)
      itemResults.push({ id: item.id, skipped: 'moot' })
      continue
    }

    const impl = await safeAgent(implementPrompt(item, phaseKey), {
      phase: 'Implement', label: `exec:${item.id}`,
      model: item.impl.model, effort: item.impl.effort, schema: IMPL_SCHEMA,
    })

    if (impl && impl.escalated) {
      log(`🔴 ${item.id}: ЭСКАЛАЦИЯ — ${impl.escalationReason || impl.summary}`)
      log('Прогон фазы остановлен: план разошёлся с реальностью, чинит plan-design, не исполнитель.')
      itemResults.push({ id: item.id, impl })
      phaseAborted = { at: item.id, reason: 'escalation', detail: impl.escalationReason || impl.summary }
      break
    }
    if (impl && impl.remainingItemsMoot) {
      restAreMoot = true
      log(`${item.id}: результат делает остальные пункты фазы беспредметными.`)
    }
    log(`${item.id}: impl ${impl ? impl.status : 'infra-failed'}`)

    let review = await safeAgent(itemReviewPrompt(item, phaseKey), {
      phase: 'Item Review', label: `review:${item.id}`,
      model: item.review.model, effort: item.review.effort, schema: REVIEW_SCHEMA,
    })

    if (review && review.blocking) {
      log(`${item.id}: blocking-находки — один фикс-проход`)
      phase('Fix')
      await safeAgent(fixPrompt(item, phaseKey, review), {
        phase: 'Fix', label: `fix:${item.id}`, model: item.impl.model, effort: item.impl.effort,
      })
      phase('Item Review')
      review = await safeAgent(itemReviewPrompt(item, phaseKey), {
        phase: 'Item Review', label: `reverify:${item.id}`,
        model: item.review.model, effort: item.review.effort, schema: REVIEW_SCHEMA,
      })
      if (review && review.blocking) {
        log(`${item.id}: находки пережили фикс-проход — пункт закроется 🟠, бесконечный цикл не крутим.`)
      }
      phase('Implement')
    }

    itemResults.push({ id: item.id, impl, review })

    // mid-phase checkpoint только там, где есть что сверять между пунктами
    const half = Math.ceil(spec.items.length / 2)
    if (spec.items.length >= 3 && i + 1 === half) {
      phase('Mid-phase Checkpoint')
      const checkpoint = await safeAgent(midPhaseCheckpointPrompt(phaseKey, i + 1, spec.items.length), {
        phase: 'Mid-phase Checkpoint', label: `checkpoint:${phaseKey}`,
        model: 'opus', effort: 'high', schema: REVIEW_SCHEMA,
      })
      itemResults.push({ id: `${phaseKey}:checkpoint@${i + 1}`, review: checkpoint })
      phase('Implement')
    }
  }

  if (phaseAborted) {
    summary.push({ phase: phaseKey, aborted: phaseAborted, items: itemResults })
    log(`Фаза ${phaseKey} прервана на ${phaseAborted.at} (${phaseAborted.reason}). Последующие фазы НЕ запускаю.`)
    break
  }

  // --- Phase Review: веером, каждый ревьюер под своим углом -----------------
  phase('Phase Review')
  const reviewers = spec.phaseReviewers || ['agelto-reviewer']
  const phaseFindings = await parallel(reviewers.map((kind) => () =>
    safeAgent(phaseReviewPrompt(phaseKey, spec.title, kind), {
      phase: 'Phase Review', label: `phase-review:${kind}`,
      agentType: kind, model: 'opus', effort: 'xhigh', schema: REVIEW_SCHEMA,
    }).then((r) => ({ kind, review: r }))
  ))

  const blocking = phaseFindings.filter(Boolean).filter((f) => f.review && f.review.blocking)
  log(`Фаза ${phaseKey}: phase-review вернули ${phaseFindings.filter(Boolean).length}/${reviewers.length}, с blocking — ${blocking.length}.`)

  if (blocking.length) {
    phase('Fix')
    const all = blocking.flatMap((f) => (f.review.findings || []).map((x) => ({ ...x, reviewer: f.kind })))
    await safeAgent(phaseFixPrompt(phaseKey, all), {
      phase: 'Fix', label: `phase-fix:${phaseKey}`, model: 'sonnet', effort: 'high',
    })
    phase('Phase Review')
    const reReview = await parallel(reviewers.map((kind) => () =>
      safeAgent(phaseReviewPrompt(phaseKey, spec.title, kind), {
        phase: 'Phase Review', label: `phase-reverify:${kind}`,
        agentType: kind, model: 'opus', effort: 'xhigh', schema: REVIEW_SCHEMA,
      }).then((r) => ({ kind, review: r }))
    ))
    const still = reReview.filter(Boolean).filter((f) => f.review && f.review.blocking)
    if (still.length) {
      log(`Фаза ${phaseKey}: ${still.length} находок пережили фикс-проход — фаза закрывается 🟠 Done with deviations.`)
    }
  }

  await safeAgent(phaseClosePrompt(phaseKey), {
    phase: 'Phase Review', label: `phase-close:${phaseKey}`, model: 'sonnet', effort: 'medium',
  })

  summary.push({ phase: phaseKey, items: itemResults, phaseFindings })
  log(`=== Фаза ${phaseKey} закрыта ===`)

  // --- phase-гейт: единственная точка, где прогон ждёт человека ---------------
  // Стоит после фазы с необратимым по объёму диффом, а не там, где «страшно».
  const isLast = phaseKey === TARGET[TARGET.length - 1]
  if (spec.gateAfter && !isLast && !APPROVALS[`after:${phaseKey}`]) {
    log(`⛔ PHASE-ГЕЙТ после ${phaseKey}. ${spec.gateAfter}`)
    log(`Прогон остановлен. Посмотри дифф, затем перезапусти с остатка:`)
    const rest = TARGET.slice(TARGET.indexOf(phaseKey) + 1)
    log(`  args = { phases: ${JSON.stringify(rest)}, approvals: { "after:${phaseKey}": "ok" } }`)
    return {
      plan: PLAN_ID,
      ran: summary.map((s) => s.phase),
      stoppedAt: `after:${phaseKey}`,
      reason: spec.gateAfter,
      resumeWith: { phases: rest, approvals: { [`after:${phaseKey}`]: 'ok' } },
      summary,
    }
  }
}

return { plan: PLAN_ID, ran: summary.map((s) => s.phase), summary }
