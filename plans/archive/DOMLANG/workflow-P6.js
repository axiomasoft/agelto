// DOMLANG · per-phase runner для ФАЗЫ P6 «Убить spec drift» с код-ревью-гейтом.
// Самодостаточный, живёт В ПАПКЕ ПЛАНА (план удалён -> удалён и файл, хвостов нет).
//
// Запуск:
//   Workflow({ scriptPath: 'plans/DOMLANG/workflow-P6.js' })
//
// Зачем отдельный файл, а не общий workflow.js (args:{phase:'P6'}):
//   владелец попросил ЯВНЫЙ код-ревью-гейт по этой фазе. P6 — docs-only (Scope Excluded
//   запрещает править PHP/тесты/миграции), поэтому «код-ревью» здесь = fresh-context проверка:
//   (1) оба греп-гейта реально зелёные (независимый перепрогон, не на слово);
//   (2) диф docs-only — ни одного файла под app/ database/ tests/;
//   (3) v1-refs и N/A-литералы целы (G-over), дрейф убит (G-under);
//   (4) долг :159 переписан по ADR-0009. Лейны моделей — по plan.md §3 и совпадают с P6 в workflow.js.
//   Расхождение этого файла с §3/D32 = дефект (как и для workflow.js).

export const meta = {
  name: 'domlang-p6-runner',
  description: 'Фаза P6 DOMLANG (kill spec drift) с код-ревью-гейтом: recon -> item-review -> edit -> phase-review fan (gate-verify + doc-conformance)',
  phases: [
    { title: 'Recon', detail: 'P6.1 — пересобрать drift-map под D30+D32 (RENAME/KEEP-v1/N-A)' },
    { title: 'Recon Review', detail: 'сверить карту с грепом: map==grep, 0 маркеров, R/K/N' },
    { title: 'Edit', detail: 'P6.2 — применить карту, оба греп-гейта, долг :159 по ADR-0009' },
    { title: 'Phase Review', detail: 'веер: независимый gate-verify + doc-conformance vs реальный код' },
  ],
}

const PLAN_ID = 'DOMLANG'
const PHASE = 'P6'

// --- лейны по plan.md §3 (совпадают с P6 в workflow.js) -----------------------
const HAIKU_LOW = { model: 'haiku', effort: 'low' }
const SONNET_MED = { model: 'sonnet', effort: 'medium' }
const OPUS_HIGH = { model: 'opus', effort: 'high' }

const ITEMS = [
  { id: 'P6.1', impl: HAIKU_LOW, review: SONNET_MED },
  { id: 'P6.2', impl: SONNET_MED, review: OPUS_HIGH },
]

const COMMIT_RULES = `Дисциплина коммитов: ветка — уже checked out (НЕ создавай/НЕ переключай). Перед коммитом 'git status'/'git diff --stat' — коммить ТОЛЬКО относящееся к шагу. Conventional Commits, русское описание, heredoc. НЕ push, НЕ --no-verify, НЕ amend чужого, НЕ force. Нечего менять — не коммить пусто.`

const PLAN_CANON = `Канон: plans/DOMLANG/plan.md §5 (особенно D29/D30/D31/D32), phases/P6.md. Реальные имена таблиц — database/migrations/** и plans/DOMLANG/findings/P1-actual-names.md, НЕ спеки (их и чинит эта фаза). N/A-категория (D32) заморожена на 4 токенах: doc_prepare/ai_screening/ai_extraction (очереди Horizon) + ai_extractions (гипотетика ADR-0004) — не таблицы, не трогать.`

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
Вхождение вне трёх frozen-категорий (RENAME/KEEP-v1/N-A) -> НЕ угадывай: [NEEDS CLARIFICATION] в карте, Status 🔴 Blocked, escalated=true, обнови handoff.
Закрытие: статус 🟢/🟠, Completion Notes/Pending Work, синхронизируй Phase Status + Status Board plan.md, перезапиши handoff.md целиком (Next — launch-block).
ОДИН коммит на пункт (доки+файлы плана вместе): 'docs(${PLAN_ID}): ${item.id} — <суть>'. ${COMMIT_RULES}`
}

function reconReviewPrompt() {
  return `Read-only ревью пункта P6.1 плана ${PLAN_ID} свежим контекстом. Ничего не меняй.
