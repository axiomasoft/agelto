#!/usr/bin/env python3
"""Check the same release coverage floors as soon as all shards finish."""

import importlib.util
import json
from pathlib import Path
import sys


SPEC = importlib.util.spec_from_file_location('release_quality', Path(__file__).with_name('check-release-quality.py'))
quality = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(quality)


def check(budget_path, clover):
    contract = json.loads(budget_path.read_text())['coverage']
    measured, critical = quality.coverage(clover, contract['critical_files'], contract['critical_file_floor'])
    if measured < contract['global_statement_floor']:
        raise ValueError('Global coverage is below the committed floor')
    print(json.dumps({'global_statement_coverage': measured, 'critical_file_statement_coverage': critical}, sort_keys=True))


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('usage: check-coverage-floor.py BUDGET CLOVER')
    check(Path(sys.argv[1]), Path(sys.argv[2]))
