# P2 — фактическое состояние gold-корпуса на 2026-08-01

Слой 1 (raw). Повод — эскалация `P2.2` (`> 🔴 ESCALATION P2.2`, `phases/P2.md`): исполнитель
`plan-run` 2026-08-01 обнаружил пустые `prc_ai_fixtures`/`prc_ai_dataset_examples` и остановился
по D25. Настоящий файл фиксирует ПРОВЕРЕННОЕ состояние обоих носителей корпуса — git-SSOT и БД —
и служит входом решения владельца.

Дерево проверки: `78a1e08` (рабочее дерево ветки `chore/skm-cleanup`, незакоммиченный дифф —
только `plans/**` и чужие файлы из carve-out `plan.md` §2). БД — `agelto` (не `agelto_test`).
Команда доступа: `docker exec agelto_app php artisan tinker --execute="…"`.

## 1. БД — пусто (эскалация подтверждена в своей фактической части)

```
fixtures=0 dataset=0 evalruns=0 docs=1   (db=agelto)
```

`prc_ai_fixtures` = 0 · `prc_ai_dataset_examples` = 0 · `prc_ai_eval_runs` = 0 ·
`prc_documents` = 1. Рабочих копий эталонов (`Document` с `procurement_id=null`, их создаёт
`estimate:gold:import`) в БД нет.

## 2. Git-SSOT корпуса — ЦЕЛ (это меняет вердикт эскалации)

Канон корпуса — не БД. `config('ai.fixtures')` (`config/ai.php:498-512`) объявляет SSOT в git:

| Ключ | Путь | Состояние на дереве |
|:--|:--|:--|
| `manifest_path` | `estimates/manifest.json` | есть, `version: 1`, **24 записи** эталонов |
| `gold_dir` | `estimates/gold/` | есть, **6 файлов `*.gold.json`** + 9 `*.screening.json` + 8 `*.profile.json` |
| `source_dir` | `estimates/files/` | есть (не в git по D-канону донора), исходники всех 6 gold-эталонов на месте |
| `splits_path` | `estimates/eval-splits.json` | есть, `generated_at: 2026-07-28`, `holdout` — 2 кода, `tune` — 4 кода |

Докблок `config/ai.php:455-463` называет это прямым текстом: «`manifest_path` — **SSOT корпуса в
git** (D1/D20): единственный вход `estimate:fixtures:import`, единственный выход
`estimate:fixtures:manifest`».

### 2.1. Шесть gold-эталонов — проверка «исходник + эталон на диске»

Проверено скриптом по `manifest.json` (`os.path.exists` для `estimates/files/<source_path>` и для
`gold_path`):

| Код | Исходник | `*.gold.json` |
|:--|:--|:--|
| `EST-GSFX-LSR-001` | OK | OK |
| `EST-XLS-KS2-001` | OK | OK |
| `EST-XLSX-KS2-001` | OK | OK |
| `EST-XLSX-KS2-003` | OK | OK |
| `EST-XLSX-LSR-001` | OK | OK |
| `EST-XLSX-VOR-001` | OK | OK |

Записей манифеста с непустым `gold_path` — ровно 6, все шесть разрешились. Остальные 18 записей
(`EST-PDF-SCAN-001`, `EST-XLS-KS3-001`, `NEG-*`) `gold_path` не несут — это негативы и
неразобранные форматы, для eval-прогона они не нужны.

### 2.2. `eval-splits.json` НЕ устарел

Шесть кодов файла сплитов — точное множество шести gold-эталонов манифеста:

```
holdout: EST-XLSX-KS2-001, EST-XLSX-LSR-001
tune:    EST-XLS-KS2-001, EST-XLSX-KS2-003, EST-GSFX-LSR-001, EST-XLSX-VOR-001
```

Расхождений нет ни в одну сторону. Версия (б) из `Pending Work` P2.2 («сплиты устарели, нужен
`--write-splits`») фактами не подтверждается: файл согласован с корпусом, пересборка сплитов
изменила бы разбиение без причины и обесценила бы сопоставимость с донорским контуром.
Единственная причина, по которой коды «не резолвятся», — пустой РЕЕСТР в БД, а не состав файла.

## 3. Диск эталонов — уже наполнен (29 файлов)

`Storage::disk(config('ai.fixtures.disk'))` (`estimates` → `storage/app/private/estimates`) несёт
все 24 исходника плюс 5 производных представлений бенчмарка P6.2 донора:

