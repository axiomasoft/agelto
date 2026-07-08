// EST-AI cross-phase milestone gate: fan-out review over multiple already-closed
// phases, adversarial-verify each finding, synthesize a pass/fail gate verdict.
// Use for boundaries that a single-phase review can't see: half-plan checkpoint,
// pre-Old/-deletion gate, final full-project pass.
export const meta = {
  name: 'estai-milestone-review',
  description: 'Cross-phase EST-AI milestone review: fan-out + adversarial verify + synthesis gate verdict',
  phases: [
    { title: 'Fan-out', detail: 'независимые ревьюеры по разным осям над накопленным диффом' },
    { title: 'Adversarial Verify', detail: 'скептик пытается опровергнуть каждую находку' },
    { title: 'Synthesis', detail: 'итоговый вердикт gate: pass/fail' },
  ],
}

// ---------------------------------------------------------------------------
// args: {
//   planId?: 'EST-AI', label: 'half-plan'|'pre-cleanup'|'final',
//   coveredPhases: ['P2','P3',...],           // какие фазы уже закрыты и входят в диф
//   focus: string,                            // что именно проверяем (см. вызовы ниже)
//   gate: bool,                               // true → обязателен явный PASS/FAIL вердикт
//   reviewers?: string[] agentType (дефолт ['agelto-reviewer','agelto-security-review','agelto-db-impact']),
// }
// ---------------------------------------------------------------------------

const PLAN_ID = (args && args.planId) || 'EST-AI'
const LABEL = (args && args.label) || 'milestone'
const COVERED = (args && Array.isArray(args.coveredPhases)) ? args.coveredPhases : []
const FOCUS = (args && args.focus) || 'общая согласованность реализованных фаз с планом и спеками'
const GATE = !!(args && args.gate)
const REVIEWERS = (args && Array.isArray(args.reviewers) && args.reviewers.length)
  ? args.reviewers
  : ['agelto-reviewer', 'agelto-security-review', 'agelto-db-impact']

async function safeAgent(prompt, opts) {
  try {
    return await agent(prompt, opts)
  } catch (e) {
    log(`agent ${(opts && opts.label) || '?'} бросил исключение (${String((e && e.message) || e).slice(0, 160)}) — infra-failed, продолжаю`)
    return null
  }
}

const FINDING_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['findings'],
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object', additionalProperties: false,
        required: ['title', 'severity', 'description'],
        properties: {
          title: { type: 'string' },
          severity: { type: 'string', enum: ['LOW', 'MED', 'HIGH', 'CRITICAL'] },
          file: { type: 'string' },
          description: { type: 'string' },
        },
      },
    },
  },
}

const VERDICT_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['refuted', 'reasoning'],
  properties: { refuted: { type: 'boolean' }, reasoning: { type: 'string' } },
}

const GATE_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['verdict', 'summary', 'blocking_findings'],
  properties: {
    verdict: { type: 'string', enum: ['PASS', 'FAIL'] },
    summary: { type: 'string' },
    blocking_findings: { type: 'array', items: { type: 'string' } },
  },
}

function fanOutPrompt(kind) {
  const scoped = COVERED.length ? `фазы ${COVERED.join(', ')}` : 'все закрытые на текущий момент фазы'
  const focusByKind = kind === 'agelto-security-review' ? 'авторизация, инъекции, секреты/логи, IDOR по всему покрытому объёму'
    : kind === 'agelto-db-impact' ? 'миграции/связи/каскады/ретеншн, согласованность схемы по всем фазам разом'
    : 'канон access-layer, корректность, N+1, честность тестов, соответствие Decision Log плана'
  return `Milestone-ревью "${LABEL}" плана ${PLAN_ID}. Покрытие: ${scoped}. Читай plans/${PLAN_ID}/plan.md (Decision Log, Execution Rules), plans/${PLAN_ID}/phases/{${COVERED.join(',')}}.md (все Completion Notes), сверяй с фактическим кодом (git log/diff/grep). Фокус этого прохода: ${focusByKind}.
Задача этого milestone: ${FOCUS}
Верни findings[] (title/severity/file/description) — только то, что реально проверил, не домысливай.`
}

function verifyPrompt(finding) {
  return `Попробуй ОПРОВЕРГНУТЬ находку milestone-ревью "${LABEL}" плана ${PLAN_ID}:
${JSON.stringify(finding)}
Перепроверь по коду/докам сам. Если не уверен — считай неопровергнутой (refuted=false). Верни refuted + reasoning.`
}

function synthesisPrompt(survivingFindings) {
  const gateInstruction = GATE
    ? `Это GATE-проход: верни verdict=FAIL если осталась хотя бы одна находка severity HIGH/CRITICAL, иначе PASS. FAIL здесь означает «не двигаться дальше по плану, пока не почищено».`
    : `Это НЕ строгий gate — verdict информативный (PASS если нет HIGH/CRITICAL, иначе FAIL), решение по нему принимает человек.`
  return `Синтез milestone-ревью "${LABEL}" плана ${PLAN_ID}. Задача: ${FOCUS}
Находки, пережившие adversarial verify:
${JSON.stringify(survivingFindings, null, 2)}
${gateInstruction}
Верни verdict/summary/blocking_findings (список title тех, что блокируют).`
}

// ---------------------------------------------------------------------------
phase('Fan-out')
const fanOutResults = await parallel(REVIEWERS.map((kind) => () =>
  safeAgent(fanOutPrompt(kind), { phase: 'Fan-out', label: `fanout:${kind}`, agentType: kind, model: 'opus', effort: 'xhigh', schema: FINDING_SCHEMA })
))

const allFindings = fanOutResults.filter(Boolean).flatMap((r) => r.findings || [])
log(`Fan-out: ${allFindings.length} находок от ${fanOutResults.filter(Boolean).length}/${REVIEWERS.length} ревьюеров.`)

// ---------------------------------------------------------------------------
phase('Adversarial Verify')
const verified = await parallel(allFindings.map((f) => () =>
  safeAgent(verifyPrompt(f), { phase: 'Adversarial Verify', label: `verify:${(f.title || '').slice(0, 40)}`, model: 'opus', effort: 'high', schema: VERDICT_SCHEMA })
    .then((v) => ({ finding: f, verdict: v }))
))

const surviving = verified.filter((v) => v.verdict && !v.verdict.refuted).map((v) => v.finding)
log(`Adversarial verify: ${surviving.length}/${allFindings.length} находок пережили опровержение.`)

// ---------------------------------------------------------------------------
phase('Synthesis')
const gateResult = await safeAgent(synthesisPrompt(surviving), { phase: 'Synthesis', label: 'synthesis', model: 'opus', effort: 'xhigh', schema: GATE_SCHEMA })

log(`Milestone "${LABEL}": ${gateResult ? gateResult.verdict : 'UNKNOWN (synthesis infra-failed)'}`)

return { plan: PLAN_ID, label: LABEL, coveredPhases: COVERED, survivingFindings: surviving, gate: gateResult }
