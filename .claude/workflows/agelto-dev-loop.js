export const meta = {
  name: 'agelto-dev-loop',
  description: 'Реализует список backend-срезов agelto последовательно на ОДНОЙ ветке: для каждого среза impl (agelto-slice-builder) → tests-to-green (agelto-test-writer) → review-gate (agelto-reviewer). Уроки overnight-прогона зашиты: один branch на прогон, хард-кап срезов переживает resume, circuit-breaker. Скоуп срезов решает человек и передаёт через args.',
  phases: [
    { title: 'Setup', detail: 'чистое дерево + одна feature-ветка от base (НЕ per-slice ветки)' },
    { title: 'Build', detail: 'по срезу последовательно: impl → tests-to-green → review-gate' },
    { title: 'Verify', detail: 'полный сюит + pint + отчёт' },
  ],
}

// ---------------------------------------------------------------------------
// Вход (args):
//   { branch: 'feat/...', base?: 'main', max_slices?: 3,
//     slices: [ { id, title, domain, goal, surface?, acceptance_criteria, skills? } ] }
// Скоуп (какие срезы и в каком порядке) решает человек до запуска — этот workflow
// исполняет, а не придумывает продуктовый объём.
// ---------------------------------------------------------------------------

const PROJECT_DIR = '/home/vostrikov/projects/agelto/agelto'

// Урок overnight-прогона: хард-кап ДОЛЖЕН пережить resume. args на resume может не
// долететь → дефолт держим литералом В СКРИПТЕ, а не только в args.
const DEFAULT_MAX = 3

const BRANCH = (args && args.branch) || 'feat/dev-loop'
const BASE = (args && args.base) || 'main'
const MAX = (args && args.max_slices) || DEFAULT_MAX
const ALL_SLICES = (args && Array.isArray(args.slices)) ? args.slices : []

// Урок: один branch на весь прогон. Кап применяем ЗДЕСЬ (slice), а не через
// необязательный arg внутри агентов.
const SLICES = ALL_SLICES.slice(0, MAX)

const HARD_GATES = `ЖЁСТКИЕ ГЕЙТЫ agelto (не нарушать):
- PHP/artisan/тесты — 'php artisan test' (php 8.5, MySQL).
- .env не трогать; тестовая БД agelto_testing (MySQL) уже создана; конфиг — в phpunit.xml.
- Проект на Pest (pestphp/pest). Миграции не добавлять (схема полная). Глубокая статика — vendor/bin/phpstan (larastan), опционально.
- НЕ git push, НЕ открывать/мержить PR, НЕ release. НЕ migrate:fresh/drop по чему-либо кроме тестовой БД.
- Все срезы строятся на ОДНОЙ ветке ${BRANCH}. Агенты НЕ создают и не переключают ветки — коммитят в текущую и оставляют дерево чистым.
- Работать строго внутри ${PROJECT_DIR}.`

if (SLICES.length === 0) {
  log('Нет срезов на вход (args.slices пуст) — нечего строить. Передай { branch, base, max_slices, slices: [...] }.')
  return { built: [], note: 'no slices provided' }
}

// ---------------------------------------------------------------------------
phase('Setup')

// Один агент устанавливает ветку: чистое дерево → ветка от base (создать или
// переключиться). Дальше НИКТО ветки не трогает.
await agent(
  `В ${PROJECT_DIR} подготовь рабочую ветку для прогона.
1. 'git status' — если дерево грязное, останови и отчитайся (НЕ затирай чужие правки).
2. 'git fetch' не нужен. Если ветка ${BRANCH} уже существует — 'git checkout ${BRANCH}'. Иначе: 'git checkout ${BASE}' затем 'git checkout -b ${BRANCH}'.
3. Подтверди: текущая ветка = ${BRANCH}, дерево чистое, последний коммит (subject).
НЕ push, НЕ удаляй ветки.
${HARD_GATES}`,
  { label: 'setup-branch', phase: 'Setup' }
)

// ---------------------------------------------------------------------------
phase('Build')

const SLICE_RESULT_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['id', 'status', 'summary', 'assumptions', 'open_questions'],
  properties: {
    id: { type: 'string' },
    status: { type: 'string', enum: ['green', 'deferred', 'failed'] },
    summary: { type: 'string' },
    files_changed: { type: 'array', items: { type: 'string' } },
    tests_added: { type: 'array', items: { type: 'string' } },
    test_output: { type: 'string', description: 'финальная строка php artisan test' },
    assumptions: { type: 'array', items: { type: 'string' }, description: 'решения там, где ТЗ молчало' },
    open_questions: { type: 'array', items: { type: 'string' } },
  },
}

