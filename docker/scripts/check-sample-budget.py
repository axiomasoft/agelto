#!/usr/bin/env python3
"""Apply the unchanged release time budget before unrelated groups finish."""

import importlib.util
import json
from pathlib import Path
import statistics
import sys


SPEC = importlib.util.spec_from_file_location('release_quality', Path(__file__).with_name('check-release-quality.py'))
quality = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(quality)


def check(group, budget, evidence):
    if group not in {'serial', 'profile', 'coverage'}:
        raise ValueError('unknown budgeted release group')
    duration = (statistics.median(quality.milliseconds(evidence / f'serial-{n}.milliseconds') for n in range(1, 6))
                if group == 'serial' else quality.milliseconds(evidence / f'{group}.milliseconds'))
    required = quality.required_time_budget(duration)
    configured = json.loads(budget.read_text())['budgets_milliseconds'][group]
    if configured < required:
        raise ValueError(f'{group} budget {configured} is below the measured floor {required}')
    print(f'{group} duration {duration} ms; required budget {required} ms; configured {configured} ms')


if __name__ == '__main__':
    if len(sys.argv) != 4:
        raise SystemExit('usage: check-sample-budget.py GROUP BUDGET EVIDENCE_DIR')
    check(sys.argv[1], Path(sys.argv[2]), Path(sys.argv[3]))
