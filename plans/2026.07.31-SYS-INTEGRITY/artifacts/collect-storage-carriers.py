#!/usr/bin/env python3
"""Машинный инвентарь носителей файловых ссылок (P6.1, план 2026.07.31-SYS-INTEGRITY).

Запуск из корня репозитория agelto:

    python3 plans/2026.07.31-SYS-INTEGRITY/artifacts/collect-storage-carriers.py \
        > plans/2026.07.31-SYS-INTEGRITY/artifacts/storage-carriers.json

Что делает скрипт МАШИННО (пересобирается на любом дереве, поэтому инвентарь
воспроизводим, а не переписан руками):

- находит все колонки-кандидаты в `database/migrations/**` (в т.ч. в сырых SQL-миграциях
  партиционированных таблиц, где `$table->text()` не встречается вовсе);
- для каждого объявленного носителя собирает writer/reader call-site'ы грепом по имени
  колонки и по конфиг-ключу диска;
- собирает ВСЕ `Storage::disk(...)` call-site'ы `app/**` и группирует их по конфиг-ключу;
- проверяет ПОЛНОТУ: колонка-кандидат из миграций, не описанная в реестре `CARRIERS`,
  попадает в `completeness.unlisted_columns` — непустой список означает, что инвентарь
  отстал от схемы (гейт Validation P6.1).

Чего скрипт НЕ делает: не выносит вердиктов. Поля `verdict`/`reason` реестра —
АВТОРСКИЕ, их источник (`research/07-storage-addressing.md` §2) назван в каждой записи;
скрипт лишь переносит их в вывод рядом с машинными фактами, чтобы у JSON был один
адрес, а у вердикта — одна SSOT.
"""
from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

REPO_MARKER = "database/migrations"