```
EST-GSFX-LSR-001/source.gsfx        EST-XLS-KS2-001/derived/bench/pdf_alt.pdf
EST-XLS-KS2-001/source.xls          EST-XLSX-KS2-001/derived/bench/pdf_alt.pdf
EST-XLSX-KS2-001/source.xlsx        EST-XLSX-KS2-003/derived/bench/pdf_alt.pdf
EST-XLSX-KS2-003/source.xlsx        EST-XLSX-LSR-001/derived/bench/pdf_alt.pdf
EST-XLSX-LSR-001/source.xlsx        EST-XLSX-VOR-001/derived/bench/pdf_alt.pdf
EST-XLSX-VOR-001/source.xlsx        (+ 13 NEG-*/… и EST-PDF-SCAN-001, EST-XLS-KS3-001,
                                       EST-XLSX-KS2-002, EST-XLSX-LSR-002/003)
```

Вывод: **потеряна ТОЛЬКО реляционная часть состояния** (строки реестра, рабочие копии, записи
датасета) — характерная картина `migrate:fresh` на dev-БД. Ни один байт корпуса не потерян.

## 4. Штатный путь восстановления существует и является каноном донора

Обе команды написаны и закрыты планом `2026.07.26-EST-TRAIN`, обе читают git-SSOT:

1. `estimate:fixtures:import` (`FixturesImportCommand`) — читает `manifest.json`, валидирует
   каждую запись контрактом `FixtureManifestEntry`, кладёт файл на диск эталонов, апсертит
   `prc_ai_fixtures`. **Идемпотентна по sha256**: повтор не перезаливает файл и не плодит записи
   (докблок команды). Файлы на диске уже есть (§3) — значит это, по сути, восстановление строк
   реестра с проверкой хешей.
2. `estimate:gold:import --prepare` (`GoldImportCommand`) — по реестру создаёт рабочие копии
   (`Document`/`DocumentVersion` с `procurement_id=null`) на диске документов, прогоняет
   `DocumentPreparer` синхронно и пишет эталонные ОТВЕТЫ из `estimates/gold/<CODE>.gold.json` в
   `prc_ai_dataset_examples` со ссылкой `fixture_id` и `source=deterministic`.
3. `estimate:fixtures:verify` (`FixturesVerifyCommand`) — сверка целостности, доступна как
   контроль после импорта.

**Стоимость восстановления — НОЛЬ платных вызовов.** `DocumentPreparer`
(`app/Services/Procurement/Prepare/DocumentPreparer.php:36-60`) — цепочка стратегий подготовки
(конвертация/представления), LLM в ней нет; скрининг и извлечение — отдельные стадии, командой
импорта не запускаются. Расход — только CPU/время контейнера на конвертацию xlsx→pdf.

## 5. Что это значит для D25

D25 запрещает **построение корпуса на лету** («создание эталонов», «подмена предмета
измерения»). Разбираемый случай — не построение: gold-эталоны, манифест и сплиты уже созданы
донором, лежат в git, защищены sha256 и НЕ меняются; речь о гидрации БД из этого замороженного
входа штатными командами донора. Различие фиксируется явно, потому что буквальное чтение
Implementation Rules P2.2 («если `prc_ai_dataset_examples` пуст … исполнитель ОСТАНАВЛИВАЕТСЯ»)
делает фазу неисполнимой на любом дереве со свежей БД — то есть навсегда, а не однократно.

Граница, которую снимать нельзя: если пустым окажется САМ git-SSOT (нет `manifest.json`,
`gold_dir` без `*.gold.json`, `eval-splits.json` ссылается на коды вне манифеста) — эскалация D25
остаётся обязательной, потому что тогда корпус пришлось бы именно СТРОИТЬ.

## 6. Риски восстановления (называются честно, решения не подменяют)

- **Представления пересоздаются на ЭТОМ образе.** `--prepare` заново гонит конвертацию; версия
  LibreOffice в текущем образе может отличаться от той, на которой донор снимал бенчмарк P6.2.
  Для P2 это безопасно: M0 и M0′ (D91) снимаются на ОДНОМ дереве и ОДНОМ корпусе, и все
  последующие дельты D8 считаются против них же. Сопоставимость с числами донора P2 не заявляет и
  не использует.
- **`derived/bench/pdf_alt.pdf` — артефакт харнесса бенчмарка**, а не рантайм-представление:
  боевой резолвер (`config('ai.representations')`, `fallback_to_source = true`) ищет представления
  рабочей копии на диске документов, который `--prepare` и наполняет. Путь `derived/bench/**` в
  этом контуре не участвует.
- **`EST-XLSX-KS2-001` в бенчмарке донора дисквалифицировал `pdf_alt` по token-limit харнесса**
  (`config/ai.php:477-483`) — техническая причина харнесса, не качество представления; в
  extraction-прогоне ограничение харнесса не действует, но эталон остаётся кандидатом на
  наблюдаемую аномалию — если она проявится в M0/M0′, это факт контура и публикуется, а не
  повод перемерить (Code Guidance P2.2).
