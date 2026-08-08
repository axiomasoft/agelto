<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-08-08
inputs_sha256: cb3b9cb062d2ef7aad9ee8020d5bbca5a2976f95e2e7a92be954f223ce5a9df4
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | 0.27.0 |
| Status | ⬜ Not started |
| Last Updated | 2026-08-08 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P1 | Разведка ЕИС: параметры листинга, ОКПД2, практики, карточка | 4/5 | 🟠 Done with deviations |
| P2 | ОКПД2: фильтр профиля и древовидная форма | 2/6 | 🟠 Done with deviations |
| P3 | Профили обхода и управление прогонами из админки | 7/10 | 🟠 Done with deviations |
| P4 | Надёжность конвейера: бюджет запросов, троттлинг, анти-дубли | 4/9 | 🟠 Done with deviations |
| P5 | Healthcheck площадки и уведомления | 4/7 | 🟠 Done with deviations |
| P6 | Статусы, история изменений закупки, событие «закупка состоялась» | 1/5 | 🟠 Done with deviations |
| P7 | Три слоя смет в админке и видимость промтов | 0/5 | 🟠 Done with deviations |
| P8 | Схлопывание миграций и финальный аудит схемы | 1/4 | 🟠 Done with deviations |
| P9 | Единая документация с диаграммами | 0/8 | ⬜ Not started |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Живой замер листинга: действие сортировки, окно `updateDate*`, флаги этапов | 🟢 Done | 2026-08-08 |
| P1.2 | ОКПД2: карта «код → id НСИ», предел набора, финальный вердикт по колонке CSV | 🟢 Done | 2026-08-08 |
| P1.3 | RAG-свод практик: пер-стадийные лимиты и разбор синхронного прохода на пачки | 🟢 Done | 2026-08-08 |
| P1.4 | Карточка 44-ФЗ: перечень этапов, структурные якоря вкладок, success-карточка | 🟢 Done | 2026-08-08 |
| P1.5 | Инвентарь вкладок и полей по шести видам процедур | 🟠 Done with deviations | 2026-08-08 |

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | Карта «код ОКПД2 → id НСИ»: таблица, датафайл, сид, резолвер | 🟠 Done with deviations | 2026-08-08 |
| P2.2 | Поле `okpd2Codes` в `EisSearchFilter`, обязательность скоупа профиля и минимальное поле формы | 🟢 Done | 2026-08-08 |
| P2.3 | Участие ОКПД2 в сборке запроса: `okpd2Ids` + `okpd2IdsWithNested` | 🟠 Done with deviations | 2026-08-08 |
| P2.4 | Выбор ОКПД2 в форме профиля: сгруппированный мультиселект | 🟢 Done | 2026-08-08 |
| P2.5 | Команда пересборки карты с ЕИС и детект дрейфа id | 🟠 Done with deviations | 2026-08-08 |
| P2.6 | Снос мёртвой ветки ОКПД2 листинга (код, схема, витрина, тесты, доки) | 🟠 Done with deviations | 2026-08-08 |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P3.1 | Сортировка выдачи в фильтре профиля | 🟠 Done with deviations | 2026-08-08 |
| P3.2 | Прогон на очереди: `CrawlRunJob`, кооперативный стоп-гейт, подъём зависших | 🟠 Done with deviations | 2026-08-08 |
| P3.3 | Политика остановки «только актуальные данные» | 🟢 Done | 2026-08-08 |
| P3.4 | Операторские действия над прогоном и внятный отказ в консоли | 🟢 Done | 2026-08-08 |
| P3.5 | Сиды двух профилей: архивный обучающий и актуальный продовый | 🟠 Done with deviations | 2026-08-08 |
| P3.6 | История прогонов и статистика в админке | 🟢 Done | 2026-08-08 |
| P3.7 | Кооперативный стоп в обёртках-дневных-циклах | 🟢 Done | 2026-08-08 |
| P3.8 | Админ-старт архивного профиля: окно дат живёт на профиле | 🟢 Done | 2026-08-08 |
| P3.9 | Регрессионный гейт консольного отказа `CrawlerRunAlreadyActive` | 🟢 Done | 2026-08-08 |
| P3.10 | Курсор — единственный источник дат в запросе листинга | 🟢 Done | 2026-08-08 |

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P4.1 | Носитель настроек обхода: площадка, профиль, дефолты конфига | 🟠 Done with deviations | 2026-08-08 |
| P4.2 | Привязка прокси к профилю и легальный прямой режим | 🟢 Done | 2026-08-08 |
| P4.3 | Авто-дизейбл прокси и операторская видимость здоровья узлов | 🟢 Done | 2026-08-08 |
| P4.4 | Общий бюджет запросов к площадке | 🟠 Done with deviations | 2026-08-08 |
| P4.5 | Планомерная подача детальной стадии | 🟠 Done with deviations | 2026-08-08 |
| P4.6 | Наблюдаемость конвейера: журнал заборов, журнал операций, метрики ожидания | 🟢 Done | 2026-08-08 |
| P4.7 | Длинный execution lane для датированного archive-профиля | 🟠 Done with deviations | 2026-08-08 |
| P4.8 | Восстановление общих validation-гейтов P4.1 | 🟠 Done with deviations | 2026-08-08 |
| P4.9 | Checkpoint archive/delta/backlog при исчерпании request budget | 🟢 Done | 2026-08-08 |

