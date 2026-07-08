// EST-AI plan-protocol phase runner: exec Pn.1..Pn.N with a review after EVERY item,
// an optional mid-phase cross-item checkpoint, and a multi-agent phase-final review.
// NOT a generated agent-pack template — safe to edit directly.
export const meta = {
  name: 'estai-phase-runner',
  description: 'Execute all items of one EST-AI plan phase (plan-protocol) with per-item review, optional mid-phase checkpoint, and multi-agent phase-final review',
  phases: [
    { title: 'Implement', detail: 'plan-exec-эквивалент по пункту Pn.m' },
    { title: 'Item Review', detail: 'read-only ревью диффа пункта свежим контекстом' },
    { title: 'Fix', detail: 'починка blocking-находок строго в Scope пункта' },
    { title: 'Mid-phase Checkpoint', detail: 'сверка согласованности между уже закрытыми пунктами фазы' },
    { title: 'Phase Review', detail: 'параллельный fan-out ревьюеров по всей фазе целиком' },
  ],
}

// ---------------------------------------------------------------------------
// args: {
//   planId?: 'EST-AI', phase: 'P3', itemCount: 4,
//   riskLevel?: 'LOW'|'MED'|'HIGH' (дефолт MED — лейны см. lane()),
//   reviewers?: string[] agentType для Phase Review (дефолт ['agelto-reviewer']),
//   noDestructiveOps?: bool (P11: implement-агент обязан ОСТАНОВИТЬСЯ перед необратимым
//     действием типа `git rm` и вернуть план/чеклист вместо выполнения),
// }
// ---------------------------------------------------------------------------

const PLAN_ID = (args && args.planId) || 'EST-AI'
const PHASE = args && args.phase
const ITEM_COUNT = (args && args.itemCount) || 0
const RISK = String((args && args.riskLevel) || 'MED').toUpperCase()
const REVIEWERS = (args && Array.isArray(args.reviewers) && args.reviewers.length) ? args.reviewers : ['agelto-reviewer']
const NO_DESTRUCTIVE = !!(args && args.noDestructiveOps)

if (!PHASE || !ITEM_COUNT) {
  log('Нужны args.phase и args.itemCount — нечего исполнять.')
  return { note: 'no phase/itemCount provided' }
}

// Лейны по риску фазы — тот же принцип, что в agelto-dev-loop.js (reviewLane):
// review глубже, чем impl, дефолт MED уже даёт opus/high, не sonnet.
function implLane() {
  return RISK === 'HIGH' ? { model: 'sonnet', effort: 'high' }
    : RISK === 'LOW' ? { model: 'sonnet', effort: 'medium' }
    : { model: 'sonnet', effort: 'medium' }
}
function reviewLane() {
  return RISK === 'HIGH' ? { model: 'opus', effort: 'xhigh' }
    : RISK === 'LOW' ? { model: 'sonnet', effort: 'medium' }
    : { model: 'opus', effort: 'high' }
}
function phaseReviewLane() {
  return RISK === 'HIGH' ? { model: 'opus', effort: 'xhigh' } : { model: 'opus', effort: 'high' }
}

const COMMIT_RULES = `Дисциплина коммитов (обязательна): ветка — та, что уже checked out (НЕ создавай новую, НЕ переключайся). Перед коммитом 'git status'/'git diff --stat' — коммить ТОЛЬКО то, что реально относится к этому шагу. 'git log --oneline -10' — сверь формат (conventional commits, рус. описание, как в истории репо). Коммить через heredoc-сообщение, последняя строка 'Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>'. НЕ push, НЕ --no-verify, НЕ amend чужих коммитов, НЕ force. Если менять нечего (например, чистая эскалация без изменений файлов) — не создавай пустой коммит.`

