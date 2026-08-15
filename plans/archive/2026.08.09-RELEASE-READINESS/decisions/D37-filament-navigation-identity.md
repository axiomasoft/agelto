---
id: D37
date: 2026-08-09
status: superseded
item: P9.1
items: [P9.1, P9.2, P9.5, P9.6, P10.3, P12.7]
supersedes: []
superseded_by: D82
---
## Решение

Navigation сохраняет stable resource/plugin identities. Source package `azguard-filament` получает
backward-compatible config hook для group/resource/page labels с English defaults; Agelto задаёт
русские overrides и application-owned keyed order в `AdminPanelProvider`: «Краулинг» → «Закупки» →
«AI-анализ» → external identity `AzGuard`, отображаемая как «Доступ» последней. Каждый
navigation-visible Agelto Resource/Page получает явные русские singular/plural/navigation labels и
deterministic sort; installed vendor bytes вручную и permission/route identities не меняются.
RAG:✅ 2026-08-09 official Filament navigation docs; repo binding —
`findings/P9-filament-surface-recon-2026-08-09.md`.

## Почему

Installed-vendor patch перезапишется при install/update, а полный custom NavigationBuilder
продублирует discovery/visibility/authorization. Filament 5.7.6 `NavigationManager` уже сопоставляет
associative group key с отдельным display label; малый source-package config hook сохраняет English
defaults для других consumers и даёт Agelto полный русский menu без нового navigation engine.
RAG:— (repo-grounded: installed `NavigationManager.php`, AzGuard source/resources and composer path).

## Consequences

Package test фиксирует English defaults и arbitrary consumer overrides; Agelto test падает при
новом unlabeled/unsorted navigation subject или смене plugin group identity. Новая группа добавляется
только через ordered map; перевод не меняет URL, permission key, Policy/Gate или
`shouldRegisterNavigation` semantics. Package source commit предшествует Agelto lock/vendor refresh;
unrelated dirty package files не затрагиваются. P9.1 сверяет terminal upstream products;
contradiction требует superseding D37, а не installed-vendor patch.
