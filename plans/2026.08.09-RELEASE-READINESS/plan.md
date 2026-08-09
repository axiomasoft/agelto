# 2026.08.09-RELEASE-READINESS — Флагманская готовность Agelto к первому production-развёртыванию

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | 2026.08.09-RELEASE-READINESS |
| Title | Флагманская готовность Agelto к первому production-развёртыванию |
| Layout | v2 |
| Document Type | Executable Master Plan |
| Authoring Model | frontier |
| Repository | agelto |
| Related Packages | axioma-studio/azguard-core · axioma-studio/azguard-filament · Swissknifeman task 0.10.0 |
| Execution Mode | phase-first · contract-first authoring |
| Target Operator Models | economy → gpt-5.6-luna · implementation → gpt-5.6-terra · frontier → gpt-5.6-sol |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:/home/vostrikov/projects/agelto/agelto |
| Design Passes | 1/1 — owner-directed сокращение D46 сохранено; D52 остаётся machine-current carrier повторного полного cross-phase `finish` для 13 фаз / 75 items после добавления P0.0; D55 принимает текущий design после ремедиации F9–F12, останавливает audit-loop на трёх заходах и открывает execution с P0.0 |
| visibility | private |

## 1. Context

План доводит Agelto от работающего development-дерева до воспроизводимого первого production-
развёртывания на пустом Ubuntu-сервере. Он охватывает Docker/Compose и Makefile, секреты и
локальные private packages, деплой и rollback, Laravel Doctor, свежую установку и Livewire/
Filament assets, закрытую production-документацию, прокси, документный конвейер, Filament UX,
Pest 5/CI и сквозной аудит качества.

Текущий Makefile считается набором требований и примеров, а не неприкасаемым интерфейсом.
Сначала P0.0 материализует общий citation gate, затем P0 сравнивает Agelto с локальными Sova,
Vintera/CMS и Dterema и верифицирует внешние практики через RAG; последующие фазы реализуют только запечатанные решения и сохраняют
провенанс. Код приложения не меняется на авторском проходе new.

Финальное доказательство — не зелёный прогон на уже настроенной машине, а повторяемый путь
из чистого clone: private dependency, секреты, build, миграции, обязательные сиды, создание
администратора, assets, закрытая документация, health/doctor и полный CI-набор.

## 2. Execution Rules

- Повторный cross-phase finish D52 охватывает все 75 items после D48 и остаётся machine-current finish carrier; owner D55 принимает текущий design после ремедиации F9–F12, завершает audit-loop на трёх заходах без четвёртого audit и открывает execution с P0.0.
- Первый execution item — prerequisite P0.0 из D48; P0.1 и остальные citation consumers не стартуют до его terminal state.
- Перед и после plan-work выполнять Task checks из marketplace root в порядке AGENTS.md: lint → state → decisions → status.
- RAG-факты и сравнение чужих репозиториев сохранять в findings в том же ходе; синтез — в research; решения — в decisions.
- Любой внешний best practice получает RAG:✅ с URL и датой либо остаётся [UNVERIFIED]; RAG:— допустим только для фактов этого репозитория.
- Текущие Makefile/Compose — requirements input: совместимость целей сохраняется только после инвентаря и осознанного решения.
- Dev code-only change не должен требовать rebuild PHP-образа; причины rebuild должны быть конечным проверяемым перечнем.
- Production secrets не печатаются, не попадают в Git, image layers, process args или shell history; права и rotation проверяются автоматом.
- Controllers остаются HTTP-only; crawling/parsing/AI — идемпотентные Horizon jobs; SourceTlsTrust и ProxyResolver остаются единственными владельцами своих инвариантов.
- Тесты пишутся как test('<русский intent>', fn), модели создаются фабриками; тестовые DB/media guards не ослабляются.
- Миграции, authz, file extraction, secrets, deployment и public contracts получают Review = full.
- Для новой внешней зависимости или команды сначала проверять официальный источник и совместимость текущих версий Laravel/PHP/Filament/Pest.
- Ни один deploy carrier не применяет разрушительную DB/volume операцию без отдельного owner gate и backup/restore evidence.
- Закрытая документация не живёт в public и не отдаёт HTML/assets без единой authorization policy; path traversal и shared-cache leakage тестируются.
- Каждая фаза синхронизирует затронутые docs; P12 не дописывает задним числом документацию за фазы-источники.
- Завершение плана допускается только после чистого bootstrap rehearsal и production-readiness отчёта с перечислением остаточных рисков.

## 3. Routing

Все строки ниже — финальные post-finish маршруты 75 детализированных items по Tier policy;
owner acceptance D55 открывает execution напрямую с P0.0 без четвёртого `plan-audit design`.

