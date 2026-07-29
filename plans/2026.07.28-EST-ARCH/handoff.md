# HANDOFF — 2026-07-29 — after P2.1

**Next:** `/task:plan-run 2026.07.28-EST-ARCH P2.2` — типизация маршрутизации на `AiDocumentType`
и снятие литералов по ЗАМОРОЖЕННЫМ контрактам P1.2 и P2.1. `Exec = plan-exec` по §3 Routing
(`implementation/medium`), но `Review = full` — семантика отказа меняется с молчаливого фолбэка на
исключение.

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | medium |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | P2.2 — `resolve/candidatesFor/shadowRoute` со `string` на `AiDocumentType\|DocumentTypeScope`, снятие 6 литералов `'estimate'` и 4 литералов `'any'`, громкая ошибка вместо фолбэка |

```
/task:plan-exec 2026.07.28-EST-ARCH P2.2
```

**Done:** фаза P1 закрыта 🟠 (4/8 🟢, детали — `phases/P1.md` → `## Phase Handoff`). **P2.1 закрыт
🟢 (`fcc0259` item + bookkeeping):** `root/document-analyzer-contract.md` ЗАМОРОЖЕН гейтом владельца
2026-07-29. Семь решений: интерфейс `DocumentAnalyzer` (5 методов, каждый с call-site'ом ядра) ·
замена `has_extractor` реестром · DTO сводки результата (D27) · носитель шага и назначения
ИНТЕРФЕЙСОМ поверх пары enum'ов, цепочку композиции задаёт сам шаг · регистрация строкой
`config('ai.analyzers')` · каталог типа сегментом пути `Analyzers\<Type>` в каждом слое + восемь
неймспейсов ядра для arch-теста P2.6 · место валидации `step`/`purpose` — реестр. Полные факты —
`phases/P2.md` → P2.1 Completion Notes.

**Remaining:** 29 items ⬜. Порядок: P2 (5 из 6) → P3 (6) → P4 (4) → P5 (6) → P6 (3) → P7 (4).
Батчи B1/B2 и два оставшихся гейта владельца (P3.1, P4.2) — `roadmap.md`.

**Sources of truth:** скилл `plan-protocol` · `plan.md` §2 (правила исполнения) и §5 (**D1–D99**) ·
**ЗАМОРОЖЕНЫ: `root/naming-canon.md`, `root/document-type-axis.md`, `root/document-contour.md`,
`root/document-analyzer-contract.md`** — правятся ТОЛЬКО новой D-записью со `supersedes` ·
`findings/extraction-boundary-2026-07-29.md` · `research/01-target-architecture.md` · `roadmap.md` ·
`phases/P1.md` (`## Phase Handoff`) · `phases/P2.md` (P2.1 Completion Notes).

**Lint (`python3 <узел>/packages/task/scripts/plan-lint.py plans/2026.07.28-EST-ARCH --baseline
HEAD`, узел — из `~/.swissknifeman/topology.json`):** **0 ERROR / 0 WARN — новых от диффа P2.1: 0**
(на итоговом дереве, после перезаписи handoff).

**Open risks:**
- **Две находки P2.1 сверх плана, обе с назначенным закрывателем — P2.5.**
  (1) `PromptCritic.php:57` — ТРЕТИЙ call-site сейма носителя шага, в `findings` §5 названы были
  два; без интерфейса-носителя метод расщепление enum'а не пережил бы.
  (2) `AiRouteResolver.php:28-33` (`DEFAULT_TEMPERATURE_BY_PURPOSE`) — ВОСЬМАЯ точка протечки
  предмета в ядро: три сметных назначения литералами. Не попала ни в семь литералов `'estimate'`,
  ни в `findings`. Перенос на `LlmPurpose::defaultTemperature()` ОБЯЗАН сохранить паритет: четыре
  сегодня неперечисленных назначения объявляют ровно `0.0`, иначе температура боевых вызовов
  тихо сдвинется.
- **Инвариант сборки реестра (§5.4 контракта) обязан быть реализован, а не забыт:** анализатор,
  объявивший шаг со значением ядерного, затенил бы ядерный промт МОЛЧА (`groupBy('key')` в
  `PromptReadRepository.php:33`). Исполнители — P2.3 (реестр) и P2.5 (расщепление); прогон
  фиктивного типа P2.6 проходит через ту же сборку.
- **`root/document-type-axis.md` §5 цитирует УСТАРЕВШИЕ номера строк Filament-форм** (писался на
  `dc0815c`): `AiRouteForm.document_type` уже `Select`, не `Textarea`; свободным `Textarea` остался
  `PromptForm` `step` (`:34`). Выводы контракта P1.2 не меняются, но номера строк оттуда не
  цитировать — брать из `document-analyzer-contract.md` §7.
- **P2.2 задевает 12 call-site'ов резолвера** (`grep -rn "routes->resolve\|routes->candidatesFor\|
  routes->shadowRoute" app/ --include=*.php` — 11 на `fcc0259`, плюс `ShadowAnalysisJob:103`
  прокидывает `documentType` строкой): периметр `Files` собирается и от них, и от тестов.
