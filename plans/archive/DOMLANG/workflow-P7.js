// DOMLANG · per-phase runner для ФАЗЫ P7 «Глоссарий v2 + OCDS mapping».
// Самодостаточный, живёт В ПАПКЕ ПЛАНА (план удалён -> удалён и файл, хвостов нет).
//
// Запуск:
//   Workflow({ scriptPath: 'plans/DOMLANG/workflow-P7.js' })
//
// Зачем отдельный файл, а не общий workflow.js (args:{phase:'P7'}):
//   тот же прецедент, что и P6 (владелец попросил явный код-ревью-гейт per-phase). P7 — docs-only
//   (Scope Excluded запрещает править PHP/тесты/миграции/эндпоинт-код), «код-ревью» здесь =
//   fresh-context проверка: (1) 8 терминов глоссария реально анкорены на существующий код/enum/
//   колонку (D33), не выдуманы; (2) quality flag НЕ смешан с review status; (3) OCDS-mapping
//   объявляет себя exchange layer (ADR-0007), а не персистенс; (4) диф docs-only. Лейны моделей —
//   по plan.md §3 (P7.1/P7.2 sonnet/medium impl, opus/high review) — расхождение этого файла с §3
//   = дефект, как и для workflow-P6.js.

export const meta = {
  name: 'domlang-p7-runner',
  description: 'Фаза P7 DOMLANG (глоссарий v2 + OCDS mapping) с код-ревью-гейтом: P7.1 impl+review -> P7.2 impl+review -> phase-review fan (term-anchor-verify + ocds-conformance)',
  phases: [
    { title: 'P7.1 Glossary', detail: 'дополнить оба глоссария 8 терминами по D33' },
    { title: 'P7.1 Review', detail: 'сверить якоря терминов с кодом, quality flag != review status' },
    { title: 'P7.2 OCDS Mapping', detail: 'создать docs/03_Dev/ocds-mapping.md по ADR-0007' },
    { title: 'P7.2 Review', detail: 'сверить mapping-таблицу с реальными колонками' },
    { title: 'Phase Review', detail: 'веер: term-anchor-verify + ocds-conformance независимо' },
  ],
}

const PLAN_ID = 'DOMLANG'
const PHASE = 'P7'

// --- лейны по plan.md §3 (совпадают с P7 в workflow.js) -----------------------
const SONNET_MED = { model: 'sonnet', effort: 'medium' }
const OPUS_HIGH = { model: 'opus', effort: 'high' }

const ITEMS = [
  { id: 'P7.1', impl: SONNET_MED, review: OPUS_HIGH },
  { id: 'P7.2', impl: SONNET_MED, review: OPUS_HIGH },
]

const COMMIT_RULES = `Дисциплина коммитов: ветка — уже checked out (НЕ создавай/НЕ переключай). Перед коммитом 'git status'/'git diff --stat' — коммить ТОЛЬКО относящееся к шагу. Conventional Commits, русское описание, heredoc. НЕ push, НЕ --no-verify, НЕ amend чужого, НЕ force. Нечего менять — не коммить пусто.`

const PLAN_CANON = `Канон: plans/DOMLANG/plan.md §5 (особенно D3/D4/D33), docs/03_Dev/adr/0007-ocds-as-exchange-layer.md, docs/03_Dev/adr/0008-thin-provenance-slice.md, phases/P7.md. Реальные имена таблиц/колонок — database/migrations/** и docs/03_Dev/database.md (канон после P6), НЕ выдумывать. 8 терминов D33: revision/event/projection/provenance/quality flag/notice/registry number/ETP number — каждый анкорен на конкретный класс/enum/колонку, перечисленный в D33 и Required Reads P7.1/P7.2.`

async function safeAgent(prompt, opts) {
  try { return await agent(prompt, opts) }
  catch (e) { log(`agent ${(opts && opts.label) || '?'} упал: ${String((e && e.message) || e).slice(0, 160)}`); return null }
}

const IMPL_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['status', 'escalated', 'summary'],
  properties: {
    status: { type: 'string', enum: ['Done', 'DoneWithDeviations', 'Blocked', 'Skipped'] },
    escalated: { type: 'boolean' },
    escalationReason: { type: 'string' },
    summary: { type: 'string' },
    committed: { type: 'string' },
  },
}

const REVIEW_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['findings', 'blocking', 'summary'],
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
    blocking: { type: 'boolean', description: 'true если есть HIGH/CRITICAL' },
    summary: { type: 'string' },
  },
}

