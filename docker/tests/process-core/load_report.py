"""Fail closed on missing/short load evidence; never infer a soak from a unit pass."""

import math


def validate(report, width):
    def need(condition, message):
        if not condition:
            raise ValueError(message)

    need(report.get("format") == "process-core-load/v1", "missing load report")
    need(width in (10, 20) and report.get("width") == width, "wrong WIDTH")
    need(report.get("running_cap") == 10 and report.get("worker_rss_cap_kib") == 262144
         and report.get("controller_rss_cap_kib") == 262144 and report.get("rss_noise_kib") == 8192,
         "changed declared resource caps")
    need(report.get("duration_seconds", 0) >= 1800, "load duration below 30 minutes")
    need(report.get("documents", 0) >= 1000 and report.get("platforms", 0) >= 2,
         "load corpus below 1000 documents/two platforms")
    need(report.get("slices_per_work", 0) >= 2, "missing multiple-slice work")
    need(len(report.get("feeder_races", [])) >= 2 and all(r["a"]["pid"] != r["b"]["pid"]
         for r in report["feeder_races"]), "missing concurrent feeders")
    need(report.get("accepted") == report["documents"], "lost/duplicate accepted work")
    for key in ("unaccounted_work", "active_reservations_after_drain", "pending_outbox_after_drain", "fifo_grant_inversions"):
        need(report.get(key) == 0, key)
    need(report.get("requests", 0) >= report["documents"] * report["slices_per_work"]
         and report.get("server_receipts", 0) >= report["documents"] * report["slices_per_work"],
         "missing real HTTP for slices")
    faults = report.get("faults", [])
    need(len(faults) >= 2 and all(f.get("boundary") == "after-lease" and f.get("signal") == "SIGKILL"
         and f.get("work") and f.get("redis_flush") and f.get("horizon_restart") for f in faults),
         "missing repeated named faults/restarts")
    samples = report.get("samples", [])
    need(len(samples) >= 1000, "missing time series")
    times = [sample["at_seconds"] for sample in samples]
    need(all(type(t) in (int, float) and math.isfinite(t) and t >= 0 for t in times)
         and all(a < b for a, b in zip(times, times[1:])), "invalid sample timestamps")
    need(samples[-1]["at_seconds"] - samples[0]["at_seconds"] >= 1790, "missing sustained sampling")
    for sample in samples:
        need(0 <= sample["running"] <= report["running_cap"] <= 10
             and sample["running"] <= sample["outstanding"] <= width, "W/C violation")
        need(sample["controller_rss_kib"] <= report["controller_rss_cap_kib"], "controller RSS cap")
        need(sample["outbox_age_seconds"] <= 60, "pending outbox age exceeds 60 seconds")
        need(sample["network_queued"] <= 64, "network demand bound")
    warm = [sample for sample in samples if sample["at_seconds"] >= 60]
    need(warm[-1]["controller_rss_kib"] - warm[0]["controller_rss_kib"] <= report["rss_noise_kib"],
         "controller RSS growth above declared noise; investigate")
    workers = report.get("worker_observations", [])
    need(len(workers) >= report["documents"] * report["slices_per_work"], "missing worker RSS")
    by_pid = {}
    for sample in workers:
        need(0 < sample["rss_kib"] <= report["worker_rss_cap_kib"], "worker RSS cap")
        by_pid.setdefault(sample["pid"], []).append(sample["rss_kib"])
    for readings in by_pid.values():
        if len(readings) > 32:
            need(readings[-1] - readings[31] <= report["rss_noise_kib"], "worker RSS growth; investigate")
    need(report.get("history_rows", 0) >= report["documents"] * 3, "unaccounted publication history")
    return {"width": width, "documents": report["documents"], "seconds": report["duration_seconds"],
            "faults": len(faults), "samples": len(samples), "verdict": "pass"}