| Batch | Items | Model class/effort | Exec | Review | Почему |
|:--|:--|:--|:--|:--|:--|
| — | P0.0 | implementation/medium | plan-exec | full | отдельный deterministic stdlib citation carrier по запечатанному D48; executable selftest и strict gate без product design |
| — | P0.1 | implementation/high | manual | full | широкий repo inventory по запечатанной схеме; high нужен для cross-layer synthesis, новых product-решений нет |
| — | P0.2 | implementation/high | manual | full | read-only сравнение трёх dirty reference trees требует воспроизводимого evidence snapshot и синтеза |
| — | P0.3 | implementation/high | manual | full | infrastructure RAG уточняет runtime/deploy boundary для следующего Laravel-трека |
| — | P0.4 | implementation/high | manual | full | Laravel/assets/docs/test RAG потребляет P0.3 и задаёт security boundary для P0.5 |
| — | P0.5 | implementation/high | manual | full | domain/security RAG потребляет запечатанные P0.3/P0.4 findings; новых product-решений не принимает |
| — | P0.6 | frontier/high | manual | full | заморозка межфазных contracts и альтернатив — открытые design-решения и высокая цена семантической ошибки |
| — | P1.1 | implementation/medium | plan-exec | full | target taxonomy/help/preflight реализуются по D10/D16 и проверяются детерминированным shell harness |
| — | P1.2 | implementation/high | manual | full | dev Compose меняет общий runtime hotspot и non-TTY agent path; design уже запечатан D16 |
| — | P1.3 | implementation/high | manual | full | production image/service boundary security-critical, но решения запечатаны D17 |
| — | P1.4 | implementation/high | manual | full | frontend supply-chain, Vite HMR и image artifact boundary требуют high без открытого product design |
| — | P1.5 | implementation/high | manual | full | deploy taxonomy и сквозные infra contract gates затрагивают production lifecycle |
| — | P2.1 | implementation/high | manual | full | private dependency lock и Composer/image closure security-critical, но D6/D19 запечатали carrier |
| — | P2.2 | implementation/high | manual | full | file-secret generation/Compose grants меняют production credential boundary по D20 |
| — | P2.3 | implementation/high | manual | full | fail-closed preflight проверяет security/deploy prerequisites без новых product-решений |
| — | P2.4 | implementation/high | manual | full | bootstrap связывает vendor/secrets/image/runtime и требует high для идемпотентности/rollback boundary |
| — | P2.5 | implementation/high | manual | full | production seeds и initial-admin auth mutation security-critical, решения уже запечатаны |
| — | P2.6 | implementation/high | manual | full | first-party Doctor integration и companion image исполняют D21 без открытого product design |
| — | P3.1 | implementation/high | manual | full | constrained runtime diagnosis по запечатанной evidence-схеме; root-cause mismatch эскалирует D22 |
| — | P3.2 | implementation/high | manual | full | Nginx/Composer/image asset boundary cross-layer и correctness-critical, решения запечатаны D22 |
| — | P3.3 | implementation/high | manual | full | readiness/Doctor/worker semantics production-critical, но carrier запечатан D21/D23 |
| — | P3.4 | implementation/high | manual | full | browser dependency, auth state и isolated clean install требуют high без открытого product design |
| — | P3.5 | implementation/high | manual | full | destructive-looking recovery proof требует containment и full review по D22/D25 |
| — | P4.1 | frontier/high | manual | full | финальный reconcile/freeze threat model после terminal P0/P1/P3: открытая цена security-contract ошибки требует frontier |
| — | P4.2 | implementation/high | manual | full | generator/manifest/image supply-chain реализуются по D26; security и immutable artifact требуют high |
| — | P4.3 | implementation/high | manual | full | path containment, MIME и response policy security-critical, но D27 уже запечатал архитектуру |
| — | P4.4 | implementation/high | manual | full | AzGuard/Filament authorization меняет access boundary по D27 без открытого product design |
| — | P4.5 | implementation/high | manual | full | adversarial HTTP/browser/image acceptance подтверждает confidentiality и deploy contract |
| — | P5.1 | frontier/high | manual | full | terminal P0 contract reconcile может supersede settings/egress decisions; открытая цена ошибки требует frontier |
| — | P5.2 | implementation/high | manual | full | dependency/settings schema и long-lived worker freshness реализуются по запечатанному D28 |
| — | P5.3 | implementation/high | manual | full | центральный egress resolver, миграции и health-safe precedence correctness-critical, но D29 запечатал semantics |
| — | P5.4 | implementation/high | manual | full | authorization и secret-safe Filament mutation security-sensitive без открытого product design |
| — | P5.5 | implementation/high | manual | full | финальная no-fallback/health/secret/tooling acceptance требует high и полного review |
| — | P6.1 | frontier/high | manual | full | terminal P0 contract reconcile может supersede format/archive decisions; открытая цена security-contract ошибки требует frontier |
| — | P6.2 | implementation/high | manual | full | typed registry, result and reason migration security-critical, но D30 запечатал contract |
| — | P6.3 | implementation/high | manual | full | hostile archive path/type/resource boundary correctness-critical, но D31 запечатал extraction semantics |
| — | P6.4 | implementation/high | manual | full | schema uniqueness, storage addressing and after-commit job lifecycle требуют high без открытого product design |
| — | P6.5 | implementation/high | manual | full | adversarial integration/retry/concurrency proof проверяет security и idempotency contract целиком |
| — | P6.6 | implementation/medium | plan-exec | full | deterministic contract-test/docs/architecture gate по уже запечатанным D30/D31 |
| — | P7.1 | frontier/high | manual | full | terminal P0/P6 reconcile замораживает taxonomy/response/disposition contract; открытая цена schema/prompt ошибки требует frontier |
| — | P7.2 | implementation/high | manual | full | JSON schema migration/backfill/indexes correctness-critical, но D32–D34 и P7.1 запечатали carrier |
| — | P7.3 | implementation/high | manual | full | structured provider schema/prompt/gold contract public для pipeline, решения уже запечатаны D33 |
| — | P7.4 | implementation/high | manual | full | idempotent Analysis/Document/event/run mutation и after-commit dispatch correctness-critical по D34 |
| — | P7.5 | implementation/medium | plan-exec | light | локальная read-only Filament projection и deterministic indexed filters без authz mutation |
| — | P7.6 | implementation/medium | plan-exec | full | детерминированный contract/full-suite/docs gate по уже запечатанным D32–D34 |
| — | P8.1 | frontier/high | manual | full | terminal P0/P7 reconcile может supersede adapter/applicability contract; открытая цена cross-source ошибки требует frontier |
| — | P8.2 | implementation/high | manual | full | typed platform definition меняет общий runtime/config/Form hotspot, но D35 запечатал identity и fail-closed semantics |
| — | P8.3 | implementation/high | manual | full | migration/backfill/partial indexes и lossless rollback correctness-critical, но D36 запечатал schema contract |
| — | P8.4 | implementation/high | manual | full | repository race, precedence, seed и authorization correctness-critical по D36 без открытого product design |
| — | P8.5 | implementation/medium | plan-exec | full | deterministic contract/architecture/full-suite/docs gate по уже запечатанным D35–D36 |
| — | P9.1 | frontier/high | manual | full | terminal-upstream reconcile и freeze navigation/presentation contracts несут открытый риск cross-phase/cross-repo семантической ошибки |
| — | P9.2 | implementation/high | manual | full | запечатанные D37/D38 реализуются в public package config и application labels; SemVer/package/auth identity требуют high+full |
| — | P9.3 | implementation/high | manual | full | широкий schema/validation/dehydration/security surface, но layout contract уже запечатан D38 |
| — | P9.4 | implementation/high | manual | full | все tables/relation actions и responsive visibility меняются по D38 с обязательной auth/query сохранностью |
| — | P9.5 | implementation/high | manual | full | runtime route/role sweep широк и correctness-sensitive, но domain-changing findings обязаны эскалировать, а не проектироваться здесь |
| — | P9.6 | implementation/high | manual | full | browser authorization/responsive acceptance переиспользует D24 и проверяет security-critical direct URLs без открытого design |
| — | P10.1 | frontier/high | manual | full | terminal-tree baseline и factual Rector branch запечатывают ещё открытый tooling contract до product mutation |
| — | P10.2 | implementation/high | manual | full | широкий security audit применяет D19/D20/D27/D29–D35 к frozen tree; findings read-only, новых product-решений нет |
| — | P10.3 | implementation/high | manual | full | architecture/naming/debt audit классифицирует evidence по D33/D37–D39 без product mutation |
| — | P10.4 | implementation/high | manual | full | migration/transaction/race/queue/config audit correctness-critical, но D23/D31/D34/D36 запечатали инварианты |
| — | P10.5 | implementation/high | manual | full | remediation matrix уже замораживает exact root causes; vertical security/data/architecture fixes требуют high и full review |
| — | P10.6 | implementation/high | manual | full | PHPStan max/no-baseline и local quality/docs gate исполняют D39; final verification high+full, CI topology остаётся P11 |
| — | P11.1 | frontier/high | manual | full | terminal baseline замораживает ещё открытый Pest execution/CI contract по official evidence и D40–D42 |
| — | P11.2 | implementation/high | manual | full | TIA/concurrency/cache performance меняют общий test command hotspot, но selection policy запечатана D40 |
| — | P11.3 | implementation/high | manual | full | DB/filesystem isolation и agent output safety-critical, решения запечатаны D41 |
| — | P11.4 | implementation/high | manual | full | широкий all-domain risk matrix и regression suites correctness-critical без открытого product design |
| — | P11.5 | implementation/high | manual | full | CI credentials/caches/actions/image security меняют supply-chain boundary по D42 |
| — | P11.6 | implementation/high | manual | full | final coverage/performance/flake/live-CI verification требует high+full, но budgets запечатаны D42 |
| — | P12.1 | implementation/high | manual | full | host provisioning/firewall/Docker mutation security-critical, но boundary запечатан D43 |
| — | P12.2 | implementation/high | manual | full | production Git/private-package credentials и exact source identity security-critical по D19/D43 |
| — | P12.3 | implementation/high | manual | full | public ingress/TLS и pre-deploy recovery config/fixture contract security-critical по D43/D44/D53 |
| — | P12.4 | implementation/high | manual | full | first production mutation исполняет frozen D9/D17/D20–D27 без открытого design |
| — | P12.5 | implementation/high | manual | full | post-deploy recurring backup/alert/restore, queue drain и rollback correctness/data-loss critical по D17/D23/D44/D53 |
| — | P12.6 | implementation/high | manual | full | cross-carrier command/docs reconcile требует final verification high, новых product-решений нет |
| — | P12.7 | frontier/xhigh | manual | full | необратимый final READY verdict и cross-phase synthesis требуют frontier/xhigh |