# Реестр носителей. `verdict`/`reason` — авторские (research/07 §2), остальное машинное.
CARRIERS: list[dict] = [
    {
        "id": "prc_document_versions.storage_path",
        "table": "prc_document_versions",
        "column": "storage_path",
        "stores": "оригинал файла версии документа",
        "config_key": "crawler.documents_disk",
        "disk_column": None,
        "partitioned": False,
        "retention": "eternal (в `partitioning.retention` не входит)",
        "writers": [
            "app/Services/Procurement/DocumentDownloader.php:181-191 — боевое скачивание",
            "app/Services/Procurement/Fixture/WorkingCopyProvisioner.php:71-90 — рабочая копия эталона",
            "app/Services/Procurement/Analyzers/Estimate/DatasetImporter.php:131-160 — импорт датасета",
            "app/Services/Procurement/Prepare/ArchiveStrategy.php:196-206 — дочерняя версия из архива",
            "app/Services/Procurement/Prepare/GsfxStrategy.php:293-303 — дочерняя версия из gsfx-zip",
        ],
        "readers": [
            "app/Services/Procurement/Prepare/RepresentationResolver.php:66-71 — фолбэк на оригинал версии",
            "app/Services/Procurement/Prepare/{Spreadsheet,Word,Archive,Gsfx,Scan}Strategy.php — вход подготовки",
            "app/Services/Procurement/Bench/FormatBenchRunner.php:429 — вход бенчмарка форматов",
        ],
        "verdict": "нужна",
        "reason": "В1 ✓ (запись адресует файл) и В2 ✓ (строка и файл вечные — строки одного "
                  "носителя могут указывать в разные поколения диска). Подтверждает D50.",
    },
    {
        "id": "prc_ai_fixtures.storage_path",
        "table": "prc_ai_fixtures",
        "column": "storage_path",
        "stores": "исходный файл эталона корпуса",
        "config_key": "ai.fixtures.disk",
        "disk_column": None,
        "partitioned": False,
        "retention": "eternal (курируемый корпус)",
        "writers": [
            "app/Services/Procurement/Fixture/FixtureUploader.php:59-66 — загрузка через Filament",
            "app/Services/Procurement/Fixture/FixtureRegistrar.php:75-81 — импорт манифеста",
        ],
        "readers": [
            "app/Services/Procurement/Fixture/FixtureVerifier.php:72-79 — верификация корпуса",
            "app/Services/Procurement/Fixture/WorkingCopyProvisioner.php:74 — чтение под рабочую копию",
            "app/Services/Procurement/Gold/GoldCorpusBuilder.php:162-165 — построение gold (->path(), только local)",
        ],
        "verdict": "нужна",
        "reason": "В1 ✓ и В2 ✓. Подтверждает D50. Конфиг-ключ ОТЛИЧАЕТСЯ от документов "
                  "(`ai.fixtures.disk`), комментарий колонки обязан называть именно его.",
    },
    {
        "id": "prc_estimates.raw_result_ref",
        "table": "prc_estimates",
        "column": "raw_result_ref",
        "stores": "сырой ответ LLM по смете при payload > ~1 МБ",
        "config_key": "crawler.raw_disk",
        "disk_column": None,
        "partitioned": False,
        "retention": "eternal (`partitioning.eternal` содержит `prc_estimates`); префикс "
                     "`ai/estimates/` под ILM-правило НЕ попадает (`partitioning.minio_lifecycle`)",
        "writers": [
            "app/Services/Procurement/Analyzers/Estimate/EstimatePersister.php:344-356 — overflow > 1 МБ",
        ],
        "readers": [
            "— читателя в дереве нет: колонка пишется и живёт как провенанс, потребитель ещё не написан",
        ],
        "verdict": "нужна",
        "reason": "В1 ✓ и В2 ✓ — РАСХОЖДЕНИЕ с пред-вердиктом D50 «отладочный носитель». "
                  "Единственный носитель класса «вечная строка + вечный файл» вне двух "
                  "подтверждённых; не партиционирован, ILM его не чистит. D114.",
    },
    {
        "id": "prc_document_representations.storage_path",
        "table": "prc_document_representations",
        "column": "storage_path",
        "stores": "подготовленное представление версии (PDF, текст, structured XML)",
        "config_key": "crawler.documents_disk",
        "disk_column": "disk",
        "partitioned": False,
        "retention": "eternal",
        "writers": [
            "app/Services/Procurement/Prepare/SpreadsheetStrategy.php:165,186 — пишет литеральный null",
            "app/Services/Procurement/Prepare/WordStrategy.php:61 — пишет литеральный null",
            "app/Services/Procurement/Prepare/ScanStrategy.php:141,154 — пишет литеральный null",
            "app/Services/Procurement/Prepare/GsfxStrategy.php:146,158 — пишет литеральный null",
            "app/Services/Procurement/Bench/FormatBenchRunner.php:440 — ЕДИНСТВЕННЫЙ пишет реальное имя",
        ],
        "readers": [
            "app/Services/Procurement/Prepare/RepresentationResolver.php:44,56 — disk ?? config",
            "app/Services/Procurement/Analyzers/Estimate/DatasetInputBuilder.php:95 — disk ?? config, В ОБХОД резолвера",
        ],
        "verdict": "колонка уже есть — миграция не нужна, писатели нужны",
        "reason": "Прецедент `100055:33`. Машинный факт: семь боевых writer-call-site'ов "
                  "пишут ЛИТЕРАЛЬНЫЙ `null`, реальное имя диска пишет один call-site "
                  "бенчмарка. Носитель входит в периметр P6.3 без миграции. D115.",
    },
    {
        "id": "llm_calls.request_ref",
        "table": "llm_calls",
        "column": "request_ref",
        "stores": "сырой request LLM-вызова",
        "config_key": "crawler.raw_disk",
        "disk_column": None,
        "partitioned": True,
        "retention": "строка 12 мес (`partitioning.retention.llm_calls`); блоб — ILM "
                     "`ai/llm-calls/` expire 90 дней",
        "writers": ["app/Services/Llm/LlmCallRecorder.php:117-125 — storeRaw()"],
        "readers": ["app/Services/Llm/LlmPayloadReader.php:26-49 — витрина Filament"],
        "verdict": "не нужна",
        "reason": "В2 ✗: блоб умирает по ILM РАНЬШЕ строки (90 дней против 12 мес) — "
                  "адресовать через полгода нечего. Плюс цена: таблица партиционирована и "
                  "создана сырым SQL, колонка = правка партиционированной схемы.",
    },
    {
        "id": "llm_calls.response_ref",
        "table": "llm_calls",
        "column": "response_ref",
        "stores": "сырой response LLM-вызова",
        "config_key": "crawler.raw_disk",
        "disk_column": None,
        "partitioned": True,
        "retention": "строка 12 мес; блоб — ILM `ai/llm-calls/` expire 90 дней",
        "writers": ["app/Services/Llm/LlmCallRecorder.php:117-125 — storeRaw()"],
        "readers": ["app/Services/Llm/LlmPayloadReader.php:26-49 — витрина Filament"],
        "verdict": "не нужна",
        "reason": "Тот же носитель и тот же писатель, что `request_ref`; причина та же.",
    },
    {
        "id": "prc_fetches.artifact_ref",
        "table": "prc_fetches",
        "column": "artifact_ref",
        "stores": "сырой CSV-блок листинга",
        "config_key": "crawler.raw_disk",
        "disk_column": None,
        "partitioned": True,
        "retention": "строка 6 мес (DROP партиции); блоб `raw/csv/` под ILM НЕ попадает",
        "writers": ["app/Services/Procurement/CsvBlockIngestor.php:143-152 — storeRawArtifact()"],
        "readers": ["— читателя в дереве нет"],
        "verdict": "не нужна",
        "reason": "В2 ✗ со стороны ЗАПИСИ: строка умирает через 6 месяцев, то есть носитель "
                  "не доживает до второго поколения диска. Артефакт — расходник прогона "
                  "листинга. Плюс цена партиционированной схемы (сырой SQL).",
    },
    {
        "id": "prc_ai_dataset_examples.gold_ref",
        "table": "prc_ai_dataset_examples",
        "column": "gold_ref",
        "stores": "крупный gold-эталон в object storage (носитель заведён «на вырост»)",
        "config_key": None,
        "disk_column": None,
        "partitioned": False,
        "retention": "eternal (`partitioning.eternal`)",
        "writers": ["— писателя нет: app/Services/Procurement/Analyzers/Estimate/GoldExampleRecorder.php:78 пишет null"],
        "readers": ["— читателя нет"],
        "verdict": "не нужна СЕЙЧАС",
        "reason": "В1 ✗ по факту дерева: писателя нет — `GoldExampleRecorder` пишет "
                  "литеральный `null`, конфиг-ключ не определён. Условие пересмотра названо: "
                  "появится писатель — колонка заводится ТЕМ ЖЕ item'ом (В2 выполнено бы, "
                  "таблица вечная).",
    },
]

