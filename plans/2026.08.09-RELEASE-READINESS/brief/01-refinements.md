# Уточнения владельца

## 2026-08-09 — статус предыдущего плана

План 2026.08.06-CRAWL-HARDEN фактически архивный; его забыли физически перенести в
plans/archive. Новый план не должен считать его конкурирующим незавершённым планом и не должен
ставить ему Paused By. Физическая архивация выполняется отдельным штатным plan-close archive,
а не смешивается с bootstrap-коммитом нового плана.

## 2026-08-09 — design synthesis P1

Новых owner-уточнений не потребовалось. Разрешение владельца переписать Make/Compose interface
трассировано в D10. На repo + official-source evidence приняты D16–D18: явные standalone dev/prod
Compose carriers с parity test, immutable production image без host code/vendor mounts и pinned
pnpm frontend contract. Полная dependency/bootstrap готовность остаётся у P2, Livewire/Filament
assets — у P3, clean production rehearsal — у P12; P1 не подменяет эти гейты.

## 2026-08-09 — design synthesis P2

Новых owner-уточнений не потребовалось. D6 уточнён без смены carrier: nested AzGuard clone получает
tracked full-SHA manifest (D19). Production secrets переходят с рассинхронизированного Swarm/env
fallback на file-backed Compose contract (D20). First-party Laravel Doctor остаётся require-dev и
получает one-off production diagnostic image, не расширяя runtime app image (D21). P2 закрывает
dependency/bootstrap readiness; Livewire/assets остаются P3, server rehearsal — P12.

## 2026-08-09 — design synthesis P3

Новых owner-уточнений не потребовалось. Locked Livewire 4 source и текущий Nginx дают проверяемую,
но ещё не runtime-доказанную гипотезу 404; P3.1 обязан запечатать before-fix evidence до mutation.
D22 сохраняет dynamic Livewire routes и один Filament asset carrier, D23 разводит `/up` liveness и
active Doctor/service readiness, D24 выбирает pinned native Playwright Test для admin interaction.
Clean-install не может касаться owner's stack: D25 синхронизирует P1/P3 на project-scoped Compose
без fixed `container_name`. Полный official Laravel/Livewire/Filament verdict остаётся hard gate P0.4,
а final Ubuntu production rehearsal — P12.

## 2026-08-09 — design synthesis P4

Новых owner-уточнений не потребовалось. D7 конкретизирован без ослабления: D26 выбирает exact
stable VitePress 1.6.4 и verified immutable-image artifact вне `public`/writable volumes; D27
задаёт один Laravel handler, manifest/path/MIME allowlist, AzGuard `DocumentationPermission::View`
и `private, no-store` для HTML и всех assets. SuperAdmin wildcard — единственный initial role
path; Analyst/Operator grant не добавляется, будущие роли используют тот же permission. P4.1
обязан сверить ещё не исполненные P0.4/P0.6/P1.5/P3.5 и остановиться на contradiction; P12
остаётся владельцем final production rehearsal.

## 2026-08-09 — design synthesis P5

Новых owner-уточнений не потребовалось. Предпочтение Spatie Settings подтверждено official
compatibility с locked Laravel 13: D28 выбирает dedicated `prc_settings` и nullable ID существующего
Proxy без дублирования credentials. D29 сохраняет старый explicit-direct intent через typed
`profile|default|direct`, запрещает availability fallback и делает source наблюдаемым при неизменных
global/per-target health carriers. P5.1 обязан сверить ещё не исполненные P0.5/P0.6; LLM и
PLAYWRIGHT_MCP_PROXY_SERVER остаются отдельными egress carriers, P10/P12 — владельцами общего аудита
и production rehearsal.

## 2026-08-09 — design synthesis P6