## 5. Decision Log

Источник решений — immutable records в decisions/. Текущие, архивные и proposed views
собираются командой plan-views.py decisions из marketplace root Swissknifeman.

## Audit design — 2026-08-09 (заход 1)

**Вердикт: RED** · заход 1 · `plan-lint` → **0 ERROR / 0 WARN**
(на рабочем дереве поверх `1ab8f053`). Сам Markdown-план структурно зелёный, но первый
execution item не может воспроизвести обязательную Validation, а два production-контракта не
имеют полного исполняемого владельца. До ремедиации execution запрещён.

### Покрытие брифа

| Мысль владельца | Носитель | Итог |
|:--|:--|:--|
| Make/Docker, agent-safe automation, code-only без rebuild, Vite-ready topology | P0.1–P0.4 · P1.1–P1.5 · P11.3 · P12.6 | покрыто |
| Пустой Ubuntu, SSH/Git, clone-to-production, deploy/rollback/backup | P2.4–P2.5 · P12.1–P12.7 · D43–D45 | покрыто; operational gap F3 ниже |
| Private AzGuard в `local/vendors` без вендоринга в основной Git | P2.1 · P2.4 · P12.2 · D6/D19 | покрыто |
| Генерация и безопасная передача secrets, preflight | P2.2–P2.4 · P12.4 · D20 | покрыто; rotation gap F2 ниже |
| Laravel Doctor, clean install, Livewire/Filament asset 404 и `/admin` interaction | P2.6 · P3.1–P3.5 · D21–D25 | покрыто |
| Закрытая production-документация вне `public` | P4.1–P4.5 · D26/D27 | покрыто |
| Proxy profile → default → direct при сохранении `ProxyResolver`/health boundaries | P5.1–P5.5 · D28/D29 | покрыто |
| Флагманское качество, security, transactions/races/queues/migrations/config | P10.1–P10.6 · P11.4–P11.6 · D39–D42 | покрыто |
| Русский Filament UX, status-mapping flexibility и все названные admin routes | P8.1–P8.5 · P9.1–P9.6 · D35–D38 | покрыто |
| Pest 5 fast/TIA, isolation, compact agent output и CI | P11.1–P11.6 · D40–D42 | покрыто |
| Typed formats, safe archives, child records и ordinary dispatch | P0.5 · P6.1–P6.6 · D30/D31 | покрыто |
| Document taxonomy, cardinality, summary, tags и structured response/persistence | P0.5 · P7.1–P7.6 · D32–D34 | покрыто |
| Расширяемые platforms/handlers/types/schemas и contract tests | P6.6 · P7.6 · P8.1–P8.5 · P10 | покрыто |
| Полный clean-room rehearsal и evidence-backed финальный verdict | P12.4–P12.7 · D9/D45 | покрыто |
| Refinements: прежний план уже архивирован; owner сократил design budget | D12 · D46/D47 · `brief/01-refinements.md` | покрыто |

