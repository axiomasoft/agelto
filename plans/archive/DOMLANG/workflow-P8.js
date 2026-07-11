// DOMLANG · per-phase runner для ФАЗЫ P8 «Lifecycle-map + state machines единым документом».
// Самодостаточный, живёт В ПАПКЕ ПЛАНА (план удалён -> удалён и файл, хвостов нет).
//
// Запуск:
//   Workflow({ scriptPath: 'plans/DOMLANG/workflow-P8.js' })
//
// Зачем отдельный файл, а не общий workflow.js (args:{phase:'P8'}):
//   тот же прецедент, что P6/P7 (владелец попросил явный код-ревью-гейт per-phase). P8 — docs-only
//   (Scope Excluded запрещает править PHP/тесты/миграции), «код-ревью» здесь = fresh-context проверка:
//   (1) КАЖДОЕ ребро stateDiagram анкорено на реальный код-сайт присваивания (D35: у enum'ов нет
//   allowedTransitions() — выдуманное ребро = spec drift класса P6); (2) ProcurementStatus НЕ
//   нарисован transition-графом (D35), а DocumentStatus ссылается на Д1, не дублируется (D34);
//   (3) mermaid парсится (нотация Class.method(), не Class::method()); (4) граница
//   повтор/ревизия/версия совпадает с инвариантом DocumentDownloader; (5) диф docs-only. Лейны
//   моделей — по plan.md §3 (P8.1/P8.2 sonnet/medium impl, opus/high review) — расхождение этого
//   файла с §3 = дефект, как и для workflow-P6.js/workflow-P7.js.

export const meta = {
  name: 'domlang-p8-runner',
  description: 'Фаза P8 DOMLANG (state-machines + entity-lifecycle-map) с код-ревью-гейтом: P8.1 impl+review -> P8.2 impl+review -> phase-review fan (diagram-anchor-verify + lifecycle-conformance)',
  phases: [
    { title: 'P8.1 State Machines', detail: 'docs/03_Dev/state-machines.md — 5 осей, рёбра анкорены на код' },
    { title: 'P8.1 Review', detail: 'каждое ребро диаграммы = реальный код-сайт; ProcurementStatus не FSM (D35); Д1 не дублирован (D34)' },
    { title: 'P8.2 Lifecycle Map', detail: 'docs/03_Dev/entity-lifecycle-map.md — кто создаёт/мутирует, повтор vs ревизия vs версия' },
    { title: 'P8.2 Review', detail: 'write-site анкоры реальны; граница дедупа snapshot_hash ⟂ content_hash верна' },
    { title: 'Phase Review', detail: 'веер: diagram-anchor-verify + lifecycle-conformance независимо' },
  ],
}

const PLAN_ID = 'DOMLANG'
const PHASE = 'P8'

// --- лейны по plan.md §3 --------------------------------------------------------
const SONNET_MED = { model: 'sonnet', effort: 'medium' }
const OPUS_HIGH = { model: 'opus', effort: 'high' }

const ITEMS = [
  { id: 'P8.1', impl: SONNET_MED, review: OPUS_HIGH },
  { id: 'P8.2', impl: SONNET_MED, review: OPUS_HIGH },
]

const COMMIT_RULES = `Дисциплина коммитов: ветка — уже checked out (НЕ создавай/НЕ переключай). Перед коммитом 'git status'/'git diff --stat' — коммить ТОЛЬКО относящееся к шагу. Conventional Commits, русское описание, heredoc. НЕ push, НЕ --no-verify, НЕ amend чужого, НЕ force. Нечего менять — не коммить пусто.`

const PLAN_CANON = `Канон: plans/DOMLANG/plan.md §5 (особенно D34/D35), plans/DOMLANG/findings/P8-status-writers.md (карта писателей + природа осей), docs/03_Dev/document-pipeline.md (Д1 — единственная существующая stateDiagram, DocumentStatus), phases/P8.md. Реальные имена/писатели статусов — только из перечисленных app/Enums/Procurement/*.php, app/Services/Procurement/StatusMappingService.php, app/Services/Procurement/CrawlerRunManager.php, app/Repositories/Procurement/*StoreRepository.php, app/Services/Procurement/DocumentDownloader.php — НЕ выдумывать. У enum'ов НЕТ allowedTransitions(): ребро диаграммы допустимо ТОЛЬКО из подтверждённого код-сайта присваивания.`

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
Ребро диаграммы/строку карты, для которых не находится якорь (код-сайт присваивания/write-site) из Required Reads/findings -> НЕ угадывай: [NEEDS CLARIFICATION] в тексте, Status 🔴 Blocked, escalated=true, обнови handoff.
Закрытие: статус 🟢/🟠, Completion Notes/Pending Work, синхронизируй Phase Status + Status Board plan.md, перезапиши handoff.md целиком (Next — launch-block).
ОДИН коммит на пункт (доки+файлы плана вместе): 'docs(${PLAN_ID}): ${item.id} — <суть>'. ${COMMIT_RULES}`
}

function stateMachinesReviewPrompt() {
  return `Read-only ревью пункта P8.1 плана ${PLAN_ID} свежим контекстом. Ничего не меняй.