## Phase P5

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P5.1 | Эталон вёрстки в репозитории, история проверок и словарь вердиктов | 🟢 Done | 2026-08-08 |
| P5.2 | Исход разбора карточки как измеримый факт | 🟢 Done | 2026-08-08 |
| P5.3 | Ежедневная проверка по штатному потоку: три пассивные пробы и команда | 🟠 Done with deviations | 2026-08-08 |
| P5.4 | Активная проба действия ОКПД2-фильтра | 🟠 Done with deviations | 2026-08-08 |
| P5.5 | Событие смены здоровья площадки и уведомление | 🟢 Done | 2026-08-08 |
| P5.6 | Витрина истории проверок в админке | 🟢 Done | 2026-08-08 |
| P5.7 | Ремедиация RED-аудита healthcheck | 🟠 Done with deviations | 2026-08-08 |

## Phase P6

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P6.1 | Сверка статусов площадки, канон положительного исхода и витрина маппингов | 🟠 Done with deviations | 2026-08-08 |
| P6.2 | Разбор вкладки результатов по реальной success-карточке | 🟢 Done | 2026-08-08 |
| P6.3 | Платформо-независимый модуль истории: таймлайн, длительности, diff редакций | 🟠 Done with deviations | 2026-08-08 |
| P6.4 | Витрина истории изменений в карточке закупки | 🟠 Done with deviations | 2026-08-08 |
| P6.5 | Доменное событие «закупка состоялась» и точка подписки | 🟠 Done with deviations | 2026-08-08 |

## Phase P7

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P7.1 | Витрина содержимого золотой сметы в карточке эталона | 🟠 Done with deviations | 2026-08-08 |
| P7.2 | Read-only витрина эталонных ответов датасета | 🟠 Done with deviations | 2026-08-08 |
| P7.3 | Связная навигация по трём слоям | 🟠 Done with deviations | 2026-08-08 |
| P7.4 | Полнота обязательных полей позиции: ревизия корпуса и построчная пометка | 🟠 Done with deviations | 2026-08-08 |
| P7.5 | Видимость промтов: достройка ресурса и экран композиции | 🟠 Done with deviations | 2026-08-08 |

## Phase P8

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P8.1 | Ревизия схемы: карта поглощения, счётный снимок, решение Q16 | 🟠 Done with deviations | 2026-08-08 |
| P8.2 | Схлопывание закупочно-краулингового контура и снос мёртвых носителей | 🟠 Done with deviations | 2026-08-08 |
| P8.3 | Схлопывание AI/LLM/сметного контура | 🟠 Done with deviations | 2026-08-08 |
| P8.4 | Пост-аудит схемы и синхронизация описания | 🟢 Done | 2026-08-08 |

