#!/usr/bin/env python3
from __future__ import annotations
import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'plans/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md'

def targets() -> list[str]:
    text = (ROOT / 'docker/Makefile').read_text()
    names = re.findall(r'\.PHONY:\s+(.+)', text)
    return sorted({name.replace('\\:', ':') for line in names for name in line.split()})

def render(rows: list[str]) -> str:
    lines = ['# P12 source-derived command matrix', '', 'Generated from `docker/Makefile`; no row is executed by this check.', '', '| Command | Class | Authority |', '| --- | --- | --- |']
    for row in rows:
        external = row in {'host:apply', 'repositories:prepare', 'repositories:sync', 'prod:migrate', 'prod:seed-base'} or row.startswith('prod:deploy')
        lines.append(f'| `make {row}` | {"external/mutating" if external else "local/check"} | {"future operator rehearsal" if external else "local"} |')
    return '\n'.join(lines) + '\n'

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=('render', 'check', 'agent-json'))
    mode = parser.parse_args().mode
    rows = targets()
    if not rows or len(rows) != len(set(rows)):
        raise SystemExit('ambiguous command inventory')
    content = render(rows)
    if mode == 'render':
        OUT.write_text(content)
    elif mode == 'check':
        if not OUT.exists() or OUT.read_text() != content:
            raise SystemExit('command matrix is stale')
    else:
        print(json.dumps({'schema': 'agelto.command-reference/v1', 'commands': rows}, separators=(',', ':')))
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
