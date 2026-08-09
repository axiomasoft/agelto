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
| Design Passes | 1/3 — 13 фаз / 74 items, кросс-репозиторная разведка трёх референсов, несколько открытых архитектурных решений и финальный production gate требуют трёх независимых дизайн-проходов |
| visibility | private |

## 1. Context

План доводит Agelto от работающего development-дерева до воспроизводимого первого production-
развёртывания на пустом Ubuntu-сервере. Он охватывает Docker/Compose и Makefile, секреты и
локальные private packages, деплой и rollback, Laravel Doctor, свежую установку и Livewire/
Filament assets, закрытую production-документацию, прокси, документный конвейер, Filament UX,
Pest 5/CI и сквозной аудит качества.

Текущий Makefile считается набором требований и примеров, а не неприкасаемым интерфейсом.
Сначала P0 сравнивает Agelto с локальными Sova, Vintera/CMS и Dterema и верифицирует внешние
практики через RAG; последующие фазы реализуют только запечатанные решения и сохраняют
провенанс. Код приложения не меняется на авторском проходе new.

Финальное доказательство — не зелёный прогон на уже настроенной машине, а повторяемый путь
из чистого clone: private dependency, секреты, build, миграции, обязательные сиды, создание
администратора, assets, закрытая документация, health/doctor и полный CI-набор.

## 2. Execution Rules

- До завершения детализации всех фаз исполнение запрещено; каждый skeleton-range в Routing имеет Exec = plan-design.
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

Все строки ниже — безопасные draft-маршруты skeleton-фаз. При детализации каждой фазы они
заменяются построчными маршрутами по Tier policy; ни один item не исполняется с Exec =
plan-design.

| Batch | Items | Model class/effort | Exec | Review | Почему |
|:--|:--|:--|:--|:--|:--|
| — | P0.1–P0.6 | frontier/high | plan-design | full | research и заморозка несущих решений ещё открыты |
| — | P1.1–P1.5 | frontier/high | plan-design | full | Docker/Make/Vite contract ещё зависит от P0 |
| — | P2.1–P2.6 | frontier/high | plan-design | full | secret/vendor/bootstrap contract ещё зависит от P0–P1 |
| — | P3.1–P3.5 | frontier/high | plan-design | full | причина Livewire 404 и readiness contract ещё не доказаны |
| — | P4.1–P4.5 | frontier/high | plan-design | full | модель закрытой документации требует threat model и RAG |
| — | P5.1–P5.5 | frontier/high | plan-design | full | storage и precedence default proxy ещё не заморожены |
| — | P6.1–P6.6 | frontier/high | plan-design | full | format/archive handler contract и security limits ещё открыты |
| — | P7.1–P7.6 | frontier/high | plan-design | full | document taxonomy/summary/tags contract ещё открыт |
| — | P8.1–P8.5 | frontier/high | plan-design | full | platform/status-mapping extension contract ещё открыт |
| — | P9.1–P9.6 | frontier/high | plan-design | full | полная карта Filament resources ещё не собрана |
| — | P10.1–P10.6 | frontier/high | plan-design | full | audit findings неизвестны до измерения |
| — | P11.1–P11.6 | frontier/high | plan-design | full | Pest 5 и CI topology требуют официальной верификации |
| — | P12.1–P12.7 | frontier/high | plan-design | full | финальный deploy/rehearsal contract потребляет все предыдущие фазы |

## 5. Decision Log

Источник решений — immutable records в decisions/. Текущие, архивные и proposed views
собираются командой plan-views.py decisions из marketplace root Swissknifeman.
