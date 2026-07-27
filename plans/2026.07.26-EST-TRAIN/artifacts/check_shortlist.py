# -*- coding: utf-8 -*-
"""Верификатор шорт-листа эталонов первой волны (P1.3).

Проверяет `artifacts/fixture-shortlist.json` против машинной фактуры P1.1
(`findings/P1-corpus-inventory.json`) и таксономии P1.2 (`artifacts/corpus-clusters.json`).
Ничего не чинит и ничего не пишет: печатает нарушения и возвращает exit 1.

Запуск (Validation п.1 ТЗ P1.3, из корня репозитория):

    python3 plans/2026.07.26-EST-TRAIN/artifacts/check_shortlist.py \
      --inventory plans/2026.07.26-EST-TRAIN/findings/P1-corpus-inventory.json \
      --clusters  plans/2026.07.26-EST-TRAIN/artifacts/corpus-clusters.json \
      --shortlist plans/2026.07.26-EST-TRAIN/artifacts/fixture-shortlist.json

Зелёная печать:
    fixtures: N (EST=n1, NEG=n2) · clusters covered: X/Y · edge cases: 7/7
"""
import argparse
import fnmatch
import json
import os
import re
import sys

CODE_RE = re.compile(r"^(EST|NEG)-(XLSX|XLS|PDF|GSFX|DOCX|JPG)-[A-Z0-9]{2,10}-[0-9]{3}$")
GOLD_SOURCES = ("deterministic", "llm_assisted", "none")
REQUIRED_KEYS = (
    "code", "source_path", "sha256", "size_bytes", "class", "format", "form",
    "generator", "cluster_id", "quirks", "expected_estimates", "expected_rows",
    "gold_source", "is_verified", "selection_reason",
)
EXT_TO_FORMAT = {"xlsx": "XLSX", "xls": "XLS", "pdf": "PDF", "gsfx": "GSFX", "docx": "DOCX", "jpg": "JPG"}
MIN_NEGATIVES = 3
SIZE_RANGE = (20, 30)

# Крайние случаи: семь СТРОК таблицы `findings/corpus-recon-2026-07-26.md` §2.
# Строка засчитана, когда КАЖДЫЙ её шаблон нашёл хотя бы один файл шорт-листа
# (шаблон матчится по имени файла — `fnmatch`, чтобы «Лесное-ИС- М*.pdf» из §2
# не пришлось подменять одним произвольным чертежом).
# Разделы прозаического слоя, в которых ищутся объяснения. Якорь — ТЕКСТ заголовка, а не его
# номер: нумерация разделов при пересмотре документа поедет, смысл раздела — нет. Раздела нет →
# это нарушение, а не «объяснение засчитано по умолчанию».
SECTION_EXCLUSIONS = "НЕ попало"          # чем объясняются непокрытые кластеры
SECTION_REGISTRY = "Черновик метаданных реестра"  # чем объясняется expected_estimates: null

EDGE_CASES = [
    ("132-МБ скан-PDF со множеством смет", ["Смета картинками.pdf"]),
    ("контейнер ГрандСметы", ["Администрация культуры и спорта 3.gsfx"]),
    ("старый бинарный .xls, форма КС-2", ["Выполнение 2019.04 АКТ КС-2 01-01-001.xls"]),
    ("крупные книги — кандидаты в многосметные", ["ЛСР.xlsx", "СМЕТА.xlsx"]),
    ("обоснование НМЦК — не смета в сметном смысле", ["2. Обоснование НМЦК медизделия.xlsx"]),
    ("негативы скрининга рядом со сметами",
     ["Проект контракта по Услугам_.docx", "Изображение WhatsApp *.jpg", "Лесное-ИС- М*.pdf"]),
    ("пограничные: табличные, но другой домен",
     ["Кабельный журнал Лесное.pdf", "Акты скрытых работ *.xls"]),
]