Прочитай plans/${PLAN_ID}/findings/P6-drift-map.md и plans/${PLAN_ID}/phases/${PHASE}.md (P6.1).
${PLAN_CANON}
Перепрогони САМ и сверь: (1) число строк секции «Префиксы» карты == \`grep -rnoE '\\b(doc_|est_|ai_|crw_|sys_|plt_)[a-z_]+' docs/specs/v2 docs/03_Dev docs-private | grep -v '/history/' | wc -l\` (ожидаемо 208); (2) 0 маркеров [NEEDS CLARIFICATION] в карте; (3) gate-числа: префиксы R+K+N=208, N=14 и РОВНО на токенах doc_prepare/ai_screening/ai_extraction/ai_extractions (D32), колонки R+K=13; (4) каждая KEEP-v1 строка — токен из KEEP-ALWAYS либо явный v1/arrow/ADR-маркер; (5) residue-set (KEEP-v1 ∪ N/A) перечислен явно. Любое расхождение карта↔греп = находка HIGH. Верни findings[]/blocking/summary.`
}

function gateVerifyPrompt() {
  return `Независимая проверка греп-гейтов фазы P6 плана ${PLAN_ID} ПОСЛЕ правок P6.2. Read-only, перепрогони команды сам, не верь Completion Notes.
${PLAN_CANON}
G-under: \`grep -rnoE '\\b(doc_|est_|ai_|crw_|sys_|plt_)[a-z_]+' docs/specs/v2 docs/03_Dev docs-private | grep -v '/history/'\` — КАЖДЫЙ остаток ∈ frozen residue-set (KEEP-v1 ∪ N/A) карты P6-drift-map.md; остаток вне множества = незатёртый дрейф (HIGH). Ожидаемый размер = K(28)+N(14)=42 строки.
G-over: каждый файл:строка:токен residue-set присутствует после правки; пропажа = затёрт v1-ref/queue-имя (HIGH).
Column: \`grep -rnE '\\bsource_status\\b|\\bsource_guid\\b' docs/specs/v2 docs/03_Dev docs-private | grep -v history\` = 0; \`grep -rnE '\\bsource_number\\b' …\` = только docs/specs/v2/01-database.md:178.
Двойной префикс: \`grep -rnE 'prc_prc_|prc_ai_ai_|ref_ref_|org_org_' docs/\` = 0.
Scope: \`git diff --name-only\` — только docs/** и plans/DOMLANG/**; 0 файлов под app/ database/ tests/ = дефект CRITICAL.
Верни findings[]/blocking/summary с фактическими выводами команд.`
}

function docConformancePrompt() {
  return `Финальное doc-conformance ревью фазы P6 («Убить spec drift») плана ${PLAN_ID} ЦЕЛИКОМ. Read-only.
Фокус: смысловая и фактическая согласованность спек с РЕАЛЬНЫМ кодом. Прочитай git diff фазы + docs/03_Dev/adr/0009-job-owns-document-transition.md.
${PLAN_CANON}
Проверь: (1) переименованные имена таблиц реально соответствуют database/migrations/** (не выдуманный prc_-канон); (2) долг docs/specs/v2/02-crawling.md:159 переписан по ADR-0009 — «помечает статус» удалено, owner перехода downloaded->prepared = PrepareDocumentJob, DocumentStatus::Queued не существует, идемпотентность = ShouldBeUnique+status-guard+лиз; (3) v1-пометки не исказили смысл историч. блоков; (4) ни одной смысловой правки спек КРОМЕ :159; (5) column-drift доведён (source_stage/registry_number/registry_guid) без затирания протокольного source_number (01-database.md:178). Верни findings[]/blocking/summary.`
}

