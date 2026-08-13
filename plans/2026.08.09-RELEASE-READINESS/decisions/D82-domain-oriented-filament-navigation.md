---
id: D82
date: 2026-08-13
status: accepted
item: P9.1
items: [P9.1, P9.2, P9.3, P9.4, P9.5, P9.6, P10.3, P12.7]
supersedes: [D37]
superseded_by: null
---
## Provenance

- authority: owner direction 2026-08-13 in the active P9.1 execution;
- recorder: `frontier/high`, `plan-run`, session `019ff9ef-ed5f-7a13-8651-f6737f3c306e`,
  run `ead0f06ede348639f7bf530b2da7613ec4fb8ca40119c3545c1f75141141bb31`;
- evidence: terminal runtime inventory and the user's explicit requirement for domain/task grouping,
  natural names and understandable descriptions; cluster URL supplied by the owner and checked
  against official Filament 5.x documentation.

## Решение

Filament navigation строится по рабочим доменам и задачам оператора, а не по PHP namespaces или
внутренним стадиям pipeline. После ungrouped «Обзор» порядок групп фиксирован так:
«Закупки» → «Площадки» → «Сбор данных» → «Качество анализа» → «Настройка AI» →
«Доступ и безопасность» → «Система».

Распределение фиксировано P9.1 matrix:

- «Закупки»: Закупки, Сметы, Результаты анализа;
- «Площадки»: Площадки, Реестры закупок, Соответствие статусов, Проверки площадок;
- «Сбор данных»: Задания сбора, Запуски сбора, Прокси-серверы, Прокси по умолчанию;
- «Качество анализа»: Эталонные документы, Проверенные примеры;
- «Настройка AI»: AI-провайдеры, Модели для задач, Инструкции для AI, Сборка инструкции,
  Предложения по улучшению;
- «Доступ и безопасность»: Роли пользователей, Персональные права, Проверка доступа;
- «Система»: Журнал процессов, Документация.

Каждый visible subject получает естественные русские singular/plural/navigation labels и короткое
purpose-описание на подходящем native Filament surface. Название отвечает на человеческий вопрос
«что здесь делают», а не переводит `Resource` class name. Hidden `FinalPromptPage` остаётся hidden и
называется «Итоговый запрос к AI» только на своём route.

Stable resource/plugin identity сохраняется. Source package `azguard-filament` получает
backward-compatible config hook для group/resource/page labels и visible Role/DirectGrant/Doctor
presentation copy с English defaults; Agelto задаёт русские overrides и application-owned keyed
order. Evidence-grade permission keys/class names остаются техническими. Installed vendor bytes,
URLs, permission keys, Policies/Gates, grants, `shouldRegisterNavigation` и route identity не
меняются.
RAG:✅ 2026-08-09 official Filament navigation docs; terminal repo/runtime binding —
`findings/P9-filament-surface-recon-2026-08-09.md`.

Filament Cluster для «Настройка AI» рассмотрен, но не входит в P9: official 5.x contract заменяет
дочерние sidebar items одним cluster item, добавляет sub-navigation и URL prefix. При замороженном
route identity это уже отдельная route/auth migration, а не безопасная перегруппировка. RAG:✅
2026-08-13, https://filamentphp.com/docs/5.x/navigation/clusters.

## Почему

D37 группировал по реализации: «Краулинг» одновременно содержал площадки, сбор, проверки и
системный журнал, а «AI-анализ» — рабочие результаты, инфраструктуру моделей и эталонный контур.
Terminal runtime inventory 2026-08-13 подтвердил 19 resources и 58 admin routes и показал, что
literal class-name labels не объясняют назначение большинства экранов. Owner direction требует
интуитивной доменной системы, понятных названий и описаний без линейного перевода.

## Consequences

P9.2 тестирует exact group/order/labels/purpose и package English-default/arbitrary-override
совместимость. P9.3/P9.4 используют тот же словарь в Sections, helper/empty-state copy, columns и
actions. P9.5/P9.6 доказывают, что перегруппировка не изменила 58-route baseline, hidden subjects,
role visibility и direct-URL authorization. D38 остаётся current и владеет enum/boolean/schema/table
presentation contract. Новая группа или механический термин допускается только через evidence-backed
superseding decision, а не локальную правку menu copy. Cluster можно вернуть только отдельным
post-release design с redirects/bookmarks/auth/browser migration proof.