Непокрытых мыслей самого брифа нет. Находки F2/F3 — внутренние противоречия и слепые зоны
выбранного release-readiness дизайна, а не потеря исходного требования.

### Находки

**F1 · Blocker · high · issue · P0.1 · `phases/P0/P0.1.md:12`**

Validation ссылается на `python3 plans/verify-citations.py ... --strict`, но такого файла нет ни
в репозитории, ни в Task runtime, и ни один item не производит его. Фактический прогон завершился
`Errno 2`; при этом citation/citations gate включён в Validation 51 item'а (на рабочем дереве поверх
`1ab8f053`). Это нарушает plan-protocol §7/§14: Validation должна быть конечной и исполняемой, а
DoR не может считаться зелёным на отсутствующем carrier. *Failure scenario:* P0.1 формирует
правильный inventory, но обязательная команда падает до close; P0.2 и вся последующая цепь навсегда
заблокированы либо исполнитель вынужден самовольно пропустить общий gate.

**F2 · Major · high · issue · D20/P2.2/P12.5 · `decisions/D20-file-secret-contract.md:26`,
`phases/P2/P2.2.md:5`, `phases/P2/P2.2.md:10`, `phases/P12/P12.5.md:6`**

D20 требует отдельный staged apply/rollback path для rotation, а Execution Rules обещают
автоматическую проверку rotation (`plan.md:46`). P2.2 создаёт только `.next` и прямо исключает
automatic rotation/apply; P12.5 исключает secret rotation сверх этого staged carrier. Ни один
Deliverable/Validation не применяет и не откатывает смену APP/DB/Redis/MinIO credentials на
работающем stack. Нарушены design-audit checklist 2/4 и plan-protocol §6/§7: consequence решения
не имеет адресата, фазовый контракт расходится с item scope. *Failure scenario:* первый реальный
rotation либо оставляет часть сервисов на старых credentials, либо обрывает доступ без проверенного
rollback, хотя финальный report заявляет исполнение security-contract.

**F3 · Major · high · issue · P12.3 · `phases/P12/P12.3.md:5`,
`phases/P12/P12.3.md:8`, `phases/P12/P12.3.md:12`, `decisions/D44-production-backup-recovery-contract.md:15`**

D44 делает RPO/retention обязательной owner-policy, но P12.3/P12.7 доказывают только разовый backup
и restore. В `Files` нет cron/systemd timer/иного recurring carrier, а Validation не проверяет
расписание, пропущенный/красный запуск или доставку alarm. Та же строка Scope обещает disk alerts,
но не объявляет их carrier и delivery test. Это слепая зона design-audit checklist 2 и разрыв
production-operability контракта. *Failure scenario:* rehearsal проходит на свежем backup, затем
production неделями не создаёт новые recovery sets или молча заполняет диск; утверждённые RPO и
retention нарушены без сигнала, но P12.7 всё ещё может выдать `READY`.

**F4 · Major · high · issue · plan views · `status.md:44`, `phases/P0/P0.1.state.md:5`**

Обязательная project-проверка `plan-views.py status --check` уже до аудита завершилась exit 1:
`status.md` устарел. Контрольная генерация показала drift всех 74 строк items (на рабочем дереве
поверх `1ab8f053`): колонка `Updated` хранит `—`, ожидается `2026-08-09`. При этом plan.md:41
требует зелёный status-check до и после любого plan-work. Нарушены project AGENTS.md Working
discipline и design-audit checklist 5 (структурная полнота/исполняемый DoR). *Failure scenario:*
первый executor честно соблюдает preflight и останавливается ещё до P0.1; исполнитель, который
игнорирует gate, начинает работу по заведомо stale owner-facing view.

После обязательного append `plan-audit` в `journal.jsonl` пост-проверка также останавливается на
`P0.1.state.md`: общий `through.journal_line` вырос с 17 до 18, поэтому все 74 state-проекции,
fresh до аудита, теперь требуют штатной пересборки. Это ожидаемое следствие audit-bookkeeping,
но финальный post-check остаётся красным и потому входит в тот же remediation scope F4.

**F5 · Minor · high · issue · D21 · `decisions/D21-doctor-companion-image.md:15`**