def section(md, heading_marker):
    """Тело раздела MD, чей ЗАГОЛОВОК содержит `heading_marker`, до следующего заголовка того же
    уровня. Раздела нет — возвращается пустая строка, то есть объяснение не засчитывается."""
    lines = md.splitlines()
    start = next((i for i, ln in enumerate(lines)
                  if ln.startswith("## ") and heading_marker in ln), None)
    if start is None:
        return ""
    end = next((i for i in range(start + 1, len(lines)) if lines[i].startswith("## ")), len(lines))
    return "\n".join(lines[start:end])


def check(inventory_path, clusters_path, shortlist_path, md_path):
    inventory = json.load(open(inventory_path, encoding="utf-8"))
    clusters_doc = json.load(open(clusters_path, encoding="utf-8"))
    shortlist = json.load(open(shortlist_path, encoding="utf-8"))

    inv = {f["path"]: f for f in inventory["files"]}
    clusters = {c["id"]: c for c in clusters_doc["clusters"]}
    assignments = clusters_doc["assignments"]
    known_quirks = {q["code"] for q in clusters_doc["quirks"]}
    md = open(md_path, encoding="utf-8").read() if os.path.exists(md_path) else ""
    md_exclusions = section(md, SECTION_EXCLUSIONS)
    md_registry = section(md, SECTION_REGISTRY)

    violations = []
    if not isinstance(shortlist, list):
        return ["shortlist: ожидался список записей"], None

    seen_codes, seen_paths = {}, {}
    for i, fx in enumerate(shortlist):
        tag = f"[{i}] {fx.get('code', '<без кода>')}"

        missing = [k for k in REQUIRED_KEYS if k not in fx]
        if missing:
            violations.append(f"{tag}: нет обязательных полей: {', '.join(missing)}")
            continue

        code = fx["code"]
        if not CODE_RE.match(code):
            violations.append(f"{tag}: код не соответствует регекспу D13 {CODE_RE.pattern}")
        if code in seen_codes:
            violations.append(f"{tag}: дубликат кода (уже занят записью [{seen_codes[code]}])")
        seen_codes[code] = i

        path = fx["source_path"]
        if path in seen_paths:
            violations.append(f"{tag}: файл уже включён записью [{seen_paths[path]}] — два кода на один файл")
        seen_paths[path] = i

        entry = inv.get(path)
        if entry is None:
            violations.append(f"{tag}: source_path отсутствует в инвентаре P1.1: {path}")
            continue
        if entry.get("is_noise"):
            violations.append(f"{tag}: файл отнесён P1.2 к noise — эталоном быть не может")
        if fx["sha256"] != entry["sha256"]:
            violations.append(f"{tag}: sha256 не совпадает с инвентарём ({fx['sha256']} ≠ {entry['sha256']})")
        if fx["size_bytes"] != entry["size"]:
            violations.append(f"{tag}: size_bytes не совпадает с инвентарём ({fx['size_bytes']} ≠ {entry['size']})")

        parts = code.split("-")
        if fx["class"] != parts[0] or fx["format"] != parts[1] or fx["form"] != parts[2]:
            violations.append(f"{tag}: поля class/format/form расходятся с кодом")
        if fx["format"] != EXT_TO_FORMAT.get(entry["ext"], "?"):
            violations.append(f"{tag}: format {fx['format']} не соответствует расширению файла .{entry['ext']}")

        cid = fx["cluster_id"]
        assignment = assignments.get(path)
        if cid not in clusters:
            violations.append(f"{tag}: cluster_id {cid} отсутствует в таксономии P1.2")
        elif assignment is None:
            violations.append(f"{tag}: файл не привязан к кластеру в taxonomy P1.2")
        elif assignment["cluster"] != cid:
            violations.append(f"{tag}: cluster_id {cid} ≠ привязке P1.2 ({assignment['cluster']})")
        elif sorted(fx["quirks"]) != sorted(assignment["quirks"]):
            violations.append(f"{tag}: quirks расходятся с привязкой P1.2 ({assignment['quirks']})")

        unknown = [q for q in fx["quirks"] if q not in known_quirks]
        if unknown:
            violations.append(f"{tag}: квирки вне словаря P1.2: {', '.join(unknown)}")

        if fx["gold_source"] not in GOLD_SOURCES:
            violations.append(f"{tag}: gold_source вне enum {GOLD_SOURCES}")
        if fx["class"] == "NEG" and fx["gold_source"] != "none":
            violations.append(f"{tag}: у негатива gold_source обязан быть 'none' (D5)")
        if fx["class"] == "EST" and fx["gold_source"] == "none":
            violations.append(f"{tag}: у эталона extraction gold_source 'none' недопустим")

        if fx["expected_rows"] is not None:
            violations.append(f"{tag}: expected_rows обязан остаться null — реальное число строк считает P5")
        if fx["is_verified"] is not False:
            violations.append(f"{tag}: is_verified обязан быть false — флаг ставит только P8.3 (D43)")

        n_est = fx["expected_estimates"]
        if fx["class"] == "NEG":
            if n_est != 0:
                violations.append(f"{tag}: у негатива expected_estimates обязан быть 0")
        elif n_est is None:
            if code not in md_registry:
                violations.append(f"{tag}: expected_estimates null не объяснён поимённо "
                                  f"в разделе «{SECTION_REGISTRY}» файла {md_path}")
        elif not isinstance(n_est, int) or n_est < 1:
            violations.append(f"{tag}: expected_estimates обязан быть положительным целым либо null с объяснением в MD")

        if not str(fx["selection_reason"]).strip():
            violations.append(f"{tag}: selection_reason пуст")

    est = [f for f in shortlist if f.get("class") == "EST"]
    neg = [f for f in shortlist if f.get("class") == "NEG"]
    if len(neg) < MIN_NEGATIVES:
        violations.append(f"негативов скрининга {len(neg)} < {MIN_NEGATIVES} (D5)")

    covered = {f.get("cluster_id") for f in shortlist}
    uncovered = sorted(set(clusters) - covered)
    for cid in uncovered:
        if cid not in md_exclusions:
            violations.append(f"кластер {cid} не покрыт эталоном и не объяснён поимённо "
                              f"в разделе «{SECTION_EXCLUSIONS}» файла {md_path}")

    edge_ok = 0
    for title, patterns in EDGE_CASES:
        missed = [p for p in patterns
                  if not any(fnmatch.fnmatch(os.path.basename(f.get("source_path", "")), p) for f in shortlist)]
        if missed:
            violations.append(f"крайний случай §2 «{title}» не покрыт: нет {', '.join(missed)}")
        else:
            edge_ok += 1

    if not SIZE_RANGE[0] <= len(shortlist) <= SIZE_RANGE[1] and "Почему не 20–30" not in md:
        violations.append(
            f"записей {len(shortlist)} вне диапазона {SIZE_RANGE[0]}–{SIZE_RANGE[1]} (D4) "
            f"и раздела «Почему не 20–30» в {md_path} нет")

    summary = (f"fixtures: {len(shortlist)} (EST={len(est)}, NEG={len(neg)}) · "
               f"clusters covered: {len(covered & set(clusters))}/{len(clusters)} · "
               f"edge cases: {edge_ok}/{len(EDGE_CASES)}")
    return violations, summary


def main():
    parser = argparse.ArgumentParser(description="Верификатор шорт-листа эталонов P1.3")
    parser.add_argument("--inventory", required=True)
    parser.add_argument("--clusters", required=True)
    parser.add_argument("--shortlist", required=True)
    parser.add_argument("--md", default="plans/2026.07.26-EST-TRAIN/research/02-fixture-shortlist.md",
                        help="прозаический слой: в нём ищутся объяснения непокрытых кластеров и выхода за диапазон")
    args = parser.parse_args()

    violations, summary = check(args.inventory, args.clusters, args.shortlist, args.md)
    for v in violations:
        print("НАРУШЕНИЕ: " + v)
    if summary:
        print(summary)
    return 1 if violations else 0


sys.exit(main())
