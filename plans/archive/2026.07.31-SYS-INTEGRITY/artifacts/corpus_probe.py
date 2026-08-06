#!/usr/bin/env python3
"""P5.1 — зонд по корпусу `estimates/files` для дельта-сверки пяти пробелов, не решённых донором
(2026.07.26-EST-TRAIN `research/06-schema-decision.md`): НР/СП суммами на позиции, разбивка прямых
затрат ОТ/ЭМ/МАТ, тип нормы, признак «неучтённый ресурс», источник цены ресурса — плюс остаточная
часть восьмого пробела (нормативный документ / дата ценового среза, поверх уже решённых
`base_year`/`price_level`) и повторная проверка §6 п.6 донора (база начисления/формула, чтобы
подтвердить, решён ли этот пробел ЦЕЛИКОМ, а не переоткрывать по цитате методики, D10).

Т О Л Ь К О  Ч Т Е Н И Е: `estimates/files` не модифицируется. Только stdlib + внешние бинарии
`libreoffice`/`pdftotext`/`file` (тот же приём, что `plans/2026.07.26-EST-TRAIN/artifacts/
corpus_inventory.py`, P1.1 донора) — запускается на ХОСТЕ, не в контейнере.

Эвристика (единственная в скрипте, остальное — прямые факты присутствия строки):
- xlsx/xls (после конверсии LibreOffice) — полнотекстовый скан ВСЕХ ячеек листа (не только первых
  строк, в отличие от `corpus_inventory.py`, — здесь важно тело таблицы, не только шапка) плюс
  отдельно шапочная зона (первые HEADER_ROWS строк) для колоночных сигналов.
- pdf — текстовый слой первых PDF_SAMPLE_PAGES страниц через `pdftotext` (образцовая выборка, не
  весь документ — те же основания, что у `corpus_inventory.py`: экономия времени на больших сканах).
- gsfx — имена XML-атрибутов элементов `Data.xml` (не только текст) — тот же приём, которым донор
  нашёл `@NaclMask`/`@PlanMask`/`@Formula`.
- docx — текст абзацев и таблиц.
- `.xls`, не поддавшиеся конверсии LibreOffice, и любой формат без ветки разбора — попадают в
  `unparsed[]` с ЧИСЛОМ, не молчат (Implementation Rules P5.1 п.6).

Совпадение считается на УРОВНЕ ФАЙЛА (файл посчитан один раз, даже если сигнал встретился на
нескольких листах/страницах). Ключевые слова — подстроки, без учёта регистра, после нормализации
ё→е и схлопывания пробелов.
"""
from __future__ import annotations

import argparse
import json
import re
import shutil
import subprocess
import tempfile
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

HEADER_ROWS = 60
PDF_SAMPLE_PAGES = 6
TIMEOUT_LIBREOFFICE = 180
TIMEOUT_PDFTOTEXT = 120
TIMEOUT_FILE = 30

NOISE_NAMES = {"desktop.ini"}
SPREADSHEET_EXT = {"xlsx", "xls"}

DOC_REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"


def norm(text: str) -> str:
    return re.sub(r"\s+", " ", text.replace("ё", "е").replace("Ё", "Е")).strip().lower()


# --- Сигналы по пробелам (GAP-1..5, GAP-6-verify, GAP-8-residual) --------------------------------
# Каждый сигнал — (label, [подстроки]); файл засчитан, если ХОТЯ БЫ ОДНА подстрока найдена.

GAP_SIGNALS = {
    "GAP-1_nr_sp_per_item": [
        "накладные расходы", "сметная прибыль",
    ],
    "GAP-1_nr_sp_per_item_header_short": [
        # короткие колоночные пометки — считаются ТОЛЬКО как отдельная ячейка шапки (см. header_cell_matches)
    ],
    "GAP-2_direct_cost_breakdown": [
        "оплата труда рабочих", "эксплуатация машин", "материальные ресурсы",
    ],
    "GAP-3_norm_type": [
        "тип нормы", "вид расценки", "сборник расценок", "гэсн", "фссц",
    ],
    "GAP-4_unaccounted_resource": [
        "неучтен", "неучёт", "не учтен", "не учтён",
    ],
    "GAP-5_resource_price_source": [
        "фссц", "тссц", "конъюнктурн", "прайс-лист", "источник цены",
    ],
    "GAP-6_accrual_base_verify": [
        "% от", "процент от", "база начисления", "от пз", "от прямых затрат",
    ],
    "GAP-8_normative_doc_date_residual": [
        "приказ минстро", "421/пр", "письмо минстро", "дата ценового среза", "дата индекса",
    ],
    "NEST_section_subtotal": [
        "итого по разделу", "всего по разделу",
    ],
}