Прочитай docs/03_Dev/state-machines.md ПОСЛЕ создания + plans/${PLAN_ID}/plan.md §5 D34/D35 + plans/${PLAN_ID}/findings/P8-status-writers.md + phases/${PHASE}.md (P8.1).
${PLAN_CANON}
Проверь: (1) в файле РОВНО две stateDiagram-v2 — для AnalysisStatus и RunStatus; КАЖДОЕ их ребро подписано методом-писателем и в тексте секции указан его file:line — САМ открой app/Services/Procurement/CrawlerRunManager.php (RunStatus: :40/:48/:104/:112/:131) и app/Services/Procurement/Screening/ScreeningProcessor.php + app/Services/Procurement/Extraction/ExtractionOrchestrator.php + app/Repositories/Procurement/AnalysisStoreRepository.php (AnalysisStatus) и сверь, что каждое нарисованное ребро реально присваивается в указанном месте; ребро без код-сайта = находка CRITICAL (выдуманный переход, класс spec drift). (2) ProcurementStatus НЕ нарисован как transition-граф между кейсами (D35) — он классификация: состояния + isTerminal()/isFailed() + явная строка «значение задаёт StatusMappingService.resolve() по этапу ЕИС, внутренних transition-гардов нет»; нарисованные рёбра Bidding→Evaluation и т.п. = находка CRITICAL. (3) DocumentStatus НЕ перерисован (D34) — 2-3 строки описания + ссылка на Д1 (document-pipeline.md); скопированная/новая stateDiagram для DocumentStatus = находка HIGH (дублирование, дрейф). (4) внутри блоков stateDiagram нет нотации Class::method() (двоеточие ломает парсер) — только Class.method(); :: внутри stateDiagram = находка MED. (5) все кейсы всех пяти enum'ов присутствуют (сверь перечни с §1 findings), 'Announced' НЕ воскрешён (удалён P2.1/D16); отсутствующий кейс или воскрешённый Announced = находка HIGH. (6) PipelineStage/AnalysisStep поданы как «не статус» (ось-маршрут/шаг), не как FSM; ProcessStage не упомянут (не существует). Любое ребро/факт без реального якоря в коде перепроверь САМ грепом/чтением, не верь тексту. Верни findings[]/blocking/summary.`
}

function lifecycleReviewPrompt() {
  return `Read-only ревью пункта P8.2 плана ${PLAN_ID} свежим контекстом. Ничего не меняй.
Прочитай docs/03_Dev/entity-lifecycle-map.md ПОСЛЕ создания + plans/${PLAN_ID}/findings/P8-status-writers.md §5 + phases/${PHASE}.md (P8.2).
${PLAN_CANON}
Проверь: (1) все пять сущностей (Procurement/ProcurementRevision/ProcurementEvent/Document/DocumentVersion) описаны с реальным создателем и мутатором — САМ сверь file:line по app/Repositories/Procurement/ProcurementStoreRepository.php (storeProcurement:16/updateProcurementHead:50/storeRevision:123/storeEvent:161) и app/Repositories/Procurement/DocumentStoreRepository.php (create:35/createVersion:81); выдуманный/неверный write-site = находка HIGH. (2) граница «повтор / новая ревизия / новая версия документа» совпадает с инвариантом app/Services/Procurement/DocumentDownloader.php (:18-80): повтор = совпал хэш → no-op; ревизия = дедуп snapshot_hash (карточка); версия = дедуп content_hash (байты файла); обе оси названы независимыми — искажение границы = находка HIGH. (3) immutable-сущности (prc_revisions/prc_events/prc_document_versions) помечены immutable; события DocumentAdded/DocumentReplaced связаны с версиями. (4) диаграммы Д1/Д2/Д4 и OCDS-mapping не пересказаны, а связаны ссылкой (Scope Excluded); дублирование = находка MED. (5) мёртвые префиксы таблиц (doc_/est_/ai_/crw_/sys_/plt_) в файле отсутствуют — канон prc_* после P6; вхождение = находка MED. Перепроверяй write-site'ы САМ чтением методов. Верни findings[]/blocking/summary с фактическими выводами.`
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
log(`P8 runner: P8.1 (state-machines) impl+review -> P8.2 (entity-lifecycle-map) impl+review -> phase-review fan`)

phase('P8.1 State Machines')
const r81 = await runItemWithReview(ITEMS[0], stateMachinesReviewPrompt, 'P8.1 Review')
if (r81.stopped) return { phase: PHASE, stoppedAt: 'P8.1', ...r81 }

phase('P8.2 Lifecycle Map')
const r82 = await runItemWithReview(ITEMS[1], lifecycleReviewPrompt, 'P8.2 Review')
if (r82.stopped) return { phase: PHASE, stoppedAt: 'P8.2', ...r82 }

// Phase Review — веер: независимый повторный diagram-anchor-verify + lifecycle-conformance свежим контекстом
phase('Phase Review')
const reviews = (await parallel([
  () => safeAgent(stateMachinesReviewPrompt(), { label: 'phase-review:diagrams', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[0].review }),
  () => safeAgent(lifecycleReviewPrompt(), { label: 'phase-review:lifecycle', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[1].review }),
])).filter(Boolean)

const blocking = reviews.filter((r) => r.blocking)
if (blocking.length) {
  const allFindings = blocking.flatMap((r) => r.findings)
  log(`Phase Review blocking (${allFindings.length} находок) -> fix`)
  const fix = await safeAgent(fixPrompt(ITEMS[0], allFindings), { label: 'fix:phase-review', phase: 'Phase Review', schema: IMPL_SCHEMA, ...ITEMS[0].impl })
  const reGate = await safeAgent(stateMachinesReviewPrompt(), { label: 'phase-review:diagrams:re', phase: 'Phase Review', schema: REVIEW_SCHEMA, ...ITEMS[0].review })
  return { phase: PHASE, verdict: reGate && !reGate.blocking ? 'GREEN-after-fix' : 'ATTENTION', reReview: reGate, fix }
}

log(`P8 закрыта: оба пункта Done, diagram-anchor-verify + lifecycle-conformance без blocking.`)
return { phase: PHASE, verdict: 'GREEN', reviews: reviews.map((r) => r.summary) }