function fixPrompt(item, findings) {
  return `Пункт ${item.id} плана ${PLAN_ID} получил blocking-находки:
${JSON.stringify(findings, null, 2)}
Почини СТРОГО в Scope Included пункта (plans/${PLAN_ID}/phases/${PHASE}.md) — не расширяй объём, фаза docs-only. Перепрогони Validation, обнови Completion Notes.
Находка вне Scope -> не чини, зафиксируй Pending Work с обоснованием.
Изменил -> отдельный коммит: 'fix(${PLAN_ID}): ${item.id} — <что по ревью>'. ${COMMIT_RULES}`
}

// --- прогон -------------------------------------------------------------------
log(`P6 runner: recon -> review -> edit -> phase-review fan (gate-verify + doc-conformance)`)

// P6.1 — recon + item-review + опц. fix
phase('Recon')
const p61 = await safeAgent(implPrompt(ITEMS[0]), { label: 'impl:P6.1', phase: 'Recon', schema: IMPL_SCHEMA, ...ITEMS[0].impl })
if (!p61 || p61.escalated || (p61.status !== 'Done' && p61.status !== 'DoneWithDeviations')) {
  log(`P6.1 не закрыт (${p61 ? p61.status : 'infra-failed'}${p61 && p61.escalated ? ', escalated' : ''}) — стоп, фаза дальше не идёт.`)
  return { phase: PHASE, stoppedAt: 'P6.1', impl: p61 }
}
phase('Recon Review')
const rev61 = await safeAgent(reconReviewPrompt(), { label: 'review:P6.1', phase: 'Recon Review', schema: REVIEW_SCHEMA, ...ITEMS[0].review })
if (rev61 && rev61.blocking) {
  log(`P6.1 review blocking -> fix`)
  const fix61 = await safeAgent(fixPrompt(ITEMS[0], rev61.findings), { label: 'fix:P6.1', phase: 'Recon Review', schema: IMPL_SCHEMA, ...ITEMS[0].impl })
  const re61 = await safeAgent(reconReviewPrompt(), { label: 'review:P6.1:re', phase: 'Recon Review', schema: REVIEW_SCHEMA, ...ITEMS[0].review })
  if (re61 && re61.blocking) return { phase: PHASE, stoppedAt: 'P6.1 review', unresolved: re61.findings, fix: fix61 }
}

// P6.2 — edit
phase('Edit')
const p62 = await safeAgent(implPrompt(ITEMS[1]), { label: 'impl:P6.2', phase: 'Edit', schema: IMPL_SCHEMA, ...ITEMS[1].impl })
if (!p62 || p62.escalated || (p62.status !== 'Done' && p62.status !== 'DoneWithDeviations')) {
  log(`P6.2 не закрыт (${p62 ? p62.status : 'infra-failed'}) — стоп.`)
  return { phase: PHASE, stoppedAt: 'P6.2', impl: p62 }
}

// Phase Review — веер: независимый gate-verify + doc-conformance (это и есть код-ревью-гейт)
phase('Phase Review')
const reviews = (await parallel([
  () => safeAgent(gateVerifyPrompt(), { label: 'phase-review:gates', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[1].review }),
  () => safeAgent(docConformancePrompt(), { label: 'phase-review:conformance', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[1].review }),
])).filter(Boolean)

const blocking = reviews.filter((r) => r.blocking)
if (blocking.length) {
  const allFindings = blocking.flatMap((r) => r.findings)
  log(`Phase Review blocking (${allFindings.length} находок) -> fix P6.2`)
  const fix62 = await safeAgent(fixPrompt(ITEMS[1], allFindings), { label: 'fix:P6.2', phase: 'Phase Review', schema: IMPL_SCHEMA, ...ITEMS[1].impl })
  const reGate = await safeAgent(gateVerifyPrompt(), { label: 'phase-review:gates:re', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[1].review })
  return { phase: PHASE, verdict: reGate && !reGate.blocking ? 'GREEN-after-fix' : 'ATTENTION', reReview: reGate, fix: fix62 }
}

log(`P6 закрыта: оба пункта Done, gate-verify + doc-conformance без blocking.`)
return { phase: PHASE, verdict: 'GREEN', reviews: reviews.map((r) => r.summary) }