Единственная RAG:✅ decision-запись без ссылки на `findings/` или `research/`: D21 называет
Packagist/GitHub, но не связывает verdict с уже существующими
`findings/P2-vendor-secrets-doctor-recon-2026-08-09.md` и `research/04-P2-execution-design.md`.
Нарушен plan-protocol §16: layer 3 должен явно ссылаться на layer 1/2. *Failure scenario:*
холодный consumer P3/P12 перечитывает D21, но не может по decision record восстановить URL,
applicable version и verification status, поэтому повторяет research или принимает assertion на
веру.

### Проверено без находок

- Brief/refinements покрыты без дыр; таблица выше трассирует все смысловые группы к фазам/items/D#.
- `plan-lint` — 0 ERROR / 0 WARN (рабочее дерево поверх `1ab8f053`); 0 реальных
  `[NEEDS CLARIFICATION:]`, 0 незакрытых checklist-пунктов DoR.
- До audit-journal: `plan-views state --all --check` — 74 fresh views;
  `bundle --all --check` — 74 fresh bundles,
  `decisions --check` — 4 fresh views (на рабочем дереве поверх `1ab8f053`); bundle maximum —
  69,383 estimated tokens у P11.1, ниже frozen 100,000 budget.
- Routing и roadmap покрывают одни и те же 74 items без расхождений model/effort/Exec; solo topology
  обоснована явными terminal Inputs→Deliverables dependencies, owner gates адресованы внешнему
  доступу или необратимому freeze/verdict.
- Design budget `1/1` подтверждён owner D46 и finish D47; roadmap создан последним и не разрешает
  execution до GREEN design-audit.
- У всех superseding Layout v2 decisions есть `## Consequences`; остальные RAG:✅ decisions имеют
  явную layer-1/2 ссылку. Code/git execution evidence в режиме `design` не проверялось по протоколу.

### Что запускать

| Model | Thinking | Context | Суть | Команда |
|:--|:--|:--|:--|:--|
| frontier | high | NEW SESSION — шаг-не-item | Адресно устранить F1–F5, пересобрать derived views/bundles/roadmap и повторить design-audit | `$ task:plan-design 2026.08.09-RELEASE-READINESS finish` |

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-design 2026.08.09-RELEASE-READINESS finish