# Короткие колоночные аббревиатуры (ячейка шапки должна ПОЧТИ ЦЕЛИКОМ состоять из этого, не просто
# содержать подстроку — иначе "мат" ловит "материал" в любом месте текста).
HEADER_CELL_EXACT = {
    "GAP-1_nr_sp_per_item_header_short": {"нр", "сп", "нр,%", "сп,%", "нр,руб", "сп,руб", "нр (руб)", "сп (руб)"},
    "GAP-2_direct_cost_breakdown_header_short": {"от", "эм", "мат", "зпм", "фот", "от,руб", "эм,руб", "мат,руб"},
    "GAP-3_norm_type_header_short": {"тип", "вид нормы", "шифр норм"},
}


def local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1]


def run(args: list[str], timeout: int) -> subprocess.CompletedProcess:
    return subprocess.run(args, shell=False, timeout=timeout, capture_output=True)


def decode(raw: bytes) -> str:
    return raw.decode("utf-8", errors="replace")


def read_shared_strings(book: zipfile.ZipFile) -> list[str]:
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


def sheet_targets(book: zipfile.ZipFile) -> list[str]:
    rels: dict[str, str] = {}
    if "xl/_rels/workbook.xml.rels" in book.namelist():
        root = ET.fromstring(book.read("xl/_rels/workbook.xml.rels"))
        for element in root:
            if local_name(element.tag) == "Relationship":
                rels[element.attrib.get("Id", "")] = element.attrib.get("Target", "")
    entries: list[str] = []
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
            if entry:
                entries.append(entry)
    return entries


def scan_xlsx_bytes(path: Path) -> tuple[str, str]:
    """Возвращает (полный текст всех ячеек всех листов, текст первых HEADER_ROWS строк каждого листа)."""
    body_parts: list[str] = []
    header_parts: list[str] = []
    with zipfile.ZipFile(path) as book:
        strings = read_shared_strings(book)
        entries = set(book.namelist())
        for entry in sheet_targets(book):
            if entry not in entries:
                continue
            row_no = 0
            with book.open(entry) as stream:
                for event, element in ET.iterparse(stream, events=("end",)):
                    name = local_name(element.tag)
                    if name == "row":
                        row_no += 1
                        continue
                    if name != "c":
                        continue
                    ctype = element.attrib.get("t", "n")
                    text_val = None
                    for child in element:
                        cname = local_name(child.tag)
                        if cname == "is":
                            text_val = "".join(t.text or "" for t in child.iter() if local_name(t.tag) == "t")
                        elif cname == "v" and text_val is None:
                            raw = child.text or ""
                            if ctype == "s" and raw.isdigit() and int(raw) < len(strings):
                                text_val = strings[int(raw)]
                            else:
                                text_val = raw
                    if text_val:
                        body_parts.append(text_val)
                        if row_no <= HEADER_ROWS:
                            header_parts.append(text_val)
                    element.clear()
    return "\n".join(body_parts), "\n".join(header_parts)


def convert_xls(path: Path, tmpdir: str) -> Path | None:
    profile = Path(tmpdir) / "lo-profile"
    completed = run([
        "libreoffice", f"-env:UserInstallation=file://{profile}",
        "--headless", "--convert-to", "xlsx", "--outdir", tmpdir, str(path),
    ], TIMEOUT_LIBREOFFICE)
    produced = sorted(Path(tmpdir).glob("*.xlsx"))
    return produced[0] if produced else None


def scan_pdf(path: Path) -> str:
    completed = run(["pdftotext", "-f", "1", "-l", str(PDF_SAMPLE_PAGES), str(path), "-"], TIMEOUT_PDFTOTEXT)
    if completed.returncode != 0:
        return ""
    return decode(completed.stdout)


def scan_docx(path: Path) -> str:
    parts: list[str] = []
    with zipfile.ZipFile(path) as document:
        if "word/document.xml" not in document.namelist():
            return ""
        with document.open("word/document.xml") as stream:
            for event, element in ET.iterparse(stream, events=("end",)):
                if local_name(element.tag) == "t" and element.text:
                    parts.append(element.text)
                element.clear()
    return "\n".join(parts)