function implPrompt(item) {
  return `Исполни пункт ${item.id} плана ${PLAN_ID} строго по plan-protocol (эквивалент /task:plan-exec ${PLAN_ID} ${item.id}).
Читай РОВНО: 1) plans/${PLAN_ID}/plan.md (Context/Execution Rules/Routing/Decision Log/Status Board) 2) plans/${PLAN_ID}/handoff.md 3) plans/${PLAN_ID}/phases/${PHASE}.md — Phase Context + пункт ${item.id} целиком 4) Required Reads пункта по порядку. Прочитанное не перечитывай.
Предусловие: статус пункта ⬜/🟡 и предыдущий пункт терминален — иначе стоп + отчёт, ничего не меняй.
До работы: статус -> 🟡 In progress (в phases/${PHASE}.md И Phase Status).
СТРОГО в рамках Scope Included. «Улучшить заодно» -> Pending Work. Фаза docs-only: правка ЛЮБОГО app/ database/ tests/ = дефект, стоп.
${PLAN_CANON}
Прогони КАЖДУЮ команду Validation пункта, зафиксируй passed/failed. Ослаблять Validation ради зелёного запрещено — расхождение разбирай до корня.
Термин, для которого не находится якорь (класс/enum/колонка) из Required Reads -> НЕ угадывай: [NEEDS CLARIFICATION] в тексте, Status 🔴 Blocked, escalated=true, обнови handoff.
Закрытие: статус 🟢/🟠, Completion Notes/Pending Work, синхронизируй Phase Status + Status Board plan.md, перезапиши handoff.md целиком (Next — launch-block).
ОДИН коммит на пункт (доки+файлы плана вместе): 'docs(${PLAN_ID}): ${item.id} — <суть>'. ${COMMIT_RULES}`
}

function glossaryReviewPrompt() {
  return `Read-only ревью пункта P7.1 плана ${PLAN_ID} свежим контекстом. Ничего не меняй.
Прочитай .ai/guidelines/glossary.md и docs/02_Workflow/glossary.md ПОСЛЕ правки + plans/${PLAN_ID}/plan.md §5 D33 + phases/${PHASE}.md (P7.1).
${PLAN_CANON}
Проверь по каждому из 8 терминов: (1) revision/event — реально указывают на app/Models/Procurement/ProcurementRevision.php и ProcurementEvent.php (прочитай оба файла и сверь); (2) quality flag описан через app/Enums/Procurement/EstimateValidationStatus.php (Valid/Suspicious/Invalid) И явно отделён от app/Enums/Procurement/ReviewStatus.php (операторское ревью) — смешение этих двух концептов = находка HIGH; (3) projection не заведён как отдельная сущность с фиктивной переменной — описывает отношение prc_procurements к prc_revisions/prc_events со ссылкой на ADR-0007; (4) provenance ссылается на ADR-0008 (thin slice, coarse-lineage), не пересказывает W3C PROV своими словами как новый канон; (5) notice зафиксирован как cross-reference-синоним Procurement, НЕ отдельная строка сущности с собственной семантикой; (6) registry number/ETP number ссылаются на D5, ETP number помечен как forward-provision без продюсера (D13); (7) существующие разделы файлов (Глаголы методов/Нейминг тестов/Изменения терминов/Стадии и статусы) не тронуты вне добавления. Любой термин без реального якоря в коде = находка HIGH (перепроверь сам через grep/чтение файлов, не верь тексту). Верни findings[]/blocking/summary.`
}

function ocdsReviewPrompt() {
  return `Read-only ревью пункта P7.2 плана ${PLAN_ID} свежим контекстом. Ничего не меняй.
Прочитай docs/03_Dev/ocds-mapping.md ПОСЛЕ создания + docs/03_Dev/adr/0007-ocds-as-exchange-layer.md + phases/${PHASE}.md (P7.2).
${PLAN_CANON}
Проверь: (1) файл открывается блоком «экспортный слой, не персистенс» со ссылкой на ADR-0007 в первых ~10 строках; (2) каждое поле в колонке "Внутреннее" таблицы реально существует — сверь САМ по database/migrations/2026_07_04_100011_create_prc_procurements_table.php, ..._100012_create_prc_revisions_table.php, ..._100013_create_prc_events_table.php и docs/03_Dev/database.md; выдуманная колонка = находка CRITICAL; (3) секции planning/contract/implementation явно перечислены как некрытые («нет источника данных»), не пропущены молча; (4) файл не переименовывает внутренние поля под OCDS-нейминг и не описывает код экспортера (Scope Excluded); (5) OCDS-термины (ocid/release/record/compiledRelease/versionedRelease) использованы в значении ADR-0007, без самодеятельного расширения смысла. Верни findings[]/blocking/summary с фактическими выводами проверки файлов.`
}