# Файлы без записи-носителя: адрес выводится конвенцией/аргументом, колонка невозможна.
CONVENTION_ADDRESSED: list[dict] = [
    {
        "id": "raw-html-snapshots",
        "stores": "сырой HTML вкладок карточки, `raw/html/{registryId}/{registryNumber}/{tab}.html.gz`",
        "config_key": "crawler.raw_html_disk",
        "verdict": "не нужна",
        "reason": "Записи в БД нет вовсе — адресовать нечем. Писатель за флагом "
                  "`crawler.raw_html_retention_enabled` (по умолчанию `false`).",
    },
    {
        "id": "dataset-export",
        "stores": "выгрузка датасета под локальную LLM",
        "config_key": "crawler.raw_disk (или опция --disk)",
        "verdict": "не нужна",
        "reason": "Продукт команды, а не персистентная ссылка: диск задаётся опцией запуска, "
                  "запись в БД не создаётся.",
    },
    {
        "id": "fixture-working-copy",
        "stores": "рабочая копия эталона на диске документов",
        "config_key": "crawler.documents_disk",
        "verdict": "покрыт носителем prc_document_versions.storage_path",
        "reason": "ПОПРАВКА к §12.2 разведки: `WorkingCopyProvisioner` не просто кладёт файл "
                  "по хэшу — он СОЗДАЁТ строку `prc_document_versions`. Это не «файл без "
                  "записи-носителя», а пятый writer-путь носителя №1.",
    },
]

CONFIG_DISK_KEYS = [
    "crawler.documents_disk",
    "crawler.raw_disk",
    "crawler.raw_html_disk",
    "ai.fixtures.disk",
]

# Колонки, похожие на адрес файла, но им НЕ являющиеся — исключаются из проверки полноты
# поимённо, чтобы список не молчал, а объяснялся.
NON_CARRIER_COLUMNS = {
    "prc_ai_fixtures.source_path": "относительный путь в корпусе-ИСТОЧНИКЕ (провенанс), не адрес на диске",
    "prc_ai_corrections.field_path": "JSONPath поля внутри результата, к файлам отношения не имеет",
    "prc_events.entity_ref": "jsonb-ссылка на сущность БД, не на файл",
    "prc_document_versions.prepared_storage_path": "колонка УДАЛЕНА миграцией 100056 (D25 плана-донора)",
}

COLUMN_CANDIDATE = re.compile(
    r"""(?:->text\(\s*'(?P<eloquent>[a-z_]*(?:storage_path|_ref|_path))'|"""
    r"""^\s*(?P<raw>[a-z_]*(?:storage_path|_ref|_path))\s+(?:text|jsonb)\b)""",
    re.MULTILINE,
)
CREATE_TABLE_ELOQUENT = re.compile(r"create_([a-z0-9_]+)_table\.php$")
CREATE_TABLE_RAW = re.compile(r"CREATE TABLE (\w+)")