def scan_gsfx(path: Path) -> tuple[str, set[str]]:
    """Текст Properties.txt + имена XML-атрибутов Data.xml (набор `элемент@атрибут`)."""
    texts: list[str] = []
    attr_names: set[str] = set()
    with zipfile.ZipFile(path) as container:
        names = set(container.namelist())
        if "Properties.txt" in names:
            texts.append(container.read("Properties.txt").decode("cp1251", errors="replace"))
        if "Data.xml" in names:
            with container.open("Data.xml") as stream:
                for event, element in ET.iterparse(stream, events=("end",)):
                    tag = local_name(element.tag)
                    for attr in element.attrib:
                        attr_names.add(f"{tag}@{attr}")
                    element.clear()
    return "\n".join(texts), attr_names


def matches(text_norm: str, needles: list[str]) -> list[str]:
    return [n for n in needles if n in text_norm]


def header_cell_hits(header_text_lines: list[str], exact_set: set[str]) -> list[str]:
    hits = []
    for line in header_text_lines:
        cell = norm(line).replace(" ", "")
        if cell in exact_set:
            hits.append(line)
    return hits


def main() -> int:
    parser = argparse.ArgumentParser(description="P5.1 corpus probe — только чтение estimates/files.")
    parser.add_argument("--root", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()

    root = Path(args.root)
    files = sorted((p for p in root.rglob("*") if p.is_file() and p.name.lower() not in NOISE_NAMES),
                   key=lambda p: p.relative_to(root).as_posix())

    per_file: dict[str, dict] = {}
    gap_file_sets: dict[str, set[str]] = {k: set() for k in GAP_SIGNALS}
    gap_file_sets["GAP-1_nr_sp_per_item_header_short"] = set()
    gap_file_sets["GAP-2_direct_cost_breakdown_header_short"] = set()
    gap_file_sets["GAP-3_norm_type_header_short"] = set()
    unparsed: dict[str, int] = {}
    gsfx_attr_union: set[str] = set()
    tmpdir = tempfile.mkdtemp(prefix="p51-corpus-probe-")

    try:
        for path in files:
            rel = path.relative_to(root).as_posix()
            ext = path.suffix.lower().lstrip(".")
            body_text = ""
            header_lines: list[str] = []

            try:
                if ext == "xlsx":
                    body_text, header_text = scan_xlsx_bytes(path)
                    header_lines = header_text.split("\n")
                elif ext == "xls":
                    conv_dir = tempfile.mkdtemp(dir=tmpdir)
                    converted = convert_xls(path, conv_dir)
                    if converted is None:
                        unparsed[ext] = unparsed.get(ext, 0) + 1
                        continue
                    body_text, header_text = scan_xlsx_bytes(converted)
                    header_lines = header_text.split("\n")
                elif ext == "pdf":
                    body_text = scan_pdf(path)
                elif ext == "docx":
                    body_text = scan_docx(path)
                elif ext == "gsfx":
                    body_text, attrs = scan_gsfx(path)
                    gsfx_attr_union |= attrs
                else:
                    unparsed[ext or "(no-ext)"] = unparsed.get(ext or "(no-ext)", 0) + 1
                    continue
            except (subprocess.SubprocessError, OSError, zipfile.BadZipFile, ET.ParseError, KeyError) as exc:
                unparsed[f"{ext}:error:{type(exc).__name__}"] = unparsed.get(f"{ext}:error:{type(exc).__name__}", 0) + 1
                continue

            text_norm = norm(body_text)
            file_hits: dict[str, list[str]] = {}

            for gap, needles in GAP_SIGNALS.items():
                if not needles:
                    continue
                hits = matches(text_norm, needles)
                if hits:
                    file_hits[gap] = hits
                    gap_file_sets[gap].add(rel)

            for gap, exact_set in HEADER_CELL_EXACT.items():
                hits = header_cell_hits(header_lines, exact_set)
                if hits:
                    file_hits[gap] = hits
                    gap_file_sets[gap].add(rel)

            if file_hits:
                per_file[rel] = file_hits

        summary = {
            gap: {"files": sorted(paths), "count": len(paths)}
            for gap, paths in gap_file_sets.items()
        }

        result = {
            "root": str(root),
            "files_scanned": len(files),
            "unparsed": unparsed,
            "gsfx_attribute_names": sorted(gsfx_attr_union),
            "gap_signal_summary": summary,
            "per_file_hits": per_file,
        }

        Path(args.out).parent.mkdir(parents=True, exist_ok=True)
        Path(args.out).write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")

        print(f"files_scanned={len(files)} unparsed={sum(unparsed.values())} -> {args.out}")
        for gap, s in summary.items():
            print(f"  {gap}: {s['count']}")
        return 0
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


if __name__ == "__main__":
    raise SystemExit(main())
