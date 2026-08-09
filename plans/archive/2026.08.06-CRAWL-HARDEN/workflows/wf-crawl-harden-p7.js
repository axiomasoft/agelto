// Полевая копия шаблона 4 (`packages/task/workflows/wf-dev-slice.js`) под фазу P7 плана
// 2026.08.06-CRAWL-HARDEN: срез → impl → tests-to-green (loop с потолком) → risk-адаптивное ревью.
//
// ПОЧЕМУ ЭТОТ СКРИПТ ВООБЩЕ ЕСТЬ (D79, переобоснован D100). Исходное обоснование D79 опиралось на
// независимость тройки P7.1/P7.4/P7.5 — оно БОЛЬШЕ НЕ ДЕЙСТВУЕТ: P7.4 и P7.5 несут `Exec = manual`
// в §3 Routing, а канон §9 прямо запрещает исполнять manual-item командой, и запуск через
// `Workflow({scriptPath})` этот гейт обходит (routing-гейт `ROUTING-BLOCKED` живёт в `plan-exec`).
// D100 вывел оба среза из скрипта в ручные запуски. Скрипт остаётся носителем автономной цепочки
// P7.1 → P7.2 → P7.3: три среза, все `Exec = plan-exec`, все implementation/medium, связаны по
// данным и доводятся до зелёного без вмешательства владельца — ровно та работа, которую скрипт
// делает лучше трёх отдельных сессий.
//
// ГРАНИЦЫ, ВЗЯТЫЕ СОЗНАТЕЛЬНО (research/08-p7-layers-design.md §9):
//  • срезы идут ПОСЛЕДОВАТЕЛЬНО — параллелизм не берётся: закрытие каждого item'а по
//    `plan-protocol` §8 правит одни и те же файлы плана (plan.md, handoff.md, journal.jsonl),
//    и три одновременных писателя дали бы конфликт бухгалтерии вместо ускорения;
//  • порядок P7.1 → P7.2 → P7.3 обязателен и по данным: рендер золота → его потребитель →
//    ссылки на обе страницы;
//  • P7.4 и P7.5 в SLICES НЕ ВХОДЯТ (D100) — оба `Exec = manual`, запускаются вручную
//    (`/task:plan-run 2026.08.06-CRAWL-HARDEN P7.4` и `… P7.5`) ПОСЛЕ этого скрипта;
//    добавлять их обратно нельзя без новой D-записи владельца;
//  • модель берётся ПОСРЕЗОВО из §3 Routing плана (см. modelOf) — общий IMPL_MODEL всё равно не
//    даёт права понизить тир, а guarded-default тира — в сторону строгости;
//  • скрипт НЕ ЗАКРЫВАЕТ items: он доводит срез до зелёного и коммитит дифф среза (шаг 1 §8).
//    Бухгалтерия плана, handoff и plan-lint остаются за `task:plan-close <ID> P7.m`.
export const meta = {
  name: 'crawl-harden-p7',
  description: 'P7.1–P7.3 плана 2026.08.06-CRAWL-HARDEN: витрины трёх слоёв смет и связная навигация (P7.4/P7.5 — вручную, D100)',
  whenToUse: 'Исполнение срезов P7.1–P7.3 после утверждения плана; запускать через scriptPath (НЕ named-workflow — args приходит строкой). P7.4 и P7.5 запускаются вручную ПОСЛЕ него',
  phases: [
    { title: 'Impl', detail: 'реализация среза + тесты', model: 'gpt-5.6-terra' },
    { title: 'Green', detail: 'loop: прогон тестов → фикс, до зелёного или max-iteration', model: 'gpt-5.6-terra' },
    { title: 'Review', detail: 'ревью диффа среза, глубина по риску', model: 'gpt-5.6-sol' },
  ],
}

// ── Конфиг (embedded, НЕ через args-строку) ──────────────────────────────────
// Пути ОТНОСИТЕЛЬНЫЕ от корня репозитория: скрипт запускается как
// `Workflow({scriptPath: "plans/2026.08.06-CRAWL-HARDEN/workflows/wf-crawl-harden-p7.js"})`,
// то есть cwd = корень репозитория. Абсолютный путь машины автора ронял бы прогон в любом
// другом клоне ещё до первого среза (F6 аудита захода 2).
const REPO = '.'
const SPEC = 'plans/2026.08.06-CRAWL-HARDEN/phases/P7.md'
if (REPO.startsWith('/')) throw new Error(`REPO должен быть относительным от корня репозитория: "${REPO}"`)
if (SPEC.startsWith('/')) throw new Error(`SPEC должен быть относительным от корня репозитория: "${SPEC}"`)