## Phase P9

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P9.1 | Ревизия обоих деревьев и карта переноса | ⬜ Not started | 2026-08-08 |
| P9.2 | Машинный гейт целостности документации | ⬜ Not started | 2026-08-08 |
| P9.3 | Слияние `docs-private` в `docs` | ⬜ Not started | 2026-08-08 |
| P9.4 | Роспуск `specs/v2` 00–07 в канон `03_Dev`/`01_Business` | ⬜ Not started | 2026-08-08 |
| P9.5 | Роспуск `specs/v2` 08 и перевод ссылок живого контура | ⬜ Not started | 2026-08-08 |
| P9.6 | Кроулинг: процессы, настройки, диаграммы Д7–Д12 | ⬜ Not started | 2026-08-08 |
| P9.7 | Документы и AI: форматы, промты, провайдеры, диаграммы Д13–Д14 | ⬜ Not started | 2026-08-08 |
| P9.8 | Сводная навигация, канон каркаса и финальная сверка | ⬜ Not started | 2026-08-08 |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-08-06 | opus/high | plan-design · in-progress · план создан (прогон 1/2): brief, findings, research, 9 скелет-фаз, 35 items |
| 2026-08-06 | opus/high | plan-design · in-progress · живой замер ОКПД2 на ЕИС вне фаз: Q1/Q6 закрыты (D8/D9), инфраструктура разведки исправлена (D10) |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR; P1 расширена item'ом P1.5 (D71) |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-06 | opus/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-07 | opus/xhigh | plan-audit · audit-red · заход 1, вердикт ATTENTION |
| 2026-08-07 | opus/high | plan-design · in-progress · finish: фиксы девяти находок plan-audit design (D100-D104, v0.13.0); закрывает и пробел леджера прогона 2/2 от 2026-08-07, который записи не оставил |
| 2026-08-07 | opus/xhigh | plan-audit · audit-red · заход 2, вердикт ATTENTION |
| 2026-08-07 | opus/high | plan-design · in-progress · finish: фиксы семи находок plan-audit design заход 2 (D105-D106, v0.14.0); греп-проход по всем слоям закрыл класс устаревших адресов, каталог плана впервые закоммичен |
| 2026-08-07 | opus/high | plan-run · in-progress · — |
| 2026-08-07 | opus/high | plan-run · closed-green · Сортировка действует на CSV; дата суточной гранулярности; ISO-формат молча снимает фильтр; 20/20 запросов |
| 2026-08-07 | opus/high | plan-run · in-progress · — |
| 2026-08-07 | opus/high | plan-run · closed-green · Карта F 34 узла, сверка суммой точная, колонка ОКПД2 пуста финально, источник — карточка; 28/60 запросов |
| 2026-08-07 | opus/high | plan-run · in-progress · — |
| 2026-08-07 | opus/high | plan-run · closed-green · 4 этапа на 1000 строк, сид знает несуществующий этап; success-карточка снята; supplier-results 0/5 якорей; 12/15 запросов |
| 2026-08-07 | opus/high | plan-run · in-progress · — |
| 2026-08-07 | opus/high | plan-run · closed-green · свод двух тем снят; для ЕИС лимиты unknown; заведена D107 supersedes D45 по форме паузы; два дефекта боевого кода названы |
| 2026-08-07 | opus/high | plan-run · in-progress · — |
| 2026-08-07 | opus/high | plan-run · closed-deviations · инвентарь шести видов снят; вкладок сверх четырёх нет, перечень зависит от этапа, сегмент URL алиасуемый; Q16 — новых колонок не требуется |
| 2026-08-07 | sonnet/low | plan-close · closed-deviations · фаза P1 закрыта, 4 item 🟢 + P1.5 🟠 |
| 2026-08-07 | opus/xhigh | plan-audit · audit-red · заход 1, вердикт ATTENTION |
| 2026-08-07 | sonnet/medium | plan-exec · in-progress · — |
| 2026-08-07 | sonnet/medium | plan-exec · closed-deviations · таблица+сид+read-репозиторий карты ОКПД2; дифф вне Files — регистрация подобласти Platform в DomainLayoutTest |
| 2026-08-07 | opus/high | plan-run · in-progress · — |
| 2026-08-07 | opus/high | plan-run · escalated · гейт RequiresScope ломает 40 тестов вне Files и убивает форму Filament для fz44 до P2.4 — нужен plan-design |
| 2026-08-07 | opus/high | plan-design · in-progress · ТЗ item'а доведено до DoR |
| 2026-08-07 | sonnet/high | plan-run · in-progress · — |
| 2026-08-07 | sonnet/high | plan-run · closed-green · фабрики+форма+фикстуры, дельта 15/15 против baseline |
| 2026-08-07 | sonnet/high | plan-run · in-progress · — |
| 2026-08-07 | sonnet/high | plan-run · closed-deviations · okpd2Ids+withNested резолв в EisCsvUrlBuilder; дифф вне Files — починка 3 фикстур без карты ОКПД2, K=0 |
| 2026-08-07 | sonnet/medium | plan-exec · in-progress · — |
| 2026-08-07 | sonnet/medium | plan-exec · closed-green · Select доведён: searchable+группировка parent_code, Placeholder при пустой карте, +2 теста |
| 2026-08-07 | opus/high | plan-run · in-progress · — |
| 2026-08-07 | opus/high | plan-run · closed-deviations · crawler:okpd2-sync + синхронизатор + store-репозиторий; 6 тестов зелены, три строки Validation красны идентично на baseline |
| 2026-08-07 | sonnet/medium | plan-exec · in-progress · — |
| 2026-08-07 | sonnet/medium | plan-exec · closed-deviations · Снос мёртвой ветки ОКПД2 листинга; 🟠 — три Validation-строки красны идентично baseline (Δ=0) |
| 2026-08-07 | sonnet/low | plan-close · closed-deviations · фаза P2 закрыта: 6/6 items терминальны (P2.2/P2.4 🟢, остальные 🟠) |
| 2026-08-07 | sonnet/high | plan-run · in-progress · — |
| 2026-08-07 | sonnet/high | plan-run · closed-deviations · Сортировка вынесена в фильтр профиля; 🟠 — полный прогон 18 failed на предсуществующем baseline (D110), вне Files item'а |
| 2026-08-07 | sonnet/high | plan-run · in-progress · — |
| 2026-08-07 | sonnet/high | plan-run · closed-deviations · CrawlRunJob+стоп-гейт+recover-stale закрыты 🟠, Δ=0 на baseline-красноте, Pending Work — archive/delta/backlog day-loop не перечитывает статус |
| 2026-08-07 | sonnet/medium | plan-run · blocked · предписано implementation/high (sonnet/high), сессия sonnet/medium |
| 2026-08-07 | sonnet/high | plan-run · in-progress · — |
| 2026-08-07 | sonnet/high | plan-run · closed-green · pre-mutation contract собран после начала мутаций (процесс), закрыт 🟢 |
| 2026-08-07 | sonnet/medium | plan-exec · in-progress · — |
| 2026-08-07 | sonnet/medium | plan-exec · closed-green · 5 операторских действий поверх StartCrawlerRunAction/RequestRunStopAction; Δ=0 на полном прогоне, закрыт 🟢 |
| 2026-08-07 | sonnet/medium | plan-exec · in-progress · — |
| 2026-08-07 | sonnet/medium | plan-exec · closed-deviations · сиды профилей: поля сортировки + stop_policy, тест расширен в EisSeedersTest.php |
| 2026-08-07 | sonnet/medium | plan-exec · in-progress · — |
| 2026-08-07 | sonnet/medium | plan-exec · closed-green · витрина прогонов+статистика закрыта зелёным, последний item фазы P3 |
| 2026-08-07 | sonnet/low | plan-close · closed-deviations · фаза P3 закрыта, 3/6 green + 3/6 orange (baseline-краснота/дифф-вне-Files) |
| 2026-08-07 | opus/xhigh | plan-audit · audit-red · заход 1, вердикт RED |
| 2026-08-07 | opus/high | plan-design · in-progress · фаза реопенена по вердикту RED (D111): заведены P3.7-P3.9, статусы и доска выровнены D112/D114 |
| 2026-08-07 | sonnet/high | plan-run · in-progress · — |
| 2026-08-07 | sonnet/high | plan-run · closed-green · complete() гейт + 3 обёртки перечитывают статус; 7 новых тестов, Δ=0 |
| 2026-08-07 | sonnet/high | plan-run · in-progress · — |
| 2026-08-07 | sonnet/high | plan-run · closed-green · окно archive-прогона на профиле, HasArchiveWindow, 17 новых тестов, Δ=0 |
| 2026-08-07 | sonnet/medium | plan-exec · in-progress · — |
| 2026-08-07 | sonnet/medium | plan-exec · closed-green · регрессионный гейт CrawlerRunAlreadyActive добавлен, прикладной код не тронут |
| 2026-08-07 | sonnet/low | plan-close · closed-deviations · фаза P3 закрыта повторно после реопена D111, 9/9 items терминальны (2 🟠) |
| 2026-08-07 | opus/xhigh | plan-audit · audit-red · заход 2, вердикт RED |
| 2026-08-07 | opus/high | plan-design · in-progress · реопен D115: заведён P3.10 (курсор — единственный источник дат в запросе); ИСПРАВЛЕНИЕ к записи закрытия P3.8 — новых тестов 12, а не 17 (пофайлово); полный прогон P3.8 переснят на 2aaa50a5 — 18 failed / 1202 passed |
| 2026-08-08 | implementation/high | plan-run · in-progress · — |
| 2026-08-08 | implementation/high | plan-run · closed-green · курсор — единственный источник дат query; sweep не несёт окно публикации профиля |
| 2026-08-08 | implementation/low | plan-close · closed-deviations · фаза P3 закрыта после реопена D115: 10/10 items терминальны (3 🟠) |
| 2026-08-08 | frontier/xhigh | plan-audit · audit-red · заход 3, вердикт RED |
| 2026-08-08 | gpt-5.6-sol/high | plan-design · in-progress · ТЗ item'а доведено до DoR |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-deviations · profile-driven standard/long lane; console test adaptation outside Files |
| 2026-08-08 | implementation/high | plan-run · in-progress · — |
| 2026-08-08 | implementation/high | plan-run · escalated · P4.1 validation требует удалить PoliteThrottle, но Scope откладывает его на P4.4. |
| 2026-08-08 | frontier/high | plan-design · in-progress · ТЗ item'а доведено до DoR: конфликт Scope/Validation снят D118 |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-deviations · typed settings resolver and four HTTP readers; pre-existing common validation failures recorded |
| 2026-08-08 | frontier/high | plan-design · in-progress · P4.8 выделен для восстановления трёх общих validation-гейтов P4.1 |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-deviations · UUID seed, core boundary and HTTP guard restored; Pint has pre-existing out-of-scope violations |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · escalated · factory gate conflicts with Files; declared CrawlerProfileSeederTest absent |
| 2026-08-08 | frontier/high | plan-design · in-progress · ТЗ item’а доведено до DoR; D120 снял конфликт factory/test-carrier |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · escalated · CrawlerFormTest creates proxy-enabled profiles without bindings but is outside Files |
| 2026-08-08 | frontier/medium | plan-design · in-progress · Files P4.2 дополнены CrawlerFormTest для уже выполненного regression fix |
| 2026-08-08 | implementation/low | plan-close · closed-green · сверены commit ea327264 и полный набор validation P4.2 |
| 2026-08-08 | gpt-5.6/frontier/medium | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6/frontier/medium | plan-run · closed-green · auto-disable, reactivate action and health view verified |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · closed-deviations · host-budget внедрён; archive checkpoint и literal network grep вынесены в follow-up |
| 2026-08-08 | frontier/high | plan-design · in-progress · фаза детализирована до DoR |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-green · typed scanner checkpoint сохраняет day и existing carrier |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · escalated · P4.1 resolver has no dispatch_batch_limit carrier |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-deviations · detail jobs batched with typed limit and unique lock |
| 2026-08-08 | implementation/medium | plan-exec · in-progress · — |
| 2026-08-08 | implementation/medium | plan-exec · escalated · Auto-disable logging requires ProxyResolver, absent from Files. |
| 2026-08-08 | frontier/high | plan-design · in-progress · D122 restores P4.6 to executable scope |
| 2026-08-08 | implementation/medium | plan-exec · closed-green · all fetch stages and operation log visibility added |
| 2026-08-08 | implementation/low | plan-close · closed-deviations · фаза P4 закрыта |
| 2026-08-08 | implementation/medium | plan-exec · in-progress · — |
| 2026-08-08 | implementation/medium | plan-exec · escalated · ТЗ требует новые Procurement\Health-каталоги, запрещённые архитектурным реестром. |
| 2026-08-08 | frontier/high | plan-design · in-progress · D123: архитектурный реестр включён в Files P5.1. |
| 2026-08-08 | implementation/medium | plan-exec · closed-green · Эталон, история проверок и словарь вердиктов добавлены. |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-green · зонд якорей и последний исход разбора карточки |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · escalated · Baseline provides only min_sample; critical and soft thresholds required for deterministic passive-check verdicts. |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-deviations · Пассивные проверки, команда и расписание готовы; baseline/общий резолвер добавлены по прямому указанию владельца. |
| 2026-08-08 | implementation/high | plan-run · in-progress · — |
| 2026-08-08 | implementation/high | plan-run · escalated · Боевой API не выражает два запроса одного профиля за вчера без ОКПД2: filter=null убирает окно, пустой scope отвергается, cursor добавляет price 0-0. |
| 2026-08-08 | implementation/high | plan-run · in-progress · Владелец 2026-08-08 снял design-гейт: реализовать минимальный типизированный шов builder/source в текущей сессии. |
| 2026-08-08 | implementation/high | plan-run · closed-deviations · Владелец разрешил типизированный override builder/source; ListingFilterCheck выполняет два запроса и тесты зелёные. |
| 2026-08-08 | implementation/high | plan-run · in-progress · — |
| 2026-08-08 | implementation/high | plan-run · closed-green · Добавлены событие здоровья, Telegram-слушатель и антиспам по вердикту. |
| 2026-08-08 | implementation/medium | plan-exec · in-progress · — |
| 2026-08-08 | implementation/medium | plan-exec · escalated · Требуемый API цвета enum отсутствует вне закрытого списка Files. |
| 2026-08-08 | implementation/medium | plan-exec · closed-green · Read-only витрина, роль оператора и runbook добавлены. |
| 2026-08-08 | frontier/xhigh | plan-audit · audit-red · заход 1, вердикт RED |
| 2026-08-08 | frontier/high | plan-design · in-progress · фаза реопенена D125, P5.7 детализирован до DoR |
| 2026-08-08 | frontier/xhigh | plan-run · in-progress · — |
| 2026-08-08 | frontier/xhigh | plan-run · closed-deviations · F1–F6 закрыты; D127 фиксирует inline stale-test scope expansion, suite вернулся к baseline D112 |
| 2026-08-08 | frontier/xhigh | plan-audit · audit-green · заход 2: F1–F6 закрыты P5.7, новых findings нет |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-deviations · Сверка этапов, канон исхода и витрина маппингов; Validation имеет baseline-отклонения. |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · escalated · success-вкладка не несёт реквизитов победителя для обязательного AwardData |
| 2026-08-08 | frontier/high | plan-design · in-progress · ТЗ item'а доведено до DoR по D128 |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · closed-green · Реальная вкладка пишет protocol, anonymous bid и award с монотонным winner enrichment. |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · escalated · Files P6.3 omit mandatory DomainLayoutTest update for new History subareas. |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/high | plan-run · closed-deviations · History module completed; D129 added required architecture registry. |
| 2026-08-08 | owner | plan-close · closed-deviations · Сверка при миграции layout v2: P4.8 закрыл remediation, но P4.1 после design-reopen не получил терминальную запись; статус и отклонения подтверждены Completion Notes v1. |
| 2026-08-08 | owner | plan-close · closed-deviations · Сверка при миграции layout v2: терминальная запись P5.3 пропустила friction=deviation; отклонение подтверждено Known Deviations v1. |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · closed-deviations · Manual Layout v2 execution: read-only events/revisions tabs, duration infolist, and P6.3 diff modal implemented. Target tests, Pint and PHPStan green; full suite has one pre-existing missing legacy research file outside item scope. |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · closed-deviations · Manual v2: succeeded_at guard, post-commit event, frozen contract/docs; focused suite, Pint, PHPStan and migration up/down green. Full suite stays red outside scope; documented 38 lint defects and P6.5 bundle legacy §5 defect preserved. |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · closed-deviations · Manual v2: reader with path gate, read-only renderer/tab, and manifest marker complete. Focused 6 tests, UI boundary, Pint, PHPStan and full suite green. Known 38 lint defects and P7.1 legacy-prose bundle defect preserved. |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · closed-deviations · note сокращена; полный текст: journal.jsonl:145 |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · in-progress · — |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · closed-deviations · Связная навигация и проверки зелёные; item-коммит отложен: Files уже содержат предсуществующий незакоммиченный дифф. |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · closed-deviations · Manual v2: причина, фильтр, read-only audit gold/database и baseline 214/214; focused 22, Pint, PHPStan, full green root-only (test storage). P7.4 bundle unresolvable_entry и 38 lint defects preserved; item commit deferred (dirty tree). |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · in-progress · Manual Layout v2 preflight: item-local legacy-prose bundle defect was recorded; direct required sources were read. Global plan-lint stays fail-closed on the documented 38 staged defects. |
| 2026-08-08 | gpt-5.6-terra/medium | plan-run · closed-deviations · Manual Layout v2: list/view and composition done; direct URL requires PromptPermission::ViewAny; rules separated from final payload. Focused 8, Pint, PHPStan and full suite green (root-only). Bundle defect, 38 lint defects; commit deferred (dirty tree). |
| 2026-08-08 | gpt-5.6-sol/xhigh | plan-audit · audit-red · Manual Layout v2 audit; task command not invoked. RED: full suite 8 failed/1 skipped/1384 passed, including six P7.4 regressions; four undeclared Files paths; P7 item/bookkeeping commits absent in mixed dirty tree. Scoped remediation recorded; P8.1 blocked. |
| 2026-08-08 | gpt-5.6-sol/xhigh | plan-run · closed-deviations · note сокращена; полный текст: journal.jsonl:152 |
| 2026-08-08 | gpt-5.6-sol/xhigh | plan-audit · audit-green · Independent manual Layout v2 re-audit; task command not invoked. GREEN: regressions 28/93; P7 50/200 + UI 3/75; gold 214/214; Pint 1082; PHPStan 0; full 1 skipped/1392/5856. Scope/13 commits verified; known 38 lint + 5 bundle defects preserved. P8.1 next session. |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · in-progress · Manual Layout v2 preflight: P8.1 bundle input defects ('там же' and external skill symlink) were repaired in the owning carrier; admitted bundle is green. Full plan-lint remains fail-closed on staged read-admission defects. |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · closed-deviations · Manual Layout v2: map 27+6 and fresh baseline 73/73 complete; D82 proof and refs are in findings/P8-schema-revision.md. Owner approved Q16 → D130: 55 labels stay jsonb, no new columns. Bundle green; 33 staged lint defects remain, no P8.1 diagnostics. Item 5d9e59f5. |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · in-progress · Manual Layout v2 preflight: repaired P8.2 prose-only Q16/research/skill admission defects in the owning carrier; generated bundle is green. Full plan-lint remains fail-closed on the documented staged read-admission defects. |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · closed-deviations · note сокращена; полный текст: journal.jsonl:157 |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · in-progress · Manual Layout v2 preflight: repaired prose-only P8.2/research/skill and combined migration read-admission defects in the owning carrier; admitted bundle green. Full plan-lint remains fail-closed on documented staged defects. |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · closed-deviations · note сокращена; полный текст: journal.jsonl:159 |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · in-progress · Manual Layout v2 preflight: P8.4 bundle exposed one prose-only Completion Notes admission defect; the owning carrier now names P8.2/P8.3 state paths and the bundle is green. Full plan-lint remains fail-closed on 29 staged P5.3/P6.5/P7/P9 defects, with no P8.4 diagnostic. |
| 2026-08-08 | gpt-5.6-sol/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:161 |

## Owner Gates

- P1.4: known deviation
- P1.5: known deviation
- P2.1: known deviation
- P2.3: known deviation
- P2.5: known deviation
- P2.6: known deviation
- P3.1: known deviation
- P3.2: known deviation
- P3.3: known deviation
- P3.4: known deviation
- P3.5: known deviation
- P4.1: known deviation
- P4.3: known deviation
- P4.4: known deviation
- P4.5: known deviation
- P4.7: known deviation
- P4.8: known deviation
- P4.9: known deviation
- P5.3: known deviation
- P5.4: known deviation
- P5.7: known deviation
- P6.1: known deviation
- P6.2: known deviation
- P6.3: known deviation
- P6.4: known deviation
- P6.5: known deviation
- P7.1: known deviation
- P7.2: known deviation
- P7.3: known deviation
- P7.4: known deviation
- P7.5: known deviation
- P8.1: known deviation
- P8.2: known deviation
- P8.3: known deviation