function fixPrompt(item, findings) {
  return `Пункт ${item.id} плана ${PLAN_ID} получил blocking-находки:
${JSON.stringify(findings, null, 2)}
Почини СТРОГО в Scope Included пункта (plans/${PLAN_ID}/phases/${PHASE}.md) — не расширяй объём, фаза docs-only. Перепрогони Validation, обнови Completion Notes.
Находка вне Scope -> не чини, зафиксируй Pending Work с обоснованием.
Изменил -> отдельный коммит: 'fix(${PLAN_ID}): ${item.id} — <что по ревью>'. ${COMMIT_RULES}`
}

async function runItemWithReview(item, reviewPromptFn, reviewPhaseTitle) {
  const impl = await safeAgent(implPrompt(item), { label: `impl:${item.id}`, phase: `${item.id.replace('.', '')} Impl`, schema: IMPL_SCHEMA, ...item.impl })
  if (!impl || impl.escalated || (impl.status !== 'Done' && impl.status !== 'DoneWithDeviations')) {
    log(`${item.id} не закрыт (${impl ? impl.status : 'infra-failed'}${impl && impl.escalated ? ', escalated' : ''}) — стоп, фаза дальше не идёт.`)
    return { stopped: true, impl }
  }
  let review = await safeAgent(reviewPromptFn(), { label: `review:${item.id}`, phase: reviewPhaseTitle, schema: REVIEW_SCHEMA, ...item.review })
  if (review && review.blocking) {
    log(`${item.id} review blocking -> fix`)
    const fix = await safeAgent(fixPrompt(item, review.findings), { label: `fix:${item.id}`, phase: reviewPhaseTitle, schema: IMPL_SCHEMA, ...item.impl })
    const re = await safeAgent(reviewPromptFn(), { label: `review:${item.id}:re`, phase: reviewPhaseTitle, schema: REVIEW_SCHEMA, ...item.review })
    if (re && re.blocking) return { stopped: true, unresolved: re.findings, fix }
    review = re
  }
  return { stopped: false, impl, review }
}

// --- прогон -------------------------------------------------------------------
log(`P7 runner: P7.1 (glossary) impl+review -> P7.2 (ocds-mapping) impl+review -> phase-review fan`)

phase('P7.1 Glossary')
const r71 = await runItemWithReview(ITEMS[0], glossaryReviewPrompt, 'P7.1 Review')
if (r71.stopped) return { phase: PHASE, stoppedAt: 'P7.1', ...r71 }

phase('P7.2 OCDS Mapping')
const r72 = await runItemWithReview(ITEMS[1], ocdsReviewPrompt, 'P7.2 Review')
if (r72.stopped) return { phase: PHASE, stoppedAt: 'P7.2', ...r72 }

// Phase Review — веер: независимый повторный term-anchor-verify + ocds-conformance свежим контекстом
phase('Phase Review')
const reviews = (await parallel([
  () => safeAgent(glossaryReviewPrompt(), { label: 'phase-review:terms', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[0].review }),
  () => safeAgent(ocdsReviewPrompt(), { label: 'phase-review:ocds', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[1].review }),
])).filter(Boolean)

const blocking = reviews.filter((r) => r.blocking)
if (blocking.length) {
  const allFindings = blocking.flatMap((r) => r.findings)
  log(`Phase Review blocking (${allFindings.length} находок) -> fix`)
  const fix = await safeAgent(fixPrompt(ITEMS[1], allFindings), { label: 'fix:phase-review', phase: 'Phase Review', schema: IMPL_SCHEMA, ...ITEMS[1].impl })
  const reGate = await safeAgent(glossaryReviewPrompt(), { label: 'phase-review:terms:re', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[0].review })
  return { phase: PHASE, verdict: reGate && !reGate.blocking ? 'GREEN-after-fix' : 'ATTENTION', reReview: reGate, fix }
}

log(`P7 закрыта: оба пункта Done, term-anchor-verify + ocds-conformance без blocking.`)
return { phase: PHASE, verdict: 'GREEN', reviews: reviews.map((r) => r.summary) }