// SLICES — один срез = один item фазы. `brief` отсылает к разделу SPEC (агент читает сам),
// `tests` — целевая команда из поля Validation item'а, `risk` — глубина ревью из колонки
// `Review` §3 Routing (у всех items P7 — full ⇒ risk ≠ low), `tier` — Model class/effort оттуда же.
const SLICES = [
  {
    key: 'P7.1',
    tier: 'medium',
    brief: 'Item P7.1 «Витрина содержимого золотой сметы в карточке эталона» — читай раздел ### P7.1 спеки целиком: Scope Included/Excluded, Implementation Rules, Code Guidance, Validation. Ключевое: разбор золота ТОЛЬКО существующими DTO PassportResponse/EstimateRow, гейт пути от выхода за gold_dir, признак золота в списке — из уже читаемого манифеста, рендер содержимого — отдельный класс (его переиспользует P7.2).',
    tests: `cd ${REPO} && docker compose exec -e DB_DATABASE=agelto_test app php artisan test --no-tia --filter='FixtureGoldPage|GoldDocumentReader'`,
    risk: 'high',
    commit: 'feat(admin): витрина содержимого золотой сметы в карточке эталона (2026.08.06-CRAWL-HARDEN P7.1)',
  },
  {
    key: 'P7.2',
    tier: 'medium',
    brief: 'Item P7.2 «Read-only витрина эталонных ответов датасета» — читай раздел ### P7.2 спеки целиком. Ключевое: страницы создания и правки УДАЛЯЮТСЯ (read-only доказывается отсутствием роутов), рендер содержимого — ТОТ ЖЕ класс, что создан в P7.1, второй рендер запрещён.',
    tests: `cd ${REPO} && docker compose exec -e DB_DATABASE=agelto_test app php artisan test --no-tia --filter='DatasetExampleResource'`,
    risk: 'high',
    commit: 'feat(admin): read-only витрина эталонных ответов датасета (2026.08.06-CRAWL-HARDEN P7.2)',
  },
  {
    key: 'P7.3',
    tier: 'medium',
    brief: 'Item P7.3 «Связная навигация по трём слоям» — читай раздел ### P7.3 спеки целиком. Ключевое: связь выражается relation-методом модели, ссылка на эталон — только по структурному FK (строковая конвенция external_id в витрину не переезжает), каждая ссылка покрыта тестом, который её ОТКРЫВАЕТ.',
    tests: `cd ${REPO} && docker compose exec -e DB_DATABASE=agelto_test app php artisan test --no-tia --filter='LayerNavigation|EstimateResource|FixtureResource|DatasetExampleResource'`,
    risk: 'high',
    commit: 'feat(admin): связная навигация по трём слоям смет (2026.08.06-CRAWL-HARDEN P7.3)',
  },
  // P7.4 и P7.5 сюда НЕ ДОБАВЛЯЮТСЯ: `Exec = manual` в §3 Routing, ручные запуски (D100).
]

// GATES — прогоняются после review КАЖДОГО среза; красный гейт останавливает конвейер.
const GATES = [
  `cd ${REPO} && docker compose exec app ./vendor/bin/pint --test — зелено`,
  `cd ${REPO} && docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=1G — без новых ошибок и без новых строк в phpstan-baseline.neon`,
  `cd ${REPO} && docker compose exec -e DB_DATABASE=agelto_test app php artisan test --no-tia --compact — зелено`,
]

const MAX_GREEN_ITERATIONS = 3

// model/effort. Тир среза — из §3 Routing плана: implementation ⇒ gpt-5.6-terra;
// medium/high — effort.
// guarded-optional-args: срез без `tier` не должен молча получить более дешёвый прогон —
// явный дефолт 'high' (typeof-guard), то есть в сторону строгости, а не экономии.
const tierOf = s => (typeof s.tier === 'string' ? s.tier : 'high')
const modelOf = s => ({ model: 'gpt-5.6-terra', effort: tierOf(s) })
const GREEN_MODEL_FOR = s => ({ model: 'gpt-5.6-terra', effort: tierOf(s) })
const riskOf = s => (typeof s.risk === 'string' ? s.risk : 'medium')
const reviewFor = risk => (risk === 'high'
  ? { model: 'gpt-5.6-sol', effort: 'xhigh' }
  : { model: 'gpt-5.6-sol', effort: 'medium' })

// ── Схемы structured-summary ─────────────────────────────────────────────────
const REPORT = {
  type: 'object',
  properties: {
    files_changed: { type: 'array', items: { type: 'string' } },
    commits: { type: 'array', items: { type: 'string' } },
    tests_green: { type: 'boolean', description: 'тесты среза зелёные (exit 0)' },
    validation: { type: 'string', description: 'команды и exit-коды' },
    issues: { type: 'array', items: { type: 'string' } },
    summary: { type: 'string', description: 'суть сделанного, 3-5 строк' },
  },
  required: ['files_changed', 'commits', 'tests_green', 'validation', 'issues', 'summary'],
}