Новых owner-уточнений не потребовалось. Repo recon показал, что ZIP/7z/RAR и child rows уже
существуют, но catch-all Passthrough принимает неизвестные bytes, reason живёт только в строке,
bulk extraction не контролирует special/actual-byte cases, child job не dispatch-ится, а parent
archive может уйти в screening. D30 выбирает один typed extension/MIME/signature registry, typed
handler result и отдельный machine-readable reason. D31 выбирает bounded entry processing,
DB-unique parent/path lineage, короткий Action transaction и after-commit ordinary dispatch;
parent получает terminal Expanded. P6.1 обязан сверить ещё не исполненные P0.5/P0.6 до mutation;
semantic document taxonomy остаётся P7, общий security audit — P10, production proof — P12.

## 2026-08-09 — design synthesis P7

Новых owner-уточнений не потребовалось. Repo recon подтвердил, что `AiDocumentType`, typed
`ScreeningResult`, flat `Analysis.result`, backend branching и read-only AnalysisResource уже
существуют; P7 эволюционирует их, не создавая второй pipeline/result table. D32 сохраняет enum как
semantic SSOT и разводит его с raw `Document.kind`; D33 добавляет required summary и typed
`none|single|multiple|unknown` estimate cardinality, оставляя backend disposition вне provider
schema; D34 выбирает versioned nested Analysis result, typed backend reason и short Action with
after-commit dispatch. P7.1 обязан сверить ещё не исполненные P0.4/P0.5/P0.6 и P6.6 до mutation;
multi-label routing не вводится, общая Filament IA остаётся P9, audit/rehearsal — P10/P12.

## 2026-08-09 — design synthesis P8

Новых owner-уточнений не потребовалось. Repo recon показал, что DB identity разрешает одинаковый
`Registry.code` на разных площадках, тогда как runtime filter map использует этот код глобально;
`SourceMethod::Csv` также ведёт к EIS-specific implementation, а onboarding-тест проверяет только
вставку строк. D35 выбирает typed platform definition по точному `Platform.code`, единый
fail-closed registry для source methods/filter DTOs и запрещает FQCN в DB/dual maps. D36 выбирает
ровно platform-default или exact-registry status applicability с precedence exact→platform и
exact-registry unknown creation; global raw rule/JSON priority запрещены. Existing mappings
сохраняют exact scope, реальная новая площадка не входит в P8. P8.1 обязан сверить ещё не
исполненные P0.5/P0.6/P7.6 до mutation; общая Filament IA остаётся P9, audit/rehearsal — P10/P12.

## 2026-08-09 — design synthesis P9

Новых owner-уточнений не потребовалось. Repo/official-source recon подтвердил 17 resources, 46
resource pages, отсутствие общего Russian label/Section/responsive contract и возможность
переименовать external group identity `AzGuard` в «Доступ» keyed map-ом Filament без vendor fork.
D37 запечатывает ordered navigation, backward-compatible localization hook source package
`azguard-filament` и явные русские labels при неизменных route/permission identities. D38
ограничивает `HasLabel` context-independent enum-ами, оставляет Llm interfaces
Filament-free и задаёт native Section/helper/table/responsive contract без изменения validation,
dehydration или authorization. P9.1 обязан сверить terminal P0.4/P0.6/P3.5–P8.5 до UI mutation;
P9.6 расширяет Playwright carrier P3.4, а не создаёт второй E2E stack. Общий audit/CI/rehearsal
остаются P10/P11/P12.

## 2026-08-09 — design synthesis P10

Новых owner-уточнений не потребовалось. Authoring recon подтвердил PHPStan level 5 с живым
baseline, отсутствие Rector и рассинхрон чисел living static-analysis doc с текущим tree; grep
seed'ы намеренно не объявлены дефектами без manual verification. D39 запечатывает последовательный
контур baseline → три read-only audit carriers → disposition matrix → vertical remediation → local
gates/docs. Target P10 — PHPStan max без committed baseline; Pint не вводит blanket `final_class`
и style-only mass rewrite, Rector допускается только после official compatibility evidence на
terminal graph. P11 остаётся владельцем Pest/CI, P12 — clean production rehearsal.

## 2026-08-09 — design synthesis P11

