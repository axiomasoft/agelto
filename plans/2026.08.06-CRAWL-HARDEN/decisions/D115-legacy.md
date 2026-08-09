---
id: D115
date: 2026-08-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Фаза P3 реопенена повторно (заход 3 бухгалтерии, второй реопен) по вердикту `RED` аудита P3 захода 2.** Причина — п. 2 правил остановки: дефект в коде, отгруженном САМИМ item'ом P3.8. Окно `publishedFrom`/`publishedTo`, проставленное P3.8 сидовому архивному профилю, УТЕКАЕТ в запрос delta- и backlog-путей: `EisCsvUrlBuilder::archiveOverrides()` при `dateField = 'update'` эмитит `updateDateFrom`/`updateDateTo`, а `publishDateFrom`/`publishDateTo` от `filterParams()` остаются в query — sweep молча слеп ко всему, что опубликовано вне 2025 (F1). **Носитель фикса запинен: `EisCsvUrlBuilder`** — когда курсор дренирует день, ЕДИНСТВЕННЫЙ источник дат в запросе есть курсор, противоположная пара ключей из фильтра профиля снимается; правило живёт в одном месте, а не в трёх вызывающих. Отвергнуты: (а) **флаг/параметр от трёх вызывающих** (`ArchiveRunProcessor`/`DeltaScanProcessor`/`BacklogRescanProcessor` передают «окно профиля не применять») — правило размножается по числу путей обхода, четвёртый путь протечёт снова ровно так же, как протекли эти два; (б) **отдельные поля DTO под окно archive-прогона** (`archiveFrom`/`archiveTo`) — семантически чище всего, но это отмена D113 целиком: миграция jsonb-фильтров живых профилей, правка формы, сида, `HasArchiveWindow` и переисполнение P3.8, тогда как утечку закрывает шов в билдере; вариант остаётся честным кандидатом, если ролей у поля станет четыре. Заводится ОДИН item — **P3.10**. Четыре шага реопена (`plan-protocol` §3) исполнены: Status Board `🟠` → `🟡` (6/10), Version 0.16.0 → 0.17.0, эта запись, `## Phase Handoff` фазы помечен `> УСТАРЕЛ — фаза реопенена D115`. **Consequences:** фаза P3 второй раз перестаёт быть терминальной, переход к P4 сдвигается на один item; статус P3.8 переоценивается `🟢` → `🟠` (дефект в его диффе + незадекларированная разбивка на два кодовых коммита, F5), но код P3.8 НЕ откатывается — контракт `HasArchiveWindow` и окно на профиле остаются в силе, P3.10 достраивает недостающее разведение ролей; следующий аудит фазы — заход 3, то есть `AUDIT-CAP`: решение о судьбе фазы будет за владельцем

## Почему

Дефект не гипотетический и уже вооружён расписанием: `crawler:rescan-backlog` тикает каждые 15 минут без флага (`routes/console.php:33`), дренирует окно `possible_skip_window` по `updateDate` и гасит `cap_hit` (`BacklogRescanProcessor.php:110-111`) — пропущенные закупки после этого не отбираются НИКОГДА, то есть цена ошибки необратима и молчалива. `RAG:— (repo-grounded: app/Services/Procurement/Eis/EisCsvUrlBuilder.php:111-117,218-233 — filterParams() эмитит publishDate*, archiveOverrides() при update их не перекрывает; app/Services/Procurement/Modes/DeltaScanProcessor.php:34-38,74; app/Services/Procurement/Modes/BacklogRescanProcessor.php:62-67,150; database/seeders/Procurement/CrawlerProfileSeeder.php:130-131; phases/P3.md блок `## Audit P3 — 2026-08-07 (заход 2)`, находка F1)`