const REVIEW_SCHEMA = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          file: { type: 'string' },
          summary: { type: 'string' },
          failure_scenario: { type: 'string' },
          severity: { type: 'string', enum: ['Blocker', 'Major', 'Minor', 'Nit'] },
        },
        required: ['file', 'summary', 'failure_scenario', 'severity'],
      },
    },
    verdict: { type: 'string', enum: ['APPROVE', 'FIX_REQUIRED'] },
  },
  required: ['findings', 'verdict'],
}

// ── Промпты ──────────────────────────────────────────────────────────────────
const COMMON = [
  `Работай из корня репозитория (cwd, он же \`${REPO}\`); все пути ниже — относительные от него. ТЗ-SSOT: ${SPEC} — прочитай раздел своего среза прежде чем писать код.`,
  `Проектные правила обязательны и приоритетнее общих: ${REPO}/CLAUDE.md (доменная раскладка, канон имён), ${REPO}/.claude/rules/*, скиллы filament, testing-rules, pest-testing. Тесты — только test('<интент по-русски>', fn), без it(); персистентные модели — через фабрики; ни одного реального вызова LLM.`,
  `Дисциплина: минимальный дифф под срез, чужой код не рефакторить попутно; неясность в ТЗ — в issues, не импровизировать. Правила поля Implementation Rules и запреты из Code Guidance своего item'а — обязательны.`,
  `Файлы плана (plans/**) НЕ трогать: закрытие item'а делает отдельная команда, не ты.`,
  `Финальный текст = данные по схеме, не сообщение человеку.`,
].join('\n')

const implPrompt = s => [
  COMMON,
  s.brief,
  `Реализуй срез вместе с тестами. Прогони: ${s.tests} — доведи до зелёного в рамках среза. Тесты проверяют ПОВЕДЕНИЕ из ТЗ; подгонка assertion'ов под фактический выход кода запрещена.`,
  `Не коммить до завершения tests-to-green, независимого review и межсрезовых гейтов.`,
].join('\n\n')

const greenPrompt = (s, prev, iter) => [
  COMMON,
  `Тесты среза «${s.key}» не зелёные (итерация ${iter}/${MAX_GREEN_ITERATIONS}). Прогони ${s.tests}, разбери падения, почини КОД (тесты правь только если тест противоречит ТЗ — тогда фиксируй это в issues).`,
  `Отчёт предыдущей попытки: ${prev.validation}`,
  `Не коммить до независимого review и межсрезовых гейтов.`,
].join('\n\n')

const commitPrompt = s => [
  COMMON,
  `Все проверки, review и гейты среза «${s.key}» прошли. Перед commit выполни git status --short и git diff --name-only.`,
  `Стадж только файлы, изменённые тобой в этом срезе; никогда не используй git add -A/-u. Несвязанные или чужие dirty-байты не трогай и укажи в issues. Файлы plans/** в коммит не включать.`,
  `Создай один commit: ${s.commit}.`,
].join('\n\n')

const reviewPrompt = s => [
  `Read-only ревью среза «${s.key}» свежим контекстом (ты НЕ автор). Работай из корня репозитория (cwd, он же \`${REPO}\`); ТЗ: ${SPEC} (раздел среза). До commit проверь текущий scoped diff через git status --short и git diff; несвязанные/чужие dirty-байты не включай в вердикт.`,
  `Ищи: несоответствие ТЗ (особенно нарушенные Implementation Rules и «Запрещённые shortcuts» item'а), correctness-дефекты с конкретным failure_scenario, подгонку тестов под код. Отдельно проверь проектные оси ревью: ${REPO}/.claude/rules/agelto-reviewer-overrides.md.`,
  `Severity Blocker/Major/Minor/Nit. Verdict FIX_REQUIRED при любом Blocker/Major.`,
  `НИЧЕГО не правь и не коммить. Финальный текст = данные по схеме.`,
].join('\n\n')

const gatePrompt = s => [
  `Прогони гейты после среза «${s.key}» из корня репозитория (cwd): ${GATES.join('; ')}. НИЧЕГО не чини — только факты (exit-коды) и вердикт в issues, если красное. Финальный текст = данные по схеме.`,
].join('\n')

// ── Оркестрация: срезы последовательно, внутри среза — loop-until-green с потолком ──
log(`crawl-harden-p7: ${SLICES.length} срезов, max green-итераций: ${MAX_GREEN_ITERATIONS}`)

// safeAgent — try/catch над **bare** `await agent()` (вне `parallel()`/`pipeline()`, где throw
// уже ловится рантаймом и отдаёт `null`): без обёртки бросок агента роняет весь прогон.
async function safeAgent(prompt, opts) {
  try { return await agent(prompt, opts) } catch (e) { log(`agent error (${opts.label || '?'}): ${e.message || e}`); return null }
}

