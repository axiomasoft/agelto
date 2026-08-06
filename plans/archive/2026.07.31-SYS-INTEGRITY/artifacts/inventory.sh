#!/usr/bin/env bash
# Машинный инвентарь раскладки app/** для фазы P3 плана 2026.07.31-SYS-INTEGRITY.
#
# Зачем: числа раскладки (файлов в корне слоя, состав подкаталогов, словарь подобластей)
# дрейфуют — за четыре дня между разведкой и ре-дизайном P3 разъехались семь чисел из восьми
# (findings/P3-layout-facts-2026-08-04.md §1). Инвентарь обязан пересчитываться командой,
# а не переписываться руками (D30, P3.1 Implementation Rule 6).
#
# Запуск — ИЗ КОРНЯ РЕПОЗИТОРИЯ:
#   bash plans/2026.07.31-SYS-INTEGRITY/artifacts/inventory.sh          # перезаписать JSON
#   bash plans/2026.07.31-SYS-INTEGRITY/artifacts/inventory.sh --check  # сверить, не переписывая
#
# Зависимости: bash + python3 (stdlib). Вывод байт-детерминирован: сортировка LC_ALL=C,
# ни времени, ни git-ref внутри — якорь дерева пишется в research/04-domain-layout.md.
set -euo pipefail

ARTIFACTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${ARTIFACTS_DIR}/app-layer-inventory.json"
MODE="${1:-write}"

if [[ ! -d app ]]; then
  echo "inventory.sh: запускать из корня репозитория (нет каталога ./app)" >&2
  exit 2
fi

render() {
  LC_ALL=C python3 - <<'PY'
import json
import os

ROOT = "app"
DOMAINS = ["Llm", "Organization", "Procurement", "Reference"]

# Техническая ось: слои без доменного уровня по канону skill `laravel-structure`
# (инфраструктура не знает про домены). Перечень — фактический, снимается сверкой с деревом:
# каталог app/<X>, не содержащий ни одного доменного подкаталога, попадает сюда автоматически.


def php_files(path):
    return sorted(
        entry.name
        for entry in os.scandir(path)
        if entry.is_file() and entry.name.endswith(".php")
    )


def subdirs(path):
    return sorted(entry.name for entry in os.scandir(path) if entry.is_dir())


def walk(path):
    yield path
    for name in subdirs(path):
        yield from walk(os.path.join(path, name))


def count_recursive(path):
    total = 0
    for current, _dirs, files in os.walk(path):
        total += sum(1 for f in files if f.endswith(".php"))
    return total


layers = []
technical = []
vocabulary = {domain: {} for domain in DOMAINS}

for path in sorted(walk(ROOT)):
    parts = path.split(os.sep)
    domain = next((p for p in parts[1:] if p in DOMAINS), None)
    if domain is None:
        # Каталог без доменного сегмента: либо контейнер слоя (app/Enums), либо техническая ось.
        has_domain_child = any(name in DOMAINS for name in subdirs(path))
        if len(parts) > 1 and not has_domain_child:
            technical.append(
                {
                    "path": path,
                    "root_files": len(php_files(path)),
                    "subdirs": subdirs(path),
                }
            )
        continue

    domain_index = parts.index(domain)
    layer = "/".join(parts[1:domain_index])
    tail = parts[domain_index + 1 :]
    entry = {
        "path": path,
        "layer": layer,
        "domain": domain,
        "subarea": "/".join(tail),
        "is_domain_root": not tail,
        "root_files": len(php_files(path)),
        "subdirs": subdirs(path),
        "files_recursive": count_recursive(path),
        "root_file_names": php_files(path),
    }
    layers.append(entry)

    if len(tail) == 1:
        vocabulary[domain].setdefault(tail[0], []).append(f"{layer}/{domain}")

for domain in vocabulary:
    for name in vocabulary[domain]:
        vocabulary[domain][name] = sorted(vocabulary[domain][name])
    vocabulary[domain] = dict(sorted(vocabulary[domain].items()))

document = {
    "schema": "app-layer-inventory/v1",
    "root": ROOT,
    "domains": DOMAINS,
    "totals": {
        "php_files": count_recursive(ROOT),
        "domain_folders": sum(1 for layer in layers if layer["is_domain_root"]),
        "layer_dirs": len(layers),
        "technical_dirs": len(technical),
    },
    "domain_layers": [layer for layer in layers if layer["is_domain_root"]],
    "subarea_dirs": [layer for layer in layers if not layer["is_domain_root"]],
    "subarea_vocabulary": vocabulary,
    "technical_axis": technical,
}

print(json.dumps(document, ensure_ascii=False, indent=2, sort_keys=False))
PY
}

if [[ "${MODE}" == "--check" ]]; then
  if ! render | diff -u "${OUT}" - >/dev/null; then
    echo "inventory.sh: ДРЕЙФ — ${OUT} не совпадает с деревом" >&2
    render | diff -u "${OUT}" - >&2 || true
    exit 1
  fi
  echo "inventory.sh: OK — ${OUT} совпадает с деревом"
  exit 0
fi

render >"${OUT}"
python3 - "${OUT}" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    document = json.load(handle)

totals = document["totals"]
print(
    "inventory.sh: {path} — {files} php-файлов, {domains} доменных папок слоёв, "
    "{subareas} подкаталогов подобластей, {tech} каталогов технической оси".format(
        path=sys.argv[1],
        files=totals["php_files"],
        domains=totals["domain_folders"],
        subareas=len(document["subarea_dirs"]),
        tech=totals["technical_dirs"],
    )
)
PY