def repo_root() -> Path:
    path = Path.cwd()
    for candidate in [path, *path.parents]:
        if (candidate / REPO_MARKER).is_dir():
            return candidate
    sys.exit(f"не найден корень репозитория (нет каталога {REPO_MARKER}) от {path}")


def grep(root: Path, pattern: str, *paths: str) -> list[str]:
    """`grep -rn` без падения на «ничего не найдено» (exit 1)."""
    result = subprocess.run(
        ["grep", "-rn", "--", pattern, *paths],
        cwd=root, capture_output=True, text=True,
    )
    if result.returncode not in (0, 1):
        sys.exit(f"grep {pattern!r} упал: {result.stderr.strip()}")
    return [line for line in result.stdout.splitlines() if line.strip()]


def site(line: str) -> dict:
    path, number, text = line.split(":", 2)
    return {"at": f"{path}:{number}", "code": text.strip()}


def declared_columns(root: Path) -> dict[str, str]:
    """Колонки-кандидаты из миграций: `table.column` → файл объявления."""
    found: dict[str, str] = {}
    for migration in sorted((root / "database" / "migrations").glob("*.php")):
        body = migration.read_text(encoding="utf-8")
        if migration.name.startswith("2026") and "drop" in migration.name:
            continue
        raw_tables = CREATE_TABLE_RAW.findall(body)
        eloquent = CREATE_TABLE_ELOQUENT.search(migration.name)
        table = raw_tables[0] if raw_tables else (eloquent.group(1) if eloquent else None)
        if table is None:
            continue
        for match in COLUMN_CANDIDATE.finditer(body):
            column = match.group("eloquent") or match.group("raw")
            found.setdefault(f"{table}.{column}", f"database/migrations/{migration.name}")
    return found


def main() -> int:
    root = repo_root()
    head = subprocess.run(["git", "rev-parse", "--short", "HEAD"], cwd=root,
                          capture_output=True, text=True).stdout.strip()

    columns = declared_columns(root)

    carriers = []
    for carrier in CARRIERS:
        column_sites = grep(root, f"'{carrier['column']}'", "app/")
        column_sites += grep(root, f"->{carrier['column']}", "app/")
        carriers.append({
            **carrier,
            "declared_in": columns.get(carrier["id"], "НЕ НАЙДЕНА В МИГРАЦИЯХ"),
            "verdict_source": "research/07-storage-addressing.md §2",
            # НАДМНОЖЕСТВО: грепается имя колонки, а `storage_path` носят три носителя
            # сразу — разделение по носителям сделано чтением кода и живёт в
            # `writers`/`readers` выше (findings/P6-file-carriers.md §3 несёт тот же
            # разбор со ссылками на строки).
            "column_name_sites": sorted({s["at"]: s for s in map(site, column_sites)}.values(),
                                        key=lambda s: s["at"]),
        })

    by_config_key = {}
    for key in CONFIG_DISK_KEYS:
        lines = grep(root, f"config('{key}'", "app/")
        by_config_key[key] = [site(line) for line in lines]

    storage_disk_sites = [site(line) for line in grep(root, "Storage::disk", "app/")]

    unlisted = sorted(
        column for column in columns
        if column not in {c["id"] for c in CARRIERS} and column not in NON_CARRIER_COLUMNS
    )

    document = {
        "$schema": "плоский инвентарь P6.1 — потребители: research/07-storage-addressing.md, P6.2/P6.3/P6.5",
        "generated_by": "plans/2026.07.31-SYS-INTEGRITY/artifacts/collect-storage-carriers.py",
        "tree": head,
        "carriers": carriers,
        "convention_addressed": CONVENTION_ADDRESSED,
        "config_disk_keys": {
            key: {"value_on_tree": None, "sites": sites}
            for key, sites in by_config_key.items()
        },
        "storage_disk_call_sites": {
            "total": len(storage_disk_sites),
            "sites": storage_disk_sites,
        },
        "completeness": {
            "declared_path_columns": columns,
            "non_carrier_columns": NON_CARRIER_COLUMNS,
            "unlisted_columns": unlisted,
            "verdict": "полон" if not unlisted else "ОТСТАЛ ОТ СХЕМЫ",
        },
    }

    json.dump(document, sys.stdout, ensure_ascii=False, indent=2)
    sys.stdout.write("\n")
    return 0 if not unlisted else 1


if __name__ == "__main__":
    raise SystemExit(main())