Новых owner-уточнений не потребовалось. Repo/official-source recon подтвердил, что Pest 5,
PCOV/TIA volume и PAO уже установлены, но `always()->locally()` расходится с актуальным official
Pest contract, DB guard не доказывает tokenized parallel database/filesystem isolation, Codex
markers не доходят до PAO в контейнере, а CI отсутствует. D40 сохраняет local TIA, требует full
`--ci --no-tia` acceptance, добавляет trusted shared baseline и принимает process/shard mode только
по повторяемому выигрышу без потери test-name/status set. D41 запечатывает exact serial/tokenized DB,
process-scoped writable roots и negative sabotage corpus; D42 — least-privilege SHA-pinned CI,
узкие lock-keyed caches, risk matrix, non-regressing coverage и measured performance/flake gates.
P11 состоит из шести последовательных items и переиспользует D24 Playwright и P10 local gates;
отдельная orchestration не объявляется. P12 остаётся владельцем clean production rehearsal.

## 2026-08-09 — design synthesis P12

Новых owner-уточнений по product scope не потребовалось. Official-source проверка и terminal-plan
contracts запечатали Ubuntu 24.04 LTS, dedicated key-only deploy identity, host Nginx/Certbot над
loopback app и effective Docker firewall checks (D43). Отдельные read-only GitHub deploy keys
используются для Agelto/AzGuard; build-on-host остаётся запрещён D17. Full recovery set включает
PostgreSQL, object/media, encrypted file secrets и config/lock manifests, а RPO/RTO/retention/
off-host destination остаются явным owner-approved production profile без придуманного default
(D44). Финал имеет только `READY|NOT_READY` и остаётся blocked на любом missing/red proof (D45).
P12 состоит из семи последовательных items: каждый потребляет состояние/артефакт предыдущего,
поэтому отдельная orchestration не объявляется.

## 2026-08-09 — owner decision по design budget и finish

Владелец явно распорядился сократить `Design Passes` с 1/3 до 1/1, записать owner D-решение и
выполнить `finish`, чтобы план был целостным. Компромисс относится только к числу независимых
design-проходов: полный cross-phase reconcile/DoR/roadmap остаётся обязательным, как и отдельный
fresh-context `$ task:plan-audit 2026.08.09-RELEASE-READINESS design` на frontier/xhigh до
любого execution.

## 2026-08-10 — P0.3 MCP replay gate

Владелец потребовал не принимать WebSearch-only infrastructure findings как terminal evidence:
P0.3 должен повторно проверить прежние findings и относящиеся к ним design-выводы через MCP.
Диагностика plan-design подтвердила, что `perplexity-web` сейчас не проходит smoke: Codex tool
возвращает `Daemon connection closed before result`, а daemon logs показывают stale graphical
environment и конкурирующий persistent-profile lock. До зелёного MCP smoke P0.3 остаётся
`🔴 Blocked`; fallback-файл сохраняется как provisional input и не открывает P0.4/P0.6.

## 2026-08-10 — owner requirement после первой попытки P1.1

Владелец считает мгновенный `blocked` на первом implementation-item признаком дефекта дизайна,
а не нормальной рабочей неудачей. Перед повторным закрытием P1.1 требуется устранить сам класс
проблемы: проверить P1.1 и обозримую цепочку P1.2–P1.5 на исполнимые предусловия и Validation,
не допускать преждевременного запуска команд будущих consumers и распространить исправление на
все механически обнаруженные downstream consumers P1.1. Цель — холодный исполнитель закрывает
готовый producer по его собственному scope; будущий consumer остаётся `unknown` до появления его
item-owned verification carrier, а не ложно красным из-за ещё не реализованной цели или фрагмента
пояснительного текста.

## 2026-08-10 — запуск remediation RED-аудита P1

Владелец запустил предписанный `$ task:plan-design 2026.08.09-RELEASE-READINESS P1` после RED-аудита.
Scope-payload аудита принят как граница авторинга: закрыть F1–F5 минимальным remediation scope,
не менять P0/P2–P12 specs, application/domain code и frozen D16–D18/D25. D64 фиксирует один P1.6:
product Make/test/docs carrier исправляется отдельным commit, исторические P1.1–P1.5 — только
append-only reconciliation, затем обязательны phase close и fresh-context audit P1.

