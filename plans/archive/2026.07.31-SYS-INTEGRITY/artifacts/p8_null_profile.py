#!/usr/bin/env python3
"""P8.1 — оффлайн-профиль пустоты по шести gold-эталонам (`estimates/gold/*.gold.json`,
корпус-донор `2026.07.26-EST-TRAIN`). Считает долю `null` НА ПОЗИЦИЮ (`row_type=item`) по
каждому полю `EstimateRow` в НОРМАЛЬНОМ (текстовом, не деградированном) источнике — база
сравнения для живой пробы скана (Scope Included P8.1 п.4): без неё число «X% null на скане»
нечитаемо, не зная фонового уровня пустоты той же модели на читаемом источнике.

ТОЛЬКО ЧТЕНИЕ: `estimates/gold/*.gold.json` не модифицируются. Только stdlib, без БД/сети —
детерминированный повторный запуск даёт байт-идентичный вывод (Validation P8.1).

Поля профилируются построчно по `EstimateRow` (docs/specs/v2/08-estimate-extraction.md §3.2,
`app/Dto/Procurement/Analyzers/Estimate/EstimateRow.php`), считаются только строки
`row_type=item` (заголовки/subtotal/summary_row не несут большинства этих полей по построению
контракта — доля null там была бы артефактом формы, не сигналом качества).
"""
from __future__ import annotations

import json
from pathlib import Path

GOLD_DIR = Path(__file__).resolve().parents[3] / "estimates" / "gold"

ROW_FIELDS = [
    "section_title",
    "code",
    "unit",
    "quantity",
    "unit_price_base",
    "unit_price_curr",
    "total_price_base",
    "total_price_curr",
    "index_code",
    "index_value",
    "source_position_number",
    "work_type_name",
    "attributes",
]


def load_gold_files() -> list[Path]:
    return sorted(GOLD_DIR.glob("*.gold.json"))


def item_rows(gold: dict) -> list[dict]:
    rows = []
    for estimate in gold.get("estimates", []):
        for row in estimate.get("rows", []):
            if row.get("row_type") == "item":
                rows.append(row)
    return rows


def is_null(value: object) -> bool:
    if value is None:
        return True
    if isinstance(value, list) and value == []:
        return True
    return False


def main() -> None:
    files = load_gold_files()

    per_file: dict[str, dict[str, object]] = {}
    total_items = 0
    total_null_by_field: dict[str, int] = {field: 0 for field in ROW_FIELDS}

    for path in files:
        gold = json.loads(path.read_text(encoding="utf-8"))
        rows = item_rows(gold)
        total_items += len(rows)

        null_by_field: dict[str, int] = {field: 0 for field in ROW_FIELDS}
        for row in rows:
            for field in ROW_FIELDS:
                if is_null(row.get(field)):
                    null_by_field[field] += 1
                    total_null_by_field[field] += 1

        per_file[path.stem] = {
            "items": len(rows),
            "null_rate": {
                field: (round(null_by_field[field] / len(rows), 4) if rows else None)
                for field in ROW_FIELDS
            },
        }

    aggregate = {
        "files": len(files),
        "total_items": total_items,
        "null_rate": {
            field: (round(total_null_by_field[field] / total_items, 4) if total_items else None)
            for field in ROW_FIELDS
        },
    }

    result = {
        "gold_dir": str(GOLD_DIR),
        "per_file": per_file,
        "aggregate": aggregate,
    }

    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