- **Предпросмотр склеенного промта ДО прогона** — требование владельца, в план сознательно НЕ
  введено (решение 2026-07-29: «делается быстро, сообщу отдельно»). P5.5 покрывает промт,
  реально УШЕДШИЙ в провайдера. Кандидат в scope P5.5 либо item P5.7 — поднимается по требованию
  владельца, не автоматически.
- Наследуются из P1: журнальный разрыв P1.5–P1.8 (D99) · дыра протокола D98 (репозиторий
  `swissknifeman`) · практика D89-исхода для гейтов-сторожей инварианта без D-записи ·
  `config/ai.php` несёт докблочные упоминания старых имён таблиц · три строки Pending Work про
  `CLAUDE.md` живут до P7.4 (D60/D64) · боевой прогон P5.2 — за владельцем (D94) · периметр
  coupling для строковых значений enum'ов — держать на P2.5 · периметр `Files` собирается не
  только от читателей колонки (D96), но и от писателей связи и от фабрик (находка P1.6).

**Workarounds/Deferred/Open questions:**
- workarounds: доказательство фальсифицируемости гейта МУТАЦИЕЙ вместо прогона на `<base>` — для
  гейтов над создаваемым артефактом (решение владельца 2026-07-29); применено в P1.1–P1.6 и P2.1,
  впредь требуется у P3.1 и P4.2 · `docker exec agelto_app` вместо `docker compose exec app` —
  интерполяция `.env` падает на `MINIO_ROOT_PASSWORD` (воспроизвелось на P2.1) · `pint --dirty`
  автофикс — точечными Edit-правками (host-запуск заблокирован permission-гейтом сессии)
- deferred: BigQuery-адаптер биллинга (D34) · HITL-правка эталона (D48) · пере-замер F1 боевым
  прогоном (D52) · остаточная дупликация `estimate:dataset:import` (D57) · перенос дрейфа в
  `CLAUDE.md` — P7.4 (D60/D64) · общий инвариант «во всём сервисном слое нет прямых обращений к
  `LlmCall`» — в план НЕ вводится (D70/D79) · переезд экранов транспорта из
  `app/Filament/Resources/Procurement/Ai/**` — вне D22 (D96) · expression-индексы по `type_profile`
  — не заводятся до 10 000 строк реестра · `resources/ai/prompts/README.md` пример front-matter без
  `document_type` · `config/ai.php` докблочные упоминания старых имён таблиц · канонизация
  D89-исхода для гейтов-сторожей инварианта · правка escape-hatch `plan-exec.md` под
  `topology.json` (D98) — репозиторий `swissknifeman`, вне этого плана · раскладка DTO сметы под
  сегмент `Analyzers\Estimate` — решает P3.5, контракт P2.1 её НЕ предписывает
- open_questions: в границах плана — НЕТ. Остаются два гейта владельца впереди (P3.1, P4.2). Вне
  плана: P7.6 плана `2026.07.26-EST-TRAIN` (боевое замыкание петли тюнинга)