const results = []
let consecutiveFails = 0

for (const slice of SLICES) {
  if (consecutiveFails >= 2) {
    log(`Circuit-breaker: ${consecutiveFails} среза подряд не дошли до зелёного — стоп, иду в Verify/отчёт.`)
    break
  }

  const extraSkills = (slice.skills || []).join(', ') || '(дополнительных нет)'

  // 1) Реализация среза в текущую ветку (agelto-slice-builder преднагружен access-layer скиллами).
  await agent(
    `Реализуй ОДИН backend-срез agelto в текущей ветке ${BRANCH}.

СРЕЗ:
${JSON.stringify(slice, null, 2)}

Дополнительно к преднагруженным скиллам подгрузи и применяй: ${extraSkills}.
Построй тонкий полный путь (route → controller/Action → DTO/Resource → policy при необходимости) по канону access-layer. Тесты НЕ пиши — их пишет следующий агент. Коммить в текущую ветку, оставь дерево чистым.
${HARD_GATES}`,
    { label: `impl:${slice.id}`, phase: 'Build', agentType: 'agelto-slice-builder', effort: 'high' }
  )

  // 2) Тесты до зелёного (или чистая отсрочка). Pest, php artisan test.
  const result = await agent(
    `Ты в текущей ветке ${BRANCH} в ${PROJECT_DIR}; срез уже реализован:
${JSON.stringify(slice, null, 2)}

Напиши Pest-тесты, доказывающие acceptance_criteria, и итерируй 'php artisan test' до ЗЕЛЁНОГО.
- Зелено → закоммить тесты в ${BRANCH}, status='green', заполни test_output финальной строкой.
- Не достиг зелёного честно → НЕ ослабляй тесты: оставь дерево чистым, status='deferred', объясни в summary+open_questions (реализация остаётся в ${BRANCH}).
Дерево всегда оставляй ЧИСТЫМ. Зафиксируй допущения.
${HARD_GATES}`,
    { label: `test:${slice.id}`, phase: 'Build', agentType: 'agelto-test-writer', schema: SLICE_RESULT_SCHEMA, effort: 'high' }
  )

  // 3) Review-gate только по зелёным (agelto-reviewer, read-only, акцент на access-layer).
  let review = null
  if (result && result.status === 'green') {
    consecutiveFails = 0
    review = await agent(
      `Read-only ревью среза "${slice.title}" в ${PROJECT_DIR}, ветка ${BRANCH}. Diff против ${BASE} ('git diff ${BASE}...${BRANCH}'). Проверь канон access-layer (grep ::query/::create/::where/::all/::find по контроллерам домена — пусто), корректность, авторизацию и acceptance_criteria: ${JSON.stringify(slice.acceptance_criteria)}. Прогони pint --test на изменённых файлах и 'php artisan test --filter=${slice.domain || ''}'. Файлы НЕ меняй, дерево чистое.
${HARD_GATES}`,
      { label: `review:${slice.id}`, phase: 'Build', agentType: 'agelto-reviewer', effort: 'high' }
    )
  } else {
    consecutiveFails++
  }

  results.push({ ...(result || { id: slice.id, status: 'failed', summary: 'agent вернул null', assumptions: [], open_questions: [] }), review_findings: review })
}

// ---------------------------------------------------------------------------
phase('Verify')

const verify = await agent(
  `Финальная проверка прогона в ${PROJECT_DIR}, ветка ${BRANCH}:
1. 'git status' — дерево чистое, текущая ветка ${BRANCH}.
2. grep -rnE '::query\\(|::create\\(|::where\\(|::all\\(|::find\\(' по app/Http/Controllers/Api и app/Actions — перечисли оставшиеся попадания (в контроллерах должно быть 0).
3. Полный 'php artisan test' — число/статус.
4. 'vendor/bin/pint --test' — чисто.
Краткий итог: чист ли слой доступа, тесты (число/упало), что осталось. Файлы не меняй, не push.
${HARD_GATES}`,
  { label: 'final-verify', phase: 'Verify', effort: 'high' }
)

const built = results.map((r) => ({ id: r.id, status: r.status }))
log(`Готово: ${built.filter((b) => b.status === 'green').length}/${SLICES.length} зелёных. Кап MAX=${MAX}, ветка ${BRANCH} (off ${BASE}). Ничего не запушено.`)

return { branch: BRANCH, base: BASE, max: MAX, results, verify }
