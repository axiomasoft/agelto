# P3 — пересчёт фактуры раскладки на дереве `4b628a8` (2026-08-04)

Слой 1 (`plan-protocol` §16). Все числа сняты машинно на дереве **`4b628a8`** (HEAD после закрытия
терминальных P1 и P2), команда указана рядом с числом. Файл заводится потому, что §9 разведки
(`repo-recon-2026-07-31.md`, дерево `8f2ccdb`) писался ДО исполнения P1/P2 — семь его чисел, на
которые опираются `Scope`/`Validation` items'ов P3, к дереву исполнения больше не относятся.

Носитель истины для P3 — ЭТОТ файл в части чисел и §9 разведки в части выводов (форма гейта,
чего в проекте нет, гипотеза «корень слоя = кросс-подобластные классы»). Числа §9 не цитируются
(правило было и остаётся: перечень пересчитывается машинно, D30).

## 1. Сводка дрейфа

| Факт | §9 разведки (`8f2ccdb`) | Дерево `4b628a8` | Причина |
|:--|:--|:--|:--|
| Файлов в корне `app/Enums/Procurement` | 38 | **40** | P1.2 (`8194a7f`) завела `ProxyScope`, `ProxyFailureReason` — ровно как предписывал D17 |
| Поверхность переноса (файлов со ссылкой) | ≈333 | **353** | рост `app/` (199) и `tests/` (105) за P1/P2 + новый файл доков |
| Миграций, требующих правки | 9 (`use`) | **13** файлов | 9 с `use` + 4 только с комментарием (два из них — новые миграции P1) |
| Строковых ссылок (не `use`) | «единственная — комментарий колонки» | **7 вхождений в 6 файлах** | комментарии P1 + два вхождения в экранированной форме `App\\Enums\\…` |
| Записей в `phpstan-baseline.neon` | 74 | **62** | baseline сокращался в P1/P2 |
| `app/Repositories/Procurement` | 15 файлов, подкаталогов НЕТ | **16** файлов, подкаталог `Analyzers/` ЕСТЬ | P1 добавила `ProxyHealthStoreRepository` |
| `app/Exceptions/Procurement` | 13 | **15** | P1/P2 |
| `app/Services/Procurement` | 12 | **11** | P2 |
| Файлов доков/скилов со ссылкой | 12 | **13** | добавился `docs/03_Dev/database.md` |

## 2. Слой enum'ов

```bash
find app/Enums/Procurement -maxdepth 1 -type f -name '*.php' | wc -l   # → 40
find app/Enums/Procurement -mindepth 1 -maxdepth 1 -type d            # → Analyzers (внутри Analyzers/Estimate/ — 2 файла)
```

Два файла сверх разбивки §3/§9.1 разведки — от P1.2: `ProxyScope.php` (скоуп исходящей
маршрутизации), `ProxyFailureReason.php` (таксономия причин отказа узла). Оба лежат в корне слоя
по D17 и входят в перенос P3.2 наравне с остальными — предмет P3.1 для них не «согласовать адрес»
(файлы уже на дереве), а поставить их в таблицу адресов раздела 3 `research/04`.

Соседние слои enum'ов вне Procurement (правило раздела 2 `research/04` обязано отвечать и про них,
потому что D65 распространяет его на любой новый класс в выровненном слое):

```bash
find app/Enums/Llm -maxdepth 1 -type f -name '*.php' | wc -l          # → 5, подкаталогов нет
find app/Enums/Organization -maxdepth 1 -type f -name '*.php' | wc -l # → 1, подкаталогов нет
```

## 3. Поверхность переноса — 353 файла

Одна команда на всю поверхность (форма шаблона объяснена в §5 — она видит и экранированную
форму FQN, в отличие от команд §9.3 разведки):

```bash
grep -rlE 'App\\+Enums\\+Procurement' app tests database config resources docs .ai .claude CLAUDE.md | wc -l   # → 353
```

Разбивка тем же шаблоном (`grep -rlE 'App\\+Enums\\+Procurement' <dir> | wc -l`):

| Каталог | Файлов |
|:--|:--|
| `app` | 199 |
| `tests` | 105 |
| `database/factories` | 23 |
| `database/migrations` | 13 |
| `database/seeders` | 2 |
| `config` | 2 (`ai.php`, `crawler.php`) |
| `docs` | 8 |
| `.ai` | 1 (`guidelines/glossary.md`) |
| `resources`, `.claude`, `CLAUDE.md` | 0 (ссылок нет; в `.claude/skills/**` ссылки на **путь** `app/Enums/Procurement/` есть — см. §6) |

## 4. Миграции: 13 файлов, а не 9

```bash
grep -rlE '^use App\\+Enums\\+Procurement' database/migrations | wc -l   # → 9  (держат use-импорт)
grep -rlE  'App\\+Enums\\+Procurement'     database/migrations | wc -l   # → 13 (use ИЛИ комментарий)
```

Девять с `use` (падают на `class not found` в каждом `RefreshDatabase` сразу после переноса):
`2026_07_04_100025`, `100028`, `100029`, `100030`, `100031`, `2026_07_09_100052`,
`2026_07_27_100053`, `2026_07_28_100058`, `2026_07_29_100059`.

Четыре только с комментарием (не ломают прогон, но после переноса становятся ложным указателем):
`2026_07_04_100036`, `2026_07_27_100055`, `2026_07_31_100061`, `2026_07_31_100062` — последние две
заведены P1.

## 5. Семь строковых вхождений и дыра в шаблоне грепа