РЕЖИМ: ремедиация находок plan-audit design (заход 1), НЕ новый design-pass, НЕ execution и НЕ реопен фазы
ВХОД: plans/2026.08.09-RELEASE-READINESS/plan.md → блок `## Audit design — 2026-08-09 (заход 1)`; прочитать ПЕРВЫМ
СКОУП: 1) F1 — назначить существующий исполняемый citation carrier либо отдельный prerequisite и синхронизировать все 51 Validation 2) F2 — дать D20 одному item-носителю полный secret rotation apply/verify/rollback contract 3) F3 — добавить recurring backup/retention и alert carrier с fail-closed проверкой 4) F4 — пересобрать status и все затронутые v2 views/bundles 5) F5 — добавить additive D21 provenance link без переписывания истории
НЕ ТРОГАТЬ: product code и execution statuses; brief/refinements; доказанные RAG findings; D1–D47 задним числом; состав/Scope/Files/Validation прочих items, Routing и roadmap вне строго необходимых следствий F1–F5'
```

## Audit design — 2026-08-09 (заход 2)

**Вердикт: RED** · заход 2 · `plan-lint` → **0 ERROR / 0 WARN — новых от рабочего
дерева: 0** (на рабочем дереве поверх `1ab8f053`). F1–F3 прошлого захода получили
исполняемых владельцев, но final approval по-прежнему невозможен: P12 требует
application-dependent backup/HTTPS proof до первого application deploy, а добавление P0.0
оставило формальный `finish`, D-log и generated views в разных состояниях.

### Покрытие брифа

| Мысль владельца | Носитель | Итог |
|:--|:--|:--|
| Make/Docker, agent-safe automation, code-only без rebuild, Vite | P0.1–P0.4 · P1.1–P1.5 · P11.3 · P12.6 | покрыто |
| Пустой Ubuntu, SSH/Git, clone-to-production, deploy/rollback/backup | P2.4–P2.5 · P12.1–P12.7 · D43–D50 | покрыто; исполнимость порядка нарушена F7 |
| Private AzGuard в `local/vendors` | P2.1 · P2.4 · P12.2 · D6/D19 | покрыто |
| Secrets, preflight и отсутствие утечек | P2.2–P2.4 · P12.4–P12.5 · D20/D49 | покрыто |
| Doctor, fresh install, Livewire/Filament assets и `/admin` | P2.6 · P3.1–P3.5 · D21–D25/D51 | покрыто; provenance incomplete F5-R |
| Закрытая production-документация вне `public` | P4.1–P4.5 · D26/D27 | покрыто |
| Proxy profile → default → direct | P5.1–P5.5 · D28/D29 | покрыто |
| Архивы, formats, child records и typed extension | P6.1–P6.6 · D30/D31 | покрыто |
| Document taxonomy/cardinality/summary/tags/structured pipeline | P7.1–P7.6 · D32–D34 | покрыто |
| Platforms/status mappings и русский Filament UX | P8.1–P9.6 · D35–D38 | покрыто |
| Quality/security/migrations/races/queues/Pest 5/CI | P10.1–P11.6 · D39–D42 | покрыто |
| Clean-room rehearsal и evidence-backed verdict | P12.4–P12.7 · D9/D45 | покрыто |
| Refinements: прежний план архивирован; budget 1/1 | D12 · D46/D47 · `brief/01-refinements.md` | покрыто; trace stale F8 |

Непокрытых мыслей исходного brief/refinements не найдено. F7 — не потеря мысли, а
внутренняя неисполняемость выбранной release-readiness последовательности.

### Находки

**F7 · Blocker · high · issue · P12.3/P12.4 · `phases/P12/P12.3.md:3`,
`phases/P12/P12.3.md:12`, `phases/P12/P12.4.md:3`, `phases/P12/P12.md:5`**

P12.3 должен закрыться до P12.4, но его Validation уже требует зелёные HTTPS `/up`,
Livewire update, application-checkable DB/object/secret restore и реальный scheduled backup.
Именно P12.4 объявлен первым deploy и только там появляются exact app/Doctor artifacts,
secrets, пустые production volumes/DB, migrations, seeds и admin. Это нарушает
plan-protocol §5/§7 и design-audit checklist 2/4: потребляемое P12.3 ещё не произведено,
а обход возможен только через незаявленный временный deploy или synthetic proof, выданный
за production evidence. *Failure scenario:* исполнитель P12.3 либо навсегда блокируется до
несуществующего приложения, либо выполняет часть P12.4 вне Scope; в обоих случаях literal
P12.1→P12.6 rehearsal и `READY` теряют воспроизводимый порядок.

**F6 · Major · high · issue · plan/D-log · `plan.md:18`, `plan.md:59`,
`decisions/D46-owner-design-budget.md:13`, `decisions/D47-cross-phase-finish.md:12`,
`decisions/D48-citation-gate-prerequisite.md:33`, `roadmap.md:3`**

Фактические phase specs, Routing и roadmap содержат 75 items, а Meta и две текущие accepted
записи D46/D47 продолжают утверждать точный объём и финальный cross-phase `finish` для 74.
D48 добавляет 75-й item, но не supersedes count/finish consequence D46/D47 и не фиксирует
новый полный reconcile. Нарушены plan-protocol §4/§5/§16 и design-audit checklist 4/5:
D-log и финальный design-pass не образуют одну непротиворечивую цепочку. *Failure scenario:*
cold-start executor не может определить, прошёл ли P0.0 обязательный final `finish`, а
авторский отчёт может одновременно доказать полноту 74 и запустить roadmap из 75 items.

**F4-R · Major · high · issue · generated views · `status.md:44`,
`phases/P0/P0.0.state.md:5` · PROMOTE-CANDIDATE**

Обязательный входной `plan-views.py status --check` снова завершился exit 1: все item rows
в `status.md` несут stale `Updated: —`. До audit-journal state и bundle views свежи по 75,
но обязательный append текущего audit увеличит journal line и сделает все 75 state- и
bundle-проекций stale. F4 прошлого захода поэтому не закрыт, хотя
handoff утверждает полную remediation. Нарушены project AGENTS.md Plan layout v2 checks и
design-audit checklist 5. *Failure scenario:* первый executor соблюдает preflight и
останавливается до P0.0 либо игнорирует красный owner-facing view. Повторяемость двух
заходов указывает на protocol/tool ordering gap: terminal journal event должен предшествовать
финальной dependency-ordered regeneration views.

**F5-R · Minor · high · issue · D51 · `decisions/D51-d21-provenance-link.md:27`,
`phases/P3/P3.3.md:7`, `phases/P12/P12.4.md:7`**

D51 обещает добавить provenance record в bundles P2.6/P3.3/P12.4/P12.7, но manifest scan
находит D51 только в P2.6; P3.3 и P12.4 продолжают читать load-bearing D21 напрямую без
additive layer-1/2 link, P12.7 также его не объявляет. Нарушены plan-protocol §16 и
design-audit checklist 3: записанное Consequence не материализовано у названных consumers.
*Failure scenario:* cold-start P3/P12 повторяет research либо принимает Doctor version/API
assertion на веру, несмотря на формально закрытую remediation F5.

**F8 · Minor · high · issue · knowledge layer · `research/00-user-intent.md:38`,
`brief/01-refinements.md:3`, `decisions/D12-previous-plan-archived.md:10`**

Owner-intent synthesis всё ещё трассирует refinement о предыдущем плане только в D3,
который superseded; текущий carrier — D12 и уже совершившаяся архивация. Нарушен
plan-protocol §16: layer-2 requirement trace расходится с layer-3 current decision.
*Failure scenario:* cold-start consumer следует D3 и считает архивирование будущим отдельным
действием, хотя owner refinement и D12 утверждают обратное.

### Проверено без находок

- F1 закрыт по дизайну: P0.0 — единственный producer `plans/verify-citations.py`; точная strict
  команда присутствует в 52 specs (P0.0 + 51 downstream consumers).
- F2/F3 получили полных владельцев: D49/P12.5 закрывает staged rotation
  apply/verify/rollback, D50/P12.3 — recurring timer/watchdog/retention/alert contract; F7
  относится к порядку получения real application evidence, а не к отсутствию этих carriers.
- `plan-lint` — 0 ERROR / 0 WARN, baseline `1ab8f053` — 0 новых; 75 item specs,
  75 Routing rows и 75 roadmap rows совпадают без дыр и model/effort/Exec drift.
- `plan-views state --all --check` и `bundle --all --check` — по 75 fresh до audit-journal;
  `decisions --check` — 4 fresh views. Реальных `[NEEDS CLARIFICATION]` нет; quoted grep
  sentinels не считаются unresolved markers.
- Brief непуст, findings/research/decisions присутствуют; кроме F5-R/F8 load-bearing
  provenance восстанавливается с диска. Все объявленные superseding decisions несут
  `## Consequences`; open questions отсутствуют.
- Design budget `1/1` подтверждён owner D46 и Update Log/journal design-pass row;
  roadmap покрывает все items, каждый solo имеет named coupling/risk reason, owner gates
  адресованы external access/policy/freeze/verdict.
- `plan-lint --conflicts` вернул OK, но сообщил, что standalone runtime не нашёл
  `SKILL.ru.md`; поэтому отдельное положительное утверждение о canon↔implementation parity
  не делается. Git/code execution evidence в режиме `design` не проверялось.

