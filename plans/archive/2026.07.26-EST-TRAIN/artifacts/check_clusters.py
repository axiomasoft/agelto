#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Верификатор покрытия таксономии P1.2 (план 2026.07.26-EST-TRAIN).

Сверяет `artifacts/corpus-clusters.json` с инвентарём `findings/P1-corpus-inventory.json`:
каждый путь инвентаря лежит РОВНО в одном носителе — `assignments` или `noise`, коды
квирков соответствуют канону, у каждого кластера есть существующий представитель.
Ничего не чинит и ничего не пишет: падает с ненулевым кодом и списком нарушений.

Только стандартная библиотека (D15). Запуск из корня репозитория:

    python3 plans/2026.07.26-EST-TRAIN/artifacts/check_clusters.py \
      --inventory plans/2026.07.26-EST-TRAIN/findings/P1-corpus-inventory.json \
      --clusters  plans/2026.07.26-EST-TRAIN/artifacts/corpus-clusters.json
"""
from __future__ import annotations

import argparse
import json
import re
import sys

# Код квирка — латиница SCREAMING_SNAKE, стабилен на весь план (ТЗ P1.2 Implementation Rules).
QUIRK_CODE_RE = re.compile(r"^[A-Z][A-Z0-9_]{2,31}$")
# Идентификатор кластера: CL-01…CL-NN либо служебный CL-UNKNOWN.
CLUSTER_ID_RE = re.compile(r"^CL-(\d{2}|UNKNOWN)$")
UNKNOWN_CLUSTER = "CL-UNKNOWN"


def load(path: str) -> dict:
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def check(inventory: dict, clusters: dict) -> tuple[list[str], dict]:
    """Возвращает (список нарушений, счётчики для итоговой строки)."""
    violations: list[str] = []

    inventory_paths = [f["path"] for f in inventory["files"]]
    inventory_set = set(inventory_paths)
    if len(inventory_paths) != len(inventory_set):
        violations.append("инвентарь несёт повторяющиеся пути — сверка невозможна")

    assignments = clusters.get("assignments") or {}
    noise = clusters.get("noise") or []
    cluster_list = clusters.get("clusters") or []
    quirks = clusters.get("quirks") or []

    # 1. Покрытие: assignments ∪ noise == все пути инвентаря, пересечение пусто.
    noise_set = set(noise)
    if len(noise) != len(noise_set):
        violations.append("noise несёт повторяющиеся пути")
    both = sorted(set(assignments) & noise_set)
    for path in both:
        violations.append(f"путь покрыт дважды (assignments и noise): {path}")
    covered = set(assignments) | noise_set
    for path in sorted(inventory_set - covered):
        violations.append(f"путь инвентаря не покрыт ни кластером, ни noise: {path}")
    for path in sorted(covered - inventory_set):
        violations.append(f"путь есть в таксономии, но отсутствует в инвентаре: {path}")

    # 2. Кластеры: уникальный id, представитель существует и отнесён к этому же кластеру.
    seen_ids: set[str] = set()
    known_ids: set[str] = set()
    for cluster in cluster_list:
        cid = cluster.get("id")
        if not isinstance(cid, str) or not CLUSTER_ID_RE.match(cid):
            violations.append(f"идентификатор кластера вне канона CL-NN: {cid!r}")
            continue
        if cid in seen_ids:
            violations.append(f"идентификатор кластера повторяется: {cid}")
        seen_ids.add(cid)
        known_ids.add(cid)

        representative = cluster.get("representative")
        if not representative:
            violations.append(f"{cid}: нет представителя")
        elif representative not in inventory_set:
            violations.append(f"{cid}: представителя нет в инвентаре: {representative}")
        elif assignments.get(representative, {}).get("cluster") != cid:
            violations.append(f"{cid}: представитель отнесён к другому кластеру: {representative}")

    # 3. Счётчик файлов кластера сходится с фактической привязкой.
    actual: dict[str, int] = {}
    for path, record in assignments.items():
        cid = record.get("cluster")
        if not isinstance(cid, str) or not CLUSTER_ID_RE.match(cid):
            violations.append(f"привязка вне канона CL-NN: {path} → {cid!r}")
            continue
        if cid not in known_ids and cid != UNKNOWN_CLUSTER:
            violations.append(f"привязка к незаявленному кластеру: {path} → {cid}")
        actual[cid] = actual.get(cid, 0) + 1
    for cluster in cluster_list:
        cid = cluster.get("id")
        declared = cluster.get("file_count")
        if cid in known_ids and declared != actual.get(cid, 0):
            violations.append(f"{cid}: file_count={declared}, фактически привязано {actual.get(cid, 0)}")

    # 4. Словарь квирков: коды по регекспу, уникальны, каждый несёт detect и impact.
    dictionary: set[str] = set()
    for quirk in quirks:
        code = quirk.get("code")
        if not isinstance(code, str) or not QUIRK_CODE_RE.match(code):
            violations.append(f"код квирка вне регекспа ^[A-Z][A-Z0-9_]{{2,31}}$: {code!r}")
            continue
        if code in dictionary:
            violations.append(f"код квирка повторяется в словаре: {code}")
        dictionary.add(code)
        for field in ("title", "detect", "impact"):
            if not quirk.get(field):
                violations.append(f"{code}: пустое поле {field}")

    # 5. Каждый квирк привязки объявлен в словаре.
    for path, record in sorted(assignments.items()):
        for code in record.get("quirks") or []:
            if code not in dictionary:
                violations.append(f"квирк не объявлен в словаре: {path} → {code}")

    unknown = actual.get(UNKNOWN_CLUSTER, 0)
    return violations, {
        "covered": len(covered & inventory_set),
        "total": len(inventory_set),
        "clusters": len(cluster_list),
        "quirks": len(dictionary),
        "unknown": unknown,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Верификатор покрытия таксономии P1.2")
    parser.add_argument("--inventory", required=True, help="findings/P1-corpus-inventory.json")
    parser.add_argument("--clusters", required=True, help="artifacts/corpus-clusters.json")
    args = parser.parse_args()

    violations, stats = check(load(args.inventory), load(args.clusters))

    for violation in violations:
        print(f"НАРУШЕНИЕ: {violation}", file=sys.stderr)

    print(
        "coverage: {covered}/{total} · clusters: {clusters} · quirks: {quirks} · unknown: {unknown}".format(**stats)
    )
    return 1 if violations else 0


if __name__ == "__main__":
    sys.exit(main())