async function safeAgent(prompt, opts) {
  try {
    return await agent(prompt, opts)
  } catch (e) {
    log(`agent ${(opts && opts.label) || '?'} бросил исключение (${String((e && e.message) || e).slice(0, 160)}) — infra-failed, продолжаю`)
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

function itemId(n) { return `${PHASE}.${n}` }

function implementPrompt(id) {
  const destructiveGuard = NO_DESTRUCTIVE
    ? `\nОСОБЫЙ ГЕЙТ: этот пункт содержит необратимое действие (см. Scope/Code Guidance пункта). НЕ выполняй само необратимое действие (например 'git rm', снос данных) — подготовь всё до него (проверки/чеклист/дифф-превью), явно перечисли, что было бы удалено/изменено, и остановись со статусом, ожидающим человеческого подтверждения. Ничего необратимого без явного 'ok' человека.`
    : ''
  return `Ты исполняешь пункт мастер-плана ${PLAN_ID} строго по plan-protocol (эквивалент /task:plan-exec ${PLAN_ID} ${id}).
Порядок чтения РОВНО такой: 1) plans/${PLAN_ID}/plan.md (Context/Execution Rules/Routing/Status Board) 2) plans/${PLAN_ID}/handoff.md 3) plans/${PLAN_ID}/phases/${PHASE}.md — Phase Context + пункт ${id} целиком 4) Required Reads пункта в указанном порядке. Повторное чтение уже прочитанного — не делай.
Предусловие: статус пункта ⬜/🟡 и фаза-1 терминальна — иначе стоп + отчёт, ничего не меняй.
До работы: статус пункта → 🟡 In progress (в phases/${PHASE}.md И в Phase Status таблице).
Исполнение СТРОГО в рамках Scope Included пункта; идею «улучшить бы заодно» — в Pending Work, не делай. План разошёлся с кодом (файла нет/сигнатура другая) — НЕ чини план сам, эскалируй (blockquote 🔴 ESCALATION в пункте, Status Blocked, обнови handoff, финальная строка ESCALATION-REQUIRED).
Прогони КАЖДУЮ команду из Validation пункта, зафиксируй passed/failed.
Закрытие: статус 🟢 Done (или 🟠 Done with deviations + Known Deviations), заполни Completion Notes/Pending Work, синхронизируй Phase Status фазы и Status Board в plan.md, ПЕРЕЗАПИШИ handoff.md целиком по стандартной схеме (Next/Done/Remaining/Sources of truth/Open risks/Workarounds-Deferred-Open questions).${destructiveGuard}
После закрытия пункта — ОДИН коммит на пункт: код/миграции/тесты + файлы плана (phases/${PHASE}.md, plan.md, handoff.md) вместе, сообщение вида 'feat(EST-AI): ${id} — <суть>' (тип по факту изменений: feat/fix/docs/chore/test). ${COMMIT_RULES}
Верни финальный отчёт по формату plan-exec: Status/Completed/Pending Work/Files Changed/Validation/Known Deviations/Escalation Needed/Committed(sha или '-').`
}

function itemReviewPrompt(id) {
  return `Read-only ревью свежим контекстом пункта ${id} плана ${PLAN_ID}. Прочитай plans/${PLAN_ID}/phases/${PHASE}.md (Completion Notes пункта ${id}) и сверь с 'git diff' актуальных изменений. Канон — CLAUDE.md, .claude/rules/*, docs/02_Workflow/glossary.md. Проверь: корректность, авторизацию, N+1, канон слоёв (тонкий контроллер/Action/Repository), честность тестов (обходки/форс-конфиг — это находка), Validation пункта реально пройден (не поверь на слово Completion Notes — перепроверь сам: тесты/pint). Верни findings[] и blocking=true если есть HIGH/CRITICAL. Ничего не меняй.`
}

function fixPrompt(id, review) {
  return `Пункт ${id} плана ${PLAN_ID} получил blocking-находки ревью:
${JSON.stringify((review && review.findings) || [], null, 2)}
Почини СТРОГО в рамках Scope Included этого пункта (plans/${PLAN_ID}/phases/${PHASE}.md) — не расширяй объём. Прогони Validation заново, обнови Completion Notes/Known Deviations. Если находка на самом деле вне Scope пункта (архитектурный вопрос) — не чини сам, зафиксируй как Pending Work и объясни почему не в Scope.
Если что-то изменил — отдельный коммит поверх 'fix(EST-AI): ${id} — <что почищено по ревью>'. ${COMMIT_RULES}`
}

function midPhaseCheckpointPrompt(doneCount) {
  return `Промежуточная сверка фазы ${PHASE} плана ${PLAN_ID} после ${doneCount} из ${ITEM_COUNT} пунктов. Прочитай Completion Notes уже закрытых пунктов в plans/${PLAN_ID}/phases/${PHASE}.md и код по ним. Ищи: конфликтующие решения между пунктами, дублирующиеся абстракции, разъехавшиеся контракты (например DTO из P2 vs то, что реально потребляет P3), нарушение Decision Log плана. Верни findings[]/blocking тем же форматом, что Item Review.`
}

function phaseReviewPrompt(reviewerKind) {
  const focus = reviewerKind === 'agelto-security-review' ? 'авторизация/инъекции/секреты/утечки в логах'
    : reviewerKind === 'agelto-db-impact' ? 'миграции/связи/scope-ы/каскады, затронутые фазой'
    : reviewerKind === 'general-purpose' ? 'смысловая и фактическая согласованность документации с реальным кодом (для документных фаз)'
    : 'канон access-layer, корректность, N+1, честность тестов'
  return `Финальное ревью фазы ${PHASE} плана ${PLAN_ID} ЦЕЛИКОМ (весь дифф с начала фазы против точки, где фаза началась). Фокус: ${focus}. Прочитай plans/${PLAN_ID}/phases/${PHASE}.md целиком (все Completion Notes всех пунктов) и сверь с фактическим кодом/git diff. Верни findings[]/blocking/summary.`
}

function phaseFixPrompt(blockingFindings) {
  return `Phase Review фазы ${PHASE} плана ${PLAN_ID} вернул blocking-находки (не пойманные на уровне отдельных пунктов):
${JSON.stringify(blockingFindings, null, 2)}
Почини каждую в коде, оставаясь в границах фазы ${PHASE} (не трогай другие фазы). Для каждой находки, если она реально относится к конкретному пункту — обнови его Known Deviations/Pending Work в plans/${PLAN_ID}/phases/${PHASE}.md. Прогони релевантные Validation-команды затронутых пунктов заново.
Закоммить одним коммитом 'fix(EST-AI): ${PHASE} phase-review — <суть>'. ${COMMIT_RULES}`
}

function phaseClosePrompt() {
  return `Фаза ${PHASE} плана ${PLAN_ID}: все пункты закрыты, phase-review пройден. 'git status' — если дерево НЕ чистое (остались несохранённые правки Phase Status/Status Board/handoff.md или что-то ещё), закоммить их отдельно 'docs(EST-AI): close phase ${PHASE}'. Если дерево уже чистое (всё закоммичено по ходу пунктов) — ничего не делать, просто подтвердить это. ${COMMIT_RULES}
Также проверь: в plan.md Phase Index строка фазы ${PHASE} отражает актуальный счётчик Items и статус (🟢 Done, если все пункты 🟢, иначе 🟠 Done with deviations); handoff.md актуален (Next указывает на следующую фазу/пункт). Если расходится — поправь и закоммить вместе.`
}

// ---------------------------------------------------------------------------
phase('Implement')
const itemResults = []

for (let i = 1; i <= ITEM_COUNT; i++) {
  const id = itemId(i)
  const lane = implLane()
  await safeAgent(implementPrompt(id), { phase: 'Implement', label: `exec:${id}`, model: lane.model, effort: lane.effort })
  log(`${id}: impl done`)

  const rl = reviewLane()
  let review = await safeAgent(itemReviewPrompt(id), { phase: 'Item Review', label: `review:${id}`, model: rl.model, effort: rl.effort, schema: REVIEW_SCHEMA })

  if (review && review.blocking) {
    log(`${id}: blocking находки — чиню`)
    await safeAgent(fixPrompt(id, review), { phase: 'Fix', label: `fix:${id}`, model: lane.model, effort: lane.effort })
    review = await safeAgent(itemReviewPrompt(id), { phase: 'Item Review', label: `reverify:${id}`, model: rl.model, effort: rl.effort, schema: REVIEW_SCHEMA })
  }
  itemResults.push({ id, review })
  log(`${id}: review-ok (${i}/${ITEM_COUNT})`)

  const half = Math.ceil(ITEM_COUNT / 2)
  if (ITEM_COUNT >= 4 && i === half) {
    phase('Mid-phase Checkpoint')
    const checkpoint = await safeAgent(midPhaseCheckpointPrompt(i), { phase: 'Mid-phase Checkpoint', label: `checkpoint:${PHASE}`, model: 'opus', effort: 'high', schema: REVIEW_SCHEMA })
    itemResults.push({ id: `${PHASE}:checkpoint@${i}`, review: checkpoint })
    phase('Implement')
  }
}

// ---------------------------------------------------------------------------
phase('Phase Review')
const prl = phaseReviewLane()
const phaseFindings = await parallel(REVIEWERS.map((kind) => () =>
  safeAgent(phaseReviewPrompt(kind), { phase: 'Phase Review', label: `phase-review:${kind}`, agentType: kind, model: prl.model, effort: prl.effort, schema: REVIEW_SCHEMA })
    .then((r) => ({ kind, review: r }))
))

const blockingPhaseFindings = phaseFindings.filter(Boolean).filter((f) => f.review && f.review.blocking)
log(`Фаза ${PHASE}: ${ITEM_COUNT} пунктов, phase-review ${phaseFindings.filter(Boolean).length}/${REVIEWERS.length} вернули, ${blockingPhaseFindings.length} с blocking-находками.`)

if (blockingPhaseFindings.length) {
  phase('Fix')
  const allBlocking = blockingPhaseFindings.flatMap((f) => (f.review.findings || []).map((x) => ({ ...x, reviewer: f.kind })))
  await safeAgent(phaseFixPrompt(allBlocking), { phase: 'Fix', label: `phase-fix:${PHASE}`, model: 'sonnet', effort: 'high' })
  phase('Phase Review')
  const reReview = await parallel(REVIEWERS.map((kind) => () =>
    safeAgent(phaseReviewPrompt(kind), { phase: 'Phase Review', label: `phase-reverify:${kind}`, agentType: kind, model: prl.model, effort: prl.effort, schema: REVIEW_SCHEMA })
      .then((r) => ({ kind, review: r }))
  ))
  const stillBlocking = reReview.filter(Boolean).filter((f) => f.review && f.review.blocking)
  if (stillBlocking.length) {
    log(`Фаза ${PHASE}: ${stillBlocking.length} находок пережили один фикс-проход — фаза закрывается как 🟠 Done with deviations, не 🟢 (человеку виднее один цикл починки не тратить бесконечно).`)
  }
}

// Единый коммит-чекпоинт границы фазы — даже если по ходу пунктов уже всё закоммичено,
// это явная точка отсчёта для milestone-review (`git diff <phase-start>...<phase-end>`).
await safeAgent(phaseClosePrompt(), { phase: 'Phase Review', label: `phase-close:${PHASE}`, model: 'sonnet', effort: 'medium' })

return { plan: PLAN_ID, phase: PHASE, items: itemResults, phaseFindings }
