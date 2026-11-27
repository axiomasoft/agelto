#!/usr/bin/env python3
"""Fail closed on the release coverage floor from a Clover report."""

from __future__ import annotations

import argparse
import os
import xml.etree.ElementTree as element_tree
from pathlib import Path


def ratio(covered: int, total: int, label: str) -> float:
    if total <= 0:
        raise ValueError(f'{label} has no executable statements')

    return covered / total * 100


parser = argparse.ArgumentParser()
parser.add_argument('--clover', type=Path, required=True)
parser.add_argument('--global-min', type=float, default=float(os.environ.get('COVERAGE_GLOBAL_MIN', '88.22')))
parser.add_argument('--critical-min', type=float, default=float(os.environ.get('COVERAGE_CRITICAL_MIN', '55')))
parser.add_argument('--critical-file', action='append', default=[])
arguments = parser.parse_args()

root = element_tree.parse(arguments.clover).getroot()
project_metrics = root.find('./project/metrics')
if project_metrics is None:
    raise SystemExit('Clover report does not contain project metrics')

global_coverage = round(ratio(
    int(project_metrics.attrib['coveredstatements']),
    int(project_metrics.attrib['statements']),
    'project',
), 2)
if global_coverage < arguments.global_min:
    raise SystemExit(f'Global statement coverage {global_coverage:.2f}% is below {arguments.global_min:.2f}%')

critical_files = []
for file_node in root.findall('.//file'):
    name = file_node.attrib.get('name', '')
    normalized = name.removeprefix('/var/www/html/').removeprefix(str(Path.cwd()) + '/')
    if normalized not in arguments.critical_file:
        continue

    metrics = file_node.find('./metrics')
    if metrics is None:
        raise SystemExit(f'Critical file {normalized} has no Clover metrics')
    statements = int(metrics.attrib['statements'])
    if statements == 0:
        continue
    critical_files.append((normalized, round(ratio(
        int(metrics.attrib['coveredstatements']),
        statements,
        normalized,
    ), 2)))

if len(critical_files) != len(arguments.critical_file):
    seen_files = {name for name, _ in critical_files}
    missing_files = sorted(set(arguments.critical_file) - seen_files)
    raise SystemExit(f'Clover report is missing configured critical files: {", ".join(missing_files)}')

failed_files = [f'{name}={coverage:.2f}%' for name, coverage in critical_files if coverage < arguments.critical_min]
if failed_files:
    raise SystemExit(f'Critical-file coverage below {arguments.critical_min:.2f}%: {", ".join(failed_files)}')

print(f'Coverage gate passed: global={global_coverage:.2f}%, critical files={len(critical_files)}')