```bash
grep -rnE 'App\\+Enums\\+Procurement' database config resources | grep -vE ':[0-9]+:use '   # → 7 строк
```

| Файл:строка | Форма | Класс |
|:--|:--|:--|
| `database/migrations/2026_07_04_100036…:23` | `\` | `ResourceType` (комментарий кода, не колонки) |
| `database/migrations/2026_07_27_100055…:30` | `\` | `RepresentationKind` |
| `database/migrations/2026_07_29_100059…:28` | `\\` | `RunMode` |
| `database/migrations/2026_07_29_100059…:29` | `\\` | `AnalysisRunStatus` |
| `database/migrations/2026_07_31_100061…:23` | `\` | `ProxyScope` |
| `database/migrations/2026_07_31_100061…:26` | `\` | `ProxyFailureReason` |
| `database/migrations/2026_07_31_100062…:34` | `\` | `ProxyFailureReason` |

**Дыра, из-за которой §9.3 разведки насчитала одно вхождение вместо семи.** Встречный греп §9.3 и
Validation P3.1 стоят на шаблоне `App.Enums.Procurement` — `.` матчит РОВНО ОДИН символ, поэтому
экранированная форма `App\\Enums\\Procurement` (двойной слеш в исходнике — строки 28–29 миграции
`2026_07_29_100059`) шаблоном НЕ ловится:

```bash
grep -c "App.Enums.Procurement"       database/migrations/2026_07_29_100059_*.php   # → 2 (это строки use, НЕ комментарии)
grep -cE 'App\\+Enums\\+Procurement'  database/migrations/2026_07_29_100059_*.php   # → 4 (use + оба комментария)
```

Отсюда рабочая форма шаблона для всех проверок P3: `-E 'App\\+Enums\\+Procurement'`
(`\\+` — один и более слешей; работает и в GNU grep, и в ugrep, которым подменён `grep` на хосте
владельца). Проверено на дереве `4b628a8`.

## 6. Доки и скилы — 13 файлов

```bash
grep -rlnE 'App\\+Enums\\+Procurement|app/Enums/Procurement' docs .ai .claude CLAUDE.md | wc -l   # → 13
```

`.ai/guidelines/glossary.md` · `.claude/skills/procurement-pipeline-dev/SKILL.md` ·
`.claude/skills/project-map/SKILL.md` · `docs/03_Dev/ai/prompts.md` ·
`docs/03_Dev/architecture.md` · `docs/03_Dev/crawling-pipeline.md` · **`docs/03_Dev/database.md`** ·
`docs/03_Dev/document-pipeline.md` · `docs/03_Dev/recipes.md` · `docs/03_Dev/state-machines.md` ·
`docs/04_Operators/reference/statuses.md` · `docs/specs/v2/01-database.md` ·
`docs/specs/v2/08-estimate-extraction.md`.

`docs/03_Dev/database.md` в `Files` item'а P3.5 не стоял — дописан этим заходом (иначе его правка
была бы диффом вне `Files`, то есть материальным отклонением, а без правки не сошлась бы
собственная Validation P3.5).

`CLAUDE.md` и `.ai/guidelines/architecture.md` ссылок на слой enum'ов НЕ несут (0 вхождений) —
в `Files` P3.5 они стоят как носители АБЗАЦА ПРАВИЛА, а не как файлы с путями к правке.

## 7. `phpstan-baseline.neon` — 62 записи

```bash
grep -c "message:" phpstan-baseline.neon   # → 62
grep -n  "Enums"   phpstan-baseline.neon   # → пусто
```

Записей на `app/Enums/**` по-прежнему нет — перенос enum'ов (P3.2) baseline не трогает. Вывод §9.4
п.2 в силе; изменилось только число, поэтому в items'ах P3 инвариант переформулирован как «число
записей не изменилось относительно значения ДО item'а» (значение снимается на дереве исполнения),
а не как хардкод `74`.

## 8. Раскладка слоёв `app/**` — таблица §9.2 пересчитана

```bash
for d in $(find app -mindepth 1 -maxdepth 2 -type d); do \
  echo "$d root=$(find $d -maxdepth 1 -type f -name '*.php' | wc -l) subdirs=$(find $d -mindepth 1 -maxdepth 1 -type d | wc -l)"; done
```

| Слой | Файлов в корне (`8f2ccdb` → `4b628a8`) | Подкаталоги |
|:--|:--|:--|
| `app/Enums/Procurement` | 38 → **40** | `Analyzers/` |
| `app/Repositories/Procurement` | 15 → **16** | было «нет» → **`Analyzers/`** |
| `app/Models/Procurement` | 14 → 14 | `Crawler/`, `Document/`, `Estimate/`, `Ai/`, `Platform/` |
| `app/Exceptions/Procurement` | 13 → **15** | нет |
| `app/Services/Procurement` | 12 → **11** | 15 подкаталогов |
| `app/Console/Commands/Procurement` | 8 → 8 | `Ai/`, `Analyzers/` |
| `app/Dto/Procurement` | 7 → 7 | 8 подкаталогов |

Вывод для P3.1 не меняется, а усиливается: диапазон «файлов в корне при наличии подкаталогов» —
**7–16**, и таблица прожила без правки четыре дня. Инвентарь обязан быть машинным и
воспроизводимым командой (скрипт `artifacts/inventory.sh`), иначе к моменту P3.4 он снова соврёт.

Отдельно: у `app/Repositories/Procurement` подкаталог появился БЕЗ правила размещения — это
эмпирическое подтверждение того, что слой дрейфует сам, и гейт нужен не «на будущее», а уже сейчас.