## 2026-08-10 — повторный design P1 после downstream RED

Владелец запустил повторный `$ task:plan-design 2026.08.09-RELEASE-READINESS P1` из handoff
заблокированного P1.6. Граница запуска: не отменять product commit `cb49b47c` и не исполнять
будущие P2/P10/P12 acceptance внутри P1.6; нормализовать ошибочно исполнимые consumer Validation
до item-owned carriers, завершить direct assertions terminal P1 carrier и повторить P1.6 с
append-only evidence. Решение материализовано D65 и `research/17-P1.6-downstream-retry-design.md`.

## 2026-08-10 — второй reopen P1 по RED-аудиту

Владелец запустил `$ task:plan-design 2026.08.09-RELEASE-READINESS P1` строго в режиме reopen по
четырём шагам, не детализации. Scope ограничен F6–F10 последнего Audit P1: получить воспроизводимую
полную P1.6 Validation на чистом названном commit либо явный product/post-close contract; добавить
exact lint baseline/count и clean product-tree anchors P1.1–P1.5; удалить expired legacy aliases и
их positive test/docs rows; синхронизировать Phase Context/handoff и сохранить duplicate journal
reconciliation как immutable process deviation. P0, P2–P12, D16–D18/D25/D57/D63–D65,
доказанные Docker/Compose/Vite/image/deploy-class semantics, historical audit/journal/findings и
пользовательский `AGENTS.md` остаются вне scope.

## 2026-08-11 — финальный reopen P2 по RED-аудиту

Владелец запустил `$ task:plan-design 2026.08.09-RELEASE-READINESS P2` строго в режиме §3-reopen,
не детализации. Scope ограничен F1–F8 блока `## Audit P2 — 2026-08-11`: полные fail-closed
Doctor contracts; same-SHA build/publish Doctor image; replay exact preflight exits; hostile
secret boundaries; реальные BaseSeeder/Doctor и Pest/Pint/PHPStan/Task/citation/diff suites;
обязательные non-fake carriers P2.1/P2.2/P2.4; append-only hashes/write-site reconciliation;
handoff/close/re-audit. Закрытые specs вне минимальной remediation, P0/P1 и чужие фазы,
пользовательский `AGENTS.md`, private vendor source/ref сверх D19 pin остаются вне scope.

Владелец отдельно постановил, что это последний design-прогон P2. После P2.7 выполняются close и
повторный audit; если frozen acceptance выявит дополнительные дефекты, они исправляются bounded
fix-ами без нового design-цикла. Новый product contract или выход за anti-scope по-прежнему требует
явного решения владельца. Решение материализовано D70 и `research/19-P2-audit-remediation.md`.

## 2026-08-11 — финальный reopen P4 по RED-аудиту

Владелец запустил `$ task:plan-design 2026.08.09-RELEASE-READINESS P4` строго в режиме §3-reopen,
не детализации. Scope ограничен F1–F8 блока `## Audit P4 — 2026-08-11`: закрыть active-SVG seam;
довести P4.5 до буквального GREEN composite browser/image carrier; сделать P4.2 clean-commit
carrier самодостаточным; восстановить P4.1 upstream committed refs; append-only reconcile P4.2
status, P4.1/P4.2/P4.5 write-sites/routes/anchors и phase/handoff views. Single auth route, AzGuard
permission model, traversal/realpath containment, VitePress 1.6.4, outside-public boundary,
P0–P3/P5+ и пользовательский `AGENTS.md` остаются вне scope.

Владелец отдельно потребовал не оставлять конфликтов следующему проходу: frozen acceptance defects
исправляются в текущем P4.6 до полного GREEN, после чего P4 закрывается без ещё одного design/audit
цикла. Решение материализовано D72 и `research/20-P4-audit-remediation.md`.