const done = []
for (const s of SLICES) {
  let res = await safeAgent(implPrompt(s), {
    label: `impl:${s.key}:${s.asOf || ''}`, phase: 'Impl', ...modelOf(s), schema: REPORT,
  })
  if (!res) {
    log(`СТОП: impl:${s.key} без результата`)
    done.push({ key: s.key, impl: null, review: null, stopped: 'impl-missing' })
    break
  }

  // tests-to-green loop — строго ограничен max-iteration (правило README).
  let iter = 0
  while (!res.tests_green && iter < MAX_GREEN_ITERATIONS) {
    iter++
    const fix = await safeAgent(greenPrompt(s, res, iter), {
      label: `green:${s.key}:${s.asOf || ''}#${iter}`, phase: 'Green', ...GREEN_MODEL_FOR(s), schema: REPORT,
    })
    if (!fix) {
      done.push({ key: s.key, impl: res, review: null, stopped: 'green-missing' })
      break
    }
    res = { ...fix, commits: [...(res.commits || []), ...(fix.commits || [])] }
  }
  if (done.find(d => d.key === s.key && d.stopped === 'green-missing')) break
  if (!res.tests_green) {
    log(`СТОП: срез ${s.key} не дошёл до зелёного за ${MAX_GREEN_ITERATIONS} итераций — эскалация в main loop`)
    done.push({ key: s.key, impl: res, review: null, stopped: 'tests-not-green' })
    break
  }

  // Risk-адаптивное review: low пропускает отдельного ревьюера. У всех срезов P7 — Review = full.
  let review = null
  if (riskOf(s) !== 'low') {
    review = await safeAgent(reviewPrompt(s), {
      label: `review:${s.key}:${s.asOf || ''}`, phase: 'Review', ...reviewFor(riskOf(s)), schema: REVIEW_SCHEMA,
    })
    if (!review) {
      log(`СТОП: review:${s.key} без результата`)
      done.push({ key: s.key, impl: res, review: null, stopped: 'review-missing' })
      break
    }
    if (review && review.verdict === 'FIX_REQUIRED') {
      log(`СТОП: review среза ${s.key} = FIX_REQUIRED (${review.findings.length} наход.) — фиксы в main loop, review-фиксы отдельными коммитами`)
      done.push({ key: s.key, impl: res, review, stopped: 'review-fix-required' })
      break
    }
  }

  // Гейты между срезами.
  const gate = GATES.length
    ? await safeAgent(gatePrompt(s), { label: `gate:${s.key}:${s.asOf || ''}`, phase: 'Impl', model: 'gpt-5.6-terra', effort: 'low', schema: REPORT })
    : null
  if (GATES.length && !gate) {
    log(`СТОП: gate:${s.key} без результата`)
    done.push({ key: s.key, impl: res, review, stopped: 'gate-missing' })
    break
  }
  if (gate && gate.issues && gate.issues.length) {
    log(`СТОП: гейт после ${s.key} красный: ${gate.issues.join('; ')}`)
    done.push({ key: s.key, impl: res, review, stopped: 'gate-red', gate })
    break
  }

  const committed = await safeAgent(commitPrompt(s), {
    label: `commit:${s.key}:${s.asOf || ''}`, phase: 'Impl', ...modelOf(s), schema: REPORT,
  })
  if (!committed) {
    log(`СТОП: commit:${s.key} без результата`)
    done.push({ key: s.key, impl: res, review, stopped: 'commit-missing' })
    break
  }
  res = {
    ...res,
    commits: [...(res.commits || []), ...(committed.commits || [])],
    issues: [...(res.issues || []), ...(committed.issues || [])],
  }

  done.push({ key: s.key, impl: res, review, stopped: null })
}

const stopped = done.find(d => d.stopped)
log(`crawl-harden-p7 завершён: ${done.filter(d => !d.stopped).length}/${SLICES.length} срезов чисто${stopped ? `, стоп на ${stopped.key} (${stopped.stopped})` : ''}`)

return {
  slices: done.map(d => ({
    key: d.key, stopped: d.stopped,
    commits: (d.impl && d.impl.commits) || [],
    review_findings: (d.review && d.review.findings) || [],
    issues: (d.impl && d.impl.issues) || [],
  })),
  all_green: done.length === SLICES.length && done.every(d => !d.stopped),
  note: 'Скрипт закрывает срезы кодом и коммитами, но НЕ закрывает items плана: бухгалтерия §8 — за task:plan-close 2026.08.06-CRAWL-HARDEN P7.m по каждому закрытому срезу. Фаза P7 этим скриптом НЕ заканчивается: P7.4 и P7.5 (Exec = manual, D100) запускаются вручную после него.',
}