### Что запускать

| Model | Thinking | Context | Суть | Команда |
|:--|:--|:--|:--|:--|
| frontier | high | NEW SESSION — шаг-не-item | Адресно устранить F4-R/F5-R/F6–F8, пересобрать v2 views после terminal journal event и повторить design-audit | `$ task:plan-design 2026.08.09-RELEASE-READINESS finish` |

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-design 2026.08.09-RELEASE-READINESS finish

РЕЖИМ: адресная ремедиация plan-audit design (заход 2), НЕ новый product design-pass и НЕ execution
ВХОД: plans/2026.08.09-RELEASE-READINESS/plan.md → блок `## Audit design — 2026-08-09 (заход 2)`; прочитать ПЕРВЫМ
СКОУП: 1) F7 — разорвать P12.3→P12.4 цикл: до first deploy оставить config/fixture gates, а real HTTPS/application/recurring-backup proof назначить post-deploy item и синхронизировать D50/research/phase chain 2) F6 — новой additive D-записью согласовать 75-item count и повторный full finish, обновить Meta/Routing prose без переписывания D1–D51 3) F4-R — append terminal journal до финальной regeneration, затем state→bundle→decisions→status и все checks green 4) F5-R — материализовать D51 у всех четырёх названных bundles/Required Reads 5) F8 — обновить intent trace D3→D12 и повторить fresh design-audit
НЕ ТРОГАТЬ: product code и execution statuses; brief/refinements; доказанные RAG findings; immutable D1–D51; Scope/Files/Validation прочих items и Routing/roadmap вне прямых следствий F4-R/F5-R/F6–F8'
```

## Audit design — 2026-08-09 (заход 3)

**Вердикт: RED** · заход 3 · `plan-lint` → **0 ERROR / 0 WARN — новых от рабочего
дерева: 0** (на рабочем дереве поверх `1ab8f053`). Ремедиация F7 восстановила исполнимый
P12.3→P12.5 порядок, а brief/Routing/roadmap структурно покрыты полностью; final approval
по-прежнему невозможен, потому что обязательные v2 state/status views не byte-fresh, а current
D-view и phase handoff'ы одновременно называют два взаимоисключающих finish-carrier.

### Покрытие брифа

| Мысль владельца | Носитель | Итог |
|:--|:--|:--|
| Make/Docker, agent-safe automation, code-only без rebuild, Vite | P0.1–P0.4 · P1.1–P1.5 · P11.3 · P12.6 | покрыто |
| Пустой Ubuntu, SSH/Git, clone-to-production, deploy/rollback/backup | P2.4–P2.5 · P12.1–P12.7 · D43–D45/D50/D53 | покрыто; порядок real proof исправлен D53 |
| Private AzGuard в `local/vendors` | P2.1 · P2.4 · P12.2 · D6/D19 | покрыто |
| Secrets, preflight, отсутствие утечек и rotation apply/rollback | P2.2–P2.4 · P12.4–P12.5 · D20/D49 | покрыто |
| Doctor, fresh install, Livewire/Filament assets и `/admin` | P2.6 · P3.1–P3.5 · P12.4/P12.7 · D21–D25/D51 | покрыто |
| Закрытая production-документация вне `public` | P4.1–P4.5 · D26/D27 | покрыто |
| Proxy profile → default → direct | P5.1–P5.5 · D28/D29 | покрыто |
| Архивы, formats, child records и typed extension | P6.1–P6.6 · D30/D31 | покрыто |
| Document taxonomy/cardinality/summary/tags/structured pipeline | P7.1–P7.6 · D32–D34 | покрыто |
| Platforms/status mappings и русский Filament UX | P8.1–P9.6 · D35–D38 | покрыто |
| Quality/security/migrations/races/queues/Pest 5/CI | P10.1–P11.6 · D39–D42 | покрыто |
| Clean-room rehearsal и evidence-backed verdict | P12.4–P12.7 · D9/D45/D53 | покрыто |
| Refinements: предыдущий план архивирован; budget 1/1; 75-item finish | D12 · D46/D52 · `brief/01-refinements.md` | покрыто; current-view drift F10/F11 ниже |

Непокрытых мыслей brief/refinements и новой слепой product-зоны не найдено. F9–F12 —
дефекты исполнимости/связности plan-продукта и его generated carriers.

### Находки

**F9 · Blocker · high · issue · v2 projections · `phases/P0/P0.0.state.md:5`,
`status.md:44`, `journal.jsonl:21`, `handoff.md:19`**

Обязательный входной `plan-views.py state --all --check` завершился exit 1 на P0.0, а
`status --check` — exit 1 на `status.md`. Все 75 state-carriers содержат
`through.journal_line: 0`, хотя journal уже имеет 21 строку; воспроизводимый render P0.0 ожидает
`journal_line: 21` и другой `inputs_sha256`. `status.md` оставляет `Updated: —`, тогда как
projector ожидает `2026-08-09`. При этом handoff утверждает, что journal append предшествовал
финальной state→bundle→decisions→status regeneration. Нарушены project AGENTS.md Layout v2
checks, plan-protocol §8 и design-audit checklist 5; утверждение о выполненной regeneration ложно.
*Failure scenario:* первый P0.0 executor соблюдает обязательный byte-fresh state/preflight и
останавливается до mutation; игнорирование гейта запускает план на carrier, не связанном с текущим
spec+journal. Текущий audit append увеличит journal до 22 строк, но не является причиной дефекта:
входные state уже были запечатаны на нулевой строке.

**F10 · Major · high · issue · finish/D-log · `decisions/D52-75-item-finish-reconcile.md:12`,
`decisions/_current.md:52`, `decisions/_current.md:53`, `decisions/_current.md:59`,
`phases/P12/P12.md:68`**

D52 объявляет себя current approval carrier для повторного finish 75 items и прямо говорит, что
D47/74 больше не current, но machine chain оставляет D46 и D47 `accepted` в `_current.md` рядом с
D52. Двенадцать phase handoff'ов P1–P12 по-прежнему утверждают, что пройден именно D47; P0 не
называет D52. `handoff.md:35–37` признаёт, что `supersedes` намеренно не применён. Наличие
`## Consequences` у D52 выполняет checklist 7, но не снимает противоречие current D-view ↔ phase
contracts из checklist 4. *Failure scenario:* cold-start consumer обязательного `Pn.md` принимает
исторический 74-item finish за approval и не может машинно установить, прошёл ли добавленный P0.0
повторный full reconcile; разные readers выбирают D47 или D52.

