#!/usr/bin/env python3
"""Validate P11.6 release-quality evidence without accepting TIA/cache replay."""

from __future__ import annotations

import argparse
import hashlib
import json
import statistics
import subprocess
import xml.etree.ElementTree as element_tree
from pathlib import Path


def normalized_junit(path: Path) -> tuple[str, int]:
    root = element_tree.parse(path).getroot()
    cases = root.findall('.//testcase')
    if not cases:
        raise ValueError(f'{path} contains no test cases')

    rows = []
    for case in cases:
        status = 'passed'
        if case.find('failure') is not None:
            status = 'failure'
        elif case.find('error') is not None:
            status = 'error'
        elif case.find('skipped') is not None:
            status = 'skipped'
        rows.append((case.attrib.get('classname', ''), case.attrib.get('name', ''), status))

    digest = hashlib.sha256(repr(sorted(rows)).encode()).hexdigest()
    if any(status != 'passed' for _, _, status in rows):
        raise ValueError(f'{path} has a non-passing test status')

    return digest, len(rows)


def milliseconds(path: Path) -> int:
    value = int(path.read_text().strip())
    if value <= 0:
        raise ValueError(f'{path} is not a positive duration')
    return value


def coverage(clover: Path) -> float:
    metrics = element_tree.parse(clover).getroot().find('./project/metrics')
    if metrics is None:
        raise ValueError(f'{clover} has no project metrics')
    statements = int(metrics.attrib['statements'])
    if statements <= 0:
        raise ValueError(f'{clover} has no executable statements')
    return round(int(metrics.attrib['coveredstatements']) / statements * 100, 2)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--budget', type=Path, required=True)
    parser.add_argument('--evidence', type=Path, required=True)
    parser.add_argument('--write-summary', type=Path)
    arguments = parser.parse_args()

    budget = json.loads(arguments.budget.read_text())
    evidence = arguments.evidence
    expected_seeds = budget['random_order_seeds']
    if expected_seeds != [104729, 130363, 155921]:
        raise SystemExit('Random-order seed contract was changed')
    if budget['selected_mode'] != 'serial':
        raise SystemExit('D40 selected release mode is not serial')

    serial = [milliseconds(evidence / f'serial-{run}.milliseconds') for run in range(1, 6)]
    serial_junit = [normalized_junit(evidence / f'serial-{run}.xml') for run in range(1, 6)]
    reference_hash, test_count = serial_junit[0]
    if any(item != serial_junit[0] for item in serial_junit[1:]):
        raise SystemExit('Five serial no-TIA runs have divergent name/status sets')

    profile_duration = milliseconds(evidence / 'profile.milliseconds')
    if normalized_junit(evidence / 'profile.xml') != serial_junit[0]:
        raise SystemExit('Profile run has divergent name/status set')

    coverage_duration = milliseconds(evidence / 'coverage.milliseconds')
    if normalized_junit(evidence / 'coverage.xml') != serial_junit[0]:
        raise SystemExit('Coverage run has divergent name/status set')
    measured_coverage = coverage(evidence / 'coverage-clover.xml')

    for seed in expected_seeds:
        if normalized_junit(evidence / f'random-{seed}.xml') != serial_junit[0]:
            raise SystemExit(f'Random seed {seed} has divergent name/status set')
    for run in range(1, 4):
        if normalized_junit(evidence / f'selected-serial-{run}.xml') != serial_junit[0]:
            raise SystemExit(f'Selected serial repetition {run} has divergent name/status set')

    serial_median = statistics.median(serial)
    required_serial_budget = max(serial_median * 1.5, serial_median + 5000)
    required_profile_budget = max(profile_duration * 1.5, profile_duration + 5000)
    required_coverage_budget = max(coverage_duration * 1.5, coverage_duration + 5000)
    configured = budget['budgets_milliseconds']
    for key, required in {
        'serial': required_serial_budget,
        'profile': required_profile_budget,
        'coverage': required_coverage_budget,
    }.items():
        if configured[key] < required:
            raise SystemExit(f'{key} budget {configured[key]} is below measured D42 floor {required}')

    if measured_coverage < budget['coverage']['global_statement_floor']:
        raise SystemExit('Global coverage is below the committed P11.6 floor')

    summary = {
        'candidate_sha': subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip(),
        'serial_milliseconds': serial,
        'serial_median_milliseconds': serial_median,
        'profile_milliseconds': profile_duration,
        'coverage_milliseconds': coverage_duration,
        'global_statement_coverage': measured_coverage,
        'name_status_sha256': reference_hash,
        'test_count': test_count,
        'random_order_seeds': expected_seeds,
        'selected_mode': 'serial',
    }
    if arguments.write_summary:
        arguments.write_summary.write_text(json.dumps(summary, indent=2, sort_keys=True) + '\n')
    print(json.dumps(summary, sort_keys=True))


if __name__ == '__main__':
    main()
