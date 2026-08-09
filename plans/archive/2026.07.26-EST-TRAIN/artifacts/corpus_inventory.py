#!/usr/bin/env python3
"""Машинный инвентарь корпуса смет `estimates/files` (P1.1, план 2026.07.26-EST-TRAIN).

Снимает со ВСЕХ файлов корпуса детерминированную фактуру: путь, расширение, размер,
sha256, mime, признак мусора — а для читаемых форматов ещё и структуру: листы книги с
использованным диапазоном, объединениями и первыми строками; страницы и текстовый слой
PDF; паспорт и частоты XML-элементов контейнера ГрандСметы.

Только stdlib (D15): ни одной сторонней зависимости, окружение воспроизводится на другой
машине без установок. Внешние бинари (`libreoffice`, `pdfinfo`, `pdftotext`, `file`)
зовутся списком аргументов без shell — в именах корпуса живут пробелы, кириллица, `!`,
`+`, `№` и вложенные каталоги.

Вывод детерминирован: ни абсолютных путей, ни меток времени, порядок записей — сортировка
по POSIX-пути относительно корня. Повторный прогон обязан дать байт-идентичный JSON.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import tempfile
import zipfile
from collections import Counter
from pathlib import Path
from xml.etree import ElementTree as ET

# --- Константы разбора ---------------------------------------------------------------

# Порог эвристики текстового слоя PDF: непробельных символов на страницу выборки.
# 0 → `none` (скан), меньше порога → `sparse` (штампы/колонтитулы поверх скана),
# иначе → `full`. Единственная эвристика в скрипте; всё остальное — прямые факты файла.
TEXT_LAYER_SPARSE_MAX = 50

# Сколько первых страниц PDF отдаётся `pdftotext` для оценки текстового слоя.
TEXT_LAYER_SAMPLE_PAGES = 3

# Сколько первых строк листа попадает в инвентарь (шапка + начало таблицы).
FIRST_ROWS = 15

# Потолок длины значения ячейки: длинные наименования объектов не нужны целиком, чтобы
# судить о форме, а JSON инвентаря лежит в git. Обрезанная ячейка помечается `truncated`.
MAX_CELL_CHARS = 300

# Таймауты внешних бинарей, секунды. Конверсия .xls — самая долгая операция прогона.
TIMEOUT_FILE = 30
TIMEOUT_PDFINFO = 120
TIMEOUT_PDFTOTEXT = 300
TIMEOUT_LIBREOFFICE = 300

NOISE_NAMES = {"desktop.ini"}

SPREADSHEET_NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
RELS_NS = "http://schemas.openxmlformats.org/package/2006/relationships"
DOC_REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"

CELL_REF_RE = re.compile(r"^([A-Z]+)")


# --- Мелкие помощники ----------------------------------------------------------------

def local_name(tag: str) -> str:
    """Имя элемента без пространства имён: `{ns}row` → `row`."""
    return tag.rsplit("}", 1)[-1]


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def run(args: list[str], timeout: int) -> subprocess.CompletedProcess:
    return subprocess.run(args, shell=False, timeout=timeout, capture_output=True)


def decode_output(raw: bytes) -> str:
    return raw.decode("utf-8", errors="replace")


def clip(value: str) -> tuple[str, bool]:
    if len(value) <= MAX_CELL_CHARS:
        return value, False
    return value[:MAX_CELL_CHARS], True


def error(stage: str, message: str) -> dict:
    return {"stage": stage, "message": message}


def mime_of(path: Path, errors: list) -> str | None:
    try:
        completed = run(["file", "--brief", "--mime-type", str(path)], TIMEOUT_FILE)
    except (OSError, subprocess.SubprocessError) as exc:
        errors.append(error("file", f"{type(exc).__name__}: {exc}"))
        return None
    if completed.returncode != 0:
        errors.append(error("file", f"exit {completed.returncode}: {decode_output(completed.stderr).strip()}"))
        return None
    return decode_output(completed.stdout).strip() or None


# --- Ветка xlsx (и xls после конверсии) ----------------------------------------------

def read_shared_strings(book: zipfile.ZipFile) -> list[str]:
    """`sharedStrings.xml` → плоский список строк; каждая `<si>` склеивается из своих `<t>`."""
    if "xl/sharedStrings.xml" not in book.namelist():
        return []
    strings: list[str] = []
    with book.open("xl/sharedStrings.xml") as stream:
        current: list[str] = []
        for event, element in ET.iterparse(stream, events=("start", "end")):
            name = local_name(element.tag)
            if event == "start" and name == "si":
                current = []
            elif event == "end" and name == "t":
                current.append(element.text or "")
            elif event == "end" and name == "si":
                strings.append("".join(current))
                element.clear()
    return strings


def sheet_targets(book: zipfile.ZipFile) -> list[dict]:
    """Листы книги в порядке `xl/workbook.xml` с резолвом `r:id` через `workbook.xml.rels`."""
    rels: dict[str, str] = {}
    if "xl/_rels/workbook.xml.rels" in book.namelist():
        root = ET.fromstring(book.read("xl/_rels/workbook.xml.rels"))
        for element in root:
            if local_name(element.tag) != "Relationship":
                continue
            rels[element.attrib.get("Id", "")] = element.attrib.get("Target", "")

    sheets: list[dict] = []
    root = ET.fromstring(book.read("xl/workbook.xml"))
    for sheets_element in root:
        if local_name(sheets_element.tag) != "sheets":
            continue
        for sheet in sheets_element:
            if local_name(sheet.tag) != "sheet":
                continue
            rel_id = sheet.attrib.get(f"{{{DOC_REL_NS}}}id", "")
            target = rels.get(rel_id, "")
            if target.startswith("/"):
                entry = target.lstrip("/")
            elif target.startswith("../"):
                entry = target[3:]
            else:
                entry = f"xl/{target}" if target else ""
            sheets.append({
                "name": sheet.attrib.get("name", ""),
                "state": sheet.attrib.get("state", "visible"),
                "entry": entry,
            })
    return sheets


def read_sheet(book: zipfile.ZipFile, entry: str, strings: list[str]) -> dict:
    """Использованный диапазон, число объединений и первые строки одного листа."""
    dimension = None
    merge_count = 0
    merges: list[str] = []
    rows: list[dict] = []
    row_count = 0
    current_row: dict | None = None
    cell: dict | None = None
    cell_value_parts: list[str] = []

    with book.open(entry) as stream:
        for event, element in ET.iterparse(stream, events=("start", "end")):
            name = local_name(element.tag)
            if event == "start":
                if name == "row":
                    row_count += 1
                    if row_count <= FIRST_ROWS:
                        current_row = {"row": int(element.attrib.get("r", row_count)), "cells": []}
                    else:
                        current_row = None
                elif name == "c" and current_row is not None:
                    cell = {"ref": element.attrib.get("r", ""), "type": element.attrib.get("t", "n")}
                    cell_value_parts = []
                continue

            if name == "dimension" and dimension is None:
                dimension = element.attrib.get("ref")
            elif name == "mergeCell":
                merge_count += 1
                ref = element.attrib.get("ref")
                if ref:
                    merges.append(ref)
            elif name == "t" and cell is not None:
                cell_value_parts.append(element.text or "")
            elif name == "v" and cell is not None:
                cell_value_parts.append(element.text or "")
            elif name == "c" and cell is not None and current_row is not None:
                raw = "".join(cell_value_parts)
                if cell["type"] == "s" and raw.isdigit() and int(raw) < len(strings):
                    raw = strings[int(raw)]
                if raw != "":
                    value, truncated = clip(raw)
                    record = {"ref": cell["ref"], "value": value}
                    if truncated:
                        record["truncated"] = True
                    current_row["cells"].append(record)
                cell = None
                cell_value_parts = []
            elif name == "row":
                if current_row is not None:
                    rows.append(current_row)
                    current_row = None
                element.clear()

    return {
        "dimension": dimension,
        "merge_count": merge_count,
        "merges": merges[:FIRST_ROWS],
        "row_count": row_count,
        "first_rows": rows,
    }


def inspect_xlsx(path: Path, errors: list) -> dict:
    """Книга OOXML: имена листов, использованный диапазон, объединения, первые строки."""
    result: dict = {"sheets": []}
    with zipfile.ZipFile(path) as book:
        strings = read_shared_strings(book)
        result["shared_strings"] = len(strings)
        entries = set(book.namelist())
        for sheet in sheet_targets(book):
            record = {"name": sheet["name"], "state": sheet["state"]}
            entry = sheet["entry"]
            if entry not in entries:
                record["error"] = f"лист не найден в контейнере: {entry!r}"
                errors.append(error("xlsx", f"лист {sheet['name']!r}: entry {entry!r} отсутствует"))
                result["sheets"].append(record)
                continue
            try:
                record.update(read_sheet(book, entry, strings))
            except (ET.ParseError, zipfile.BadZipFile, KeyError, ValueError) as exc:
                record["error"] = f"{type(exc).__name__}: {exc}"
                errors.append(error("xlsx", f"лист {sheet['name']!r}: {type(exc).__name__}: {exc}"))
            result["sheets"].append(record)
    return result


def inspect_xls(path: Path, errors: list) -> dict:
    """Старый бинарный `.xls` — конверсия LibreOffice во временный каталог, дальше ветка xlsx."""
    tmpdir = tempfile.mkdtemp(prefix="corpus-inventory-")
    try:
        profile = Path(tmpdir) / "lo-profile"
        completed = run([
            "libreoffice",
            f"-env:UserInstallation=file://{profile}",
            "--headless",
            "--convert-to", "xlsx",
            "--outdir", tmpdir,
            str(path),
        ], TIMEOUT_LIBREOFFICE)
        produced = sorted(Path(tmpdir).glob("*.xlsx"))
        if not produced:
            errors.append(error("libreoffice", (
                f"конверсия не дала .xlsx (exit {completed.returncode}): "
                f"{decode_output(completed.stderr).strip() or decode_output(completed.stdout).strip()}"
            )))
            return {"converted_via": "libreoffice", "converted": False, "sheets": []}
        result = inspect_xlsx(produced[0], errors)
        result["converted_via"] = "libreoffice"
        result["converted"] = True
        return result
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


# --- Ветка pdf -----------------------------------------------------------------------

def inspect_pdf(path: Path, errors: list) -> dict:
    """Страницы, размер страницы и генератор — из `pdfinfo`; текстовый слой — из `pdftotext`."""
    result: dict = {"pages": None, "page_size": None, "producer": None, "creator": None,
                    "encrypted": None, "text_layer": None, "text_chars": None,
                    "chars_per_page": None, "sampled_pages": None}

    completed = run(["pdfinfo", str(path)], TIMEOUT_PDFINFO)
    if completed.returncode != 0:
        errors.append(error("pdfinfo", f"exit {completed.returncode}: {decode_output(completed.stderr).strip()}"))
    info: dict[str, str] = {}
    for line in decode_output(completed.stdout).splitlines():
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        info[key.strip()] = value.strip()
    if "Pages" in info and info["Pages"].isdigit():
        result["pages"] = int(info["Pages"])
    result["page_size"] = info.get("Page size")
    result["producer"] = info.get("Producer")
    result["creator"] = info.get("Creator")
    result["encrypted"] = info.get("Encrypted")

    sample = min(TEXT_LAYER_SAMPLE_PAGES, result["pages"] or TEXT_LAYER_SAMPLE_PAGES)
    completed = run(["pdftotext", "-f", "1", "-l", str(sample), str(path), "-"], TIMEOUT_PDFTOTEXT)
    if completed.returncode != 0:
        errors.append(error("pdftotext", f"exit {completed.returncode}: {decode_output(completed.stderr).strip()}"))
        return result
    text = decode_output(completed.stdout)
    chars = len("".join(text.split()))
    per_page = chars / sample if sample else 0
    result["text_chars"] = chars
    result["sampled_pages"] = sample
    result["chars_per_page"] = round(per_page, 2)
    result["text_layer"] = "none" if chars == 0 else ("sparse" if per_page < TEXT_LAYER_SPARSE_MAX else "full")
    return result


# --- Ветка gsfx ----------------------------------------------------------------------

def inspect_gsfx(path: Path, errors: list) -> dict:
    """Контейнер ГрандСметы: паспорт `Properties.txt` и частоты элементов `Data.xml` (оба cp1251)."""
    result: dict = {"entries": [], "properties": {}, "elements": {}, "document_attributes": {}}
    with zipfile.ZipFile(path) as container:
        for info in sorted(container.infolist(), key=lambda item: item.filename):
            result["entries"].append({"name": info.filename, "size": info.file_size})
        names = set(container.namelist())

        if "Properties.txt" in names:
            raw = container.read("Properties.txt").decode("cp1251", errors="replace")
            for line in raw.splitlines():
                line = line.strip()
                if not line or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                result["properties"][key.strip()] = value.strip()
        else:
            errors.append(error("gsfx", "в контейнере нет Properties.txt"))

        if "Data.xml" in names:
            counter: Counter = Counter()
            with container.open("Data.xml") as stream:
                for event, element in ET.iterparse(stream, events=("end",)):
                    counter[local_name(element.tag)] += 1
                    if local_name(element.tag) == "Document":
                        result["document_attributes"] = dict(element.attrib)
                    element.clear()
            result["elements"] = dict(counter)
        else:
            errors.append(error("gsfx", "в контейнере нет Data.xml"))
    return result


# --- Ветка docx ----------------------------------------------------------------------

def inspect_docx(path: Path, errors: list) -> dict:
    """Документ Word: число абзацев и таблиц, первые непустые абзацы."""
    result: dict = {"paragraphs": 0, "tables": 0, "first_paragraphs": []}
    with zipfile.ZipFile(path) as document:
        if "word/document.xml" not in document.namelist():
            errors.append(error("docx", "в контейнере нет word/document.xml"))
            return result
        with document.open("word/document.xml") as stream:
            texts: list[str] = []
            for event, element in ET.iterparse(stream, events=("end",)):
                name = local_name(element.tag)
                if name == "tbl":
                    result["tables"] += 1
                elif name == "p":
                    result["paragraphs"] += 1
                    if len(result["first_paragraphs"]) < FIRST_ROWS:
                        text = "".join(node.text or "" for node in element.iter()
                                       if local_name(node.tag) == "t").strip()
                        if text:
                            value, truncated = clip(text)
                            texts.append(value)
                            result["first_paragraphs"].append(
                                {"text": value, "truncated": True} if truncated else {"text": value})
                    element.clear()
    return result


# --- Диспетчер -----------------------------------------------------------------------

def inspect_other(path: Path, errors: list) -> dict:
    """Формат без ветки разбора: факт остаётся в инвентаре, но структуры у него нет."""
    return {}


def inspect_file(path: Path, root: Path) -> dict:
    relative = path.relative_to(root).as_posix()
    ext = path.suffix.lower().lstrip(".")
    errors: list = []
    record: dict = {
        "path": relative,
        "name": path.name,
        "ext": ext,
        "size": path.stat().st_size,
        "sha256": sha256_of(path),
        "is_noise": path.name.lower() in NOISE_NAMES,
        "noise_reason": "мусор Windows: desktop.ini" if path.name.lower() in NOISE_NAMES else None,
        "parsed": False,
        "errors": errors,
    }
    record["mime"] = mime_of(path, errors)

    branch = {"xlsx": "xlsx", "xls": "xls", "pdf": "pdf", "gsfx": "gsfx", "docx": "docx"}.get(ext, "other")
    record["branch"] = branch
    inspector = {
        "xlsx": inspect_xlsx,
        "xls": inspect_xls,
        "pdf": inspect_pdf,
        "gsfx": inspect_gsfx,
        "docx": inspect_docx,
        "other": inspect_other,
    }[branch]

    if branch == "other":
        record["parsed"] = False
        return record

    before = len(errors)
    try:
        payload = inspector(path, errors)
    except Exception as exc:  # ветка формата упала — прогон продолжается, причина в errors[]
        errors.append(error(branch, f"{type(exc).__name__}: {exc}"))
        return record

    if branch in ("xlsx", "xls"):
        record["sheets"] = payload.pop("sheets", [])
        record.update(payload)
    else:
        record[branch] = payload
    record["parsed"] = len(errors) == before
    return record


def build_inventory(root: Path) -> dict:
    files = sorted((p for p in root.rglob("*") if p.is_file()),
                   key=lambda p: p.relative_to(root).as_posix())
    records = [inspect_file(path, root) for path in files]
    payload = [record for record in records if not record["is_noise"]]
    return {
        "schema_version": 1,
        "root": root.as_posix(),
        "totals": {
            "files": len(records),
            "payload_files": len(payload),
            "noise_files": len(records) - len(payload),
            "bytes": sum(record["size"] for record in records),
            "payload_bytes": sum(record["size"] for record in payload),
            "unique_sha256": len({record["sha256"] for record in payload}),
            "parsed": sum(1 for record in payload if record["parsed"]),
            "with_errors": sum(1 for record in records if record["errors"]),
            "by_ext": dict(sorted(Counter(record["ext"] for record in records).items())),
        },
        "constants": {
            "text_layer_sparse_max": TEXT_LAYER_SPARSE_MAX,
            "text_layer_sample_pages": TEXT_LAYER_SAMPLE_PAGES,
            "first_rows": FIRST_ROWS,
            "max_cell_chars": MAX_CELL_CHARS,
        },
        "files": records,
    }


# --- Человекочитаемый срез ------------------------------------------------------------

def human_size(size: int) -> str:
    return f"{size / 1024 / 1024:.1f} МБ" if size >= 1024 * 1024 else f"{size / 1024:.1f} КБ"


def render_report(inventory: dict) -> str:
    records = inventory["files"]
    payload = [record for record in records if not record["is_noise"]]
    totals = inventory["totals"]
    lines: list[str] = []

    lines.append("# Инвентарь корпуса `estimates/files` (P1.1)")
    lines.append("")
    lines.append("> Машинный срез, снятый `artifacts/corpus_inventory.py`. Источник истины — "
                 "`P1-corpus-inventory.json`; этот файл лишь читаемое отражение и не содержит "
                 "суждений о форме документа (это скоуп P1.2).")
    lines.append("")
    lines.append("## 1. Объём")
    lines.append("")
    lines.append("| Метрика | Значение |")
    lines.append("|:--|:--|")
    lines.append(f"| Файлов всего | {totals['files']} |")
    lines.append(f"| Из них мусор (`desktop.ini`) | {totals['noise_files']} |")
    lines.append(f"| Полезных файлов | {totals['payload_files']} |")
    lines.append(f"| Уникальных по sha256 (полезные) | {totals['unique_sha256']} |")
    lines.append(f"| Суммарный объём | {human_size(totals['bytes'])} |")
    lines.append(f"| Разобрано веткой формата | {totals['parsed']} |")
    lines.append(f"| Записей с `errors[]` | {totals['with_errors']} |")
    lines.append("")

    lines.append("## 2. Распределение по расширениям")
    lines.append("")
    lines.append("| Расширение | Файлов | Объём | Разобрано |")
    lines.append("|:--|--:|--:|--:|")
    by_ext: dict[str, list] = {}
    for record in records:
        by_ext.setdefault(record["ext"], []).append(record)
    for ext in sorted(by_ext, key=lambda key: (-len(by_ext[key]), key)):
        group = by_ext[ext]
        lines.append(f"| `{ext or '—'}` | {len(group)} | {human_size(sum(r['size'] for r in group))} | "
                     f"{sum(1 for r in group if r['parsed'])} |")
    lines.append("")

    lines.append("## 3. PDF: текстовый слой")
    lines.append("")
    lines.append(f"Эвристика — непробельных символов на страницу выборки (первых "
                 f"{TEXT_LAYER_SAMPLE_PAGES} страниц документа): `0` → `none`, "
                 f"`< {TEXT_LAYER_SPARSE_MAX}` → `sparse`, иначе `full`.")
    lines.append("")
    pdfs = [record for record in payload if record["branch"] == "pdf"]
    layers = Counter(record["pdf"]["text_layer"] for record in pdfs)
    lines.append("| Текстовый слой | Файлов | Страниц суммарно |")
    lines.append("|:--|--:|--:|")
    for layer in ("none", "sparse", "full", None):
        group = [record for record in pdfs if record["pdf"]["text_layer"] == layer]
        if not group:
            continue
        pages = sum(record["pdf"]["pages"] or 0 for record in group)
        lines.append(f"| `{layer or '—'}` | {layers[layer]} | {pages} |")
    lines.append("")
    lines.append("Крупнейшие PDF:")
    lines.append("")
    lines.append("| Файл | Размер | Страниц | Слой | Producer |")
    lines.append("|:--|--:|--:|:--|:--|")
    for record in sorted(pdfs, key=lambda r: -r["size"])[:10]:
        pdf = record["pdf"]
        lines.append(f"| `{record['path']}` | {human_size(record['size'])} | {pdf['pages']} | "
                     f"`{pdf['text_layer']}` | {pdf['producer'] or '—'} |")
    lines.append("")

    lines.append("## 4. Книги: листы и объединения")
    lines.append("")
    books = [record for record in payload if record["branch"] in ("xlsx", "xls")]
    multi = [record for record in books if len(record.get("sheets", [])) > 1]
    lines.append(f"Книг всего — {len(books)} (`xlsx` {sum(1 for r in books if r['ext'] == 'xlsx')}, "
                 f"`xls` {sum(1 for r in books if r['ext'] == 'xls')}, все `.xls` прошли через "
                 f"конверсию LibreOffice во временный каталог). Многолистовых — {len(multi)}.")
    lines.append("")
    lines.append("| Файл | Листов | Объединений | Строк (лист 1) | Диапазон (лист 1) |")
    lines.append("|:--|--:|--:|--:|:--|")
    for record in sorted(books, key=lambda r: (-len(r.get("sheets", [])), r["path"]))[:15]:
        sheets = record.get("sheets", [])
        first = sheets[0] if sheets else {}
        lines.append(f"| `{record['path']}` | {len(sheets)} | "
                     f"{sum(sheet.get('merge_count', 0) for sheet in sheets)} | "
                     f"{first.get('row_count', '—')} | `{first.get('dimension') or '—'}` |")
    lines.append("")

    lines.append("## 5. Контейнер ГрандСметы")
    lines.append("")
    for record in payload:
        if record["branch"] != "gsfx":
            continue
        gsfx = record["gsfx"]
        lines.append(f"`{record['path']}` — {human_size(record['size'])}, "
                     f"`DocType={gsfx['properties'].get('DocType', '—')}`, "
                     f"`GSmetaVersion={gsfx['properties'].get('GSmetaVersion', '—')}`, "
                     f"элементов `Data.xml` — {sum(gsfx['elements'].values())} "
                     f"в {len(gsfx['elements'])} типах, вложений — {len(gsfx['entries'])}.")
        lines.append("")

    lines.append("## 6. Ошибки чтения")
    lines.append("")
    failed = [record for record in records if record["errors"]]
    if not failed:
        lines.append("Записей с непустым `errors[]` нет: каждая ветка формата отработала на всех "
                     "своих файлах.")
    else:
        lines.append("| Файл | Стадия | Причина |")
        lines.append("|:--|:--|:--|")
        for record in failed:
            for item in record["errors"]:
                lines.append(f"| `{record['path']}` | `{item['stage']}` | {item['message']} |")
    lines.append("")

    unparsed = [record for record in payload if not record["parsed"]]
    lines.append("## 7. Файлы без ветки разбора")
    lines.append("")
    if not unparsed:
        lines.append("Нет: каждый полезный файл разобран веткой своего формата.")
    else:
        lines.append("`parsed: false` — формат не поддержан веткой разбора (это НЕ пропуск: запись "
                     "в инвентаре есть, различать «не поддержан» и «пропущен» обязана P1.2).")
        lines.append("")
        lines.append("| Файл | Расширение | MIME | Размер |")
        lines.append("|:--|:--|:--|--:|")
        for record in unparsed:
            lines.append(f"| `{record['path']}` | `{record['ext'] or '—'}` | `{record['mime'] or '—'}` | "
                         f"{human_size(record['size'])} |")
    lines.append("")

    lines.append("## 8. Воспроизведение")
    lines.append("")
    lines.append("```bash")
    lines.append("python3 plans/2026.07.26-EST-TRAIN/artifacts/corpus_inventory.py \\")
    lines.append("  --root estimates/files \\")
    lines.append("  --out plans/2026.07.26-EST-TRAIN/findings/P1-corpus-inventory.json \\")
    lines.append("  --report plans/2026.07.26-EST-TRAIN/findings/P1-corpus-inventory.md")
    lines.append("```")
    lines.append("")
    return "\n".join(lines)


# --- Точка входа ----------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(description="Инвентарь корпуса смет (stdlib, хост).")
    parser.add_argument("--root", required=True, help="каталог корпуса, только чтение")
    parser.add_argument("--out", required=True, help="путь машинного носителя (JSON)")
    parser.add_argument("--report", required=True, help="путь человекочитаемого среза (Markdown)")
    args = parser.parse_args()

    root = Path(args.root)
    if not root.is_dir():
        parser.error(f"корень корпуса не найден: {args.root}")

    inventory = build_inventory(root)

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(inventory, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
                   encoding="utf-8")

    report = Path(args.report)
    report.parent.mkdir(parents=True, exist_ok=True)
    report.write_text(render_report(inventory), encoding="utf-8")

    totals = inventory["totals"]
    print(f"files={totals['files']} payload={totals['payload_files']} parsed={totals['parsed']} "
          f"errors={totals['with_errors']} → {args.out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