**F11 · Minor · high · issue · navigation views · `index.md:26`, `index.md:55`**

Index всё ещё направляет refinement предыдущего плана к superseded D3 вместо current D12 и
описывает `artifacts/P12-backup-evidence.md` как recurring/restore evidence P12.3, хотя D53
оставил P12.3 только pre-deploy config/fixture section и передал real recurring/restore proof
P12.5. Нарушены plan-protocol §16 и design-audit checklist 3/4. *Failure scenario:* холодный
читатель навигационного слоя восстанавливает отменённый lifecycle и прежний P12 evidence order,
повторяя уже исправленную F7-развилку.

**F12 · Minor · high · issue · bundles · `bundles/P2.6.json:1`,
`bundles/P12.7.json:1` · PROMOTE-CANDIDATE**

Хотя `bundle --all --check` подтверждает byte-fresh 75 manifests, детерминированный scan находит
235 duplicate `(ordinal,path)` groups в 70/75 bundles. Когда Required Reads содержит полный
`decisions/D#.md`, generator одновременно резолвит path-candidate и D# token и записывает один
decision дважды; в P12.7 дубли есть и в Inputs, и в Required Reads. Нарушена дисциплина Layout v2
mandatory digest-qualified inputs и запрет повторного чтения уже прочитанного. *Failure scenario:*
cold executor дважды загружает одни decisions, расходует контекст и получает завышенный admission
weight; текущий максимум уже 69,359 estimated tokens у P11.1. Повторяемость по 70 items указывает
на ecosystem-level deduplication gap, но plan-side declarations тоже обязаны выдавать однозначный
manifest.

### Проверено без находок

- Brief/refinements покрыты без дыр; таблица выше трассирует все смысловые группы к phases/items/D#.
- F7 закрыт: D53, P12 phase contract, P12.3/P12.4/P12.5 и `research/14-P12-execution-design.md`
  согласованно разделяют pre-deploy config/fixtures, first deploy и post-deploy real proof.
- `plan-lint` — 0 ERROR / 0 WARN, baseline `1ab8f053` — 0 новых; 75 specs, 75 state carriers,
  75 bundles, 75 Routing rows и 75 roadmap rows существуют. Future/cyclic state dependency edges — 0.
- `bundle --all --check` — 75 fresh и `decisions --check` — 4 fresh; ограничение F12 относится
  к содержательной уникальности declarations, которую freshness/schema сейчас не проверяют.
- Реальных `[NEEDS CLARIFICATION]` и незакрытых DoR checklist-пунктов нет. Intent/Why/
  Implementation Rules всех 75 items имеют допустимый RAG-marker; D21 provenance материализован
  через D51 у P2.6/P3.3/P12.4/P12.7.
- Design budget `1/1` подтверждён Meta, owner D46 и journal Design pass 1/1; D52 фиксирует повторный
  75-item reconcile. Все superseding/correcting D-records несут `## Consequences`.
- Roadmap покрывает все 75 items без Routing model/effort/Exec drift; solo topology имеет named
  sequential/risk reason, owner gates адресованы external access/policy/freeze/verdict.
- `plan-lint --conflicts` сообщил 0 расхождений, но standalone runtime не нашёл `SKILL.ru.md`,
  поэтому положительное утверждение canon↔implementation parity не делается. Git/code execution
  evidence в режиме `design` по протоколу не проверялось.

### Что запускать

| Model | Thinking | Context | Суть | Команда |
|:--|:--|:--|:--|:--|
| frontier | high | NEW SESSION — шаг-не-item | Устранить F9–F12, материализовать однозначный current finish и повторить design-audit | `$ task:plan-design 2026.08.09-RELEASE-READINESS finish` |

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-design 2026.08.09-RELEASE-READINESS finish

РЕЖИМ: адресная ремедиация plan-audit design (заход 3), НЕ новый product design-pass и НЕ execution
ВХОД: plans/2026.08.09-RELEASE-READINESS/plan.md → блок `## Audit design — 2026-08-09 (заход 3)`; прочитать ПЕРВЫМ
СКОУП: 1) F9 — после terminal journal event штатно пересобрать state→bundle→decisions→status и доказать все четыре `--check` green 2) F10 — сделать D52 однозначным machine-current approval carrier и синхронизировать все phase handoff'ы без переписывания исторического смысла 3) F11 — обновить index на D12 и post-D53 P12.3/P12.5 evidence ownership 4) F12 — устранить duplicate decision declarations plan-side, регенерировать 75 bundles и записать ecosystem PROMOTE-CANDIDATE без правки plugin cache
НЕ ТРОГАТЬ: product code и execution statuses; brief/refinements; доказанные findings/research; product Scope/Files/Validation items вне механической нормализации decision reads; D1–D53 задним числом вне минимально необходимой machine-chain reconciliation'
```
