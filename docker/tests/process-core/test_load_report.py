import copy
import unittest

from load_report import validate


class LoadEvidenceTest(unittest.TestCase):
    def corpus(self):
        return {"format": "process-core-load/v1", "width": 10, "running_cap": 10,
                "duration_seconds": 1800, "documents": 1000, "platforms": 2, "slices_per_work": 3,
                "accepted": 1000, "unaccounted_work": 0, "active_reservations_after_drain": 0,
                "pending_outbox_after_drain": 0, "fifo_grant_inversions": 0, "requests": 3000,
                "server_receipts": 3000, "history_rows": 10000, "worker_rss_cap_kib": 262144,
                "feeder_races": [{"a": {"pid": 1}, "b": {"pid": 2}} for _ in range(2)],
                "controller_rss_cap_kib": 262144, "rss_noise_kib": 8192,
                "faults": [{"boundary": "after-lease", "signal": "SIGKILL", "work": str(n),
                            "redis_flush": True, "horizon_restart": True} for n in range(2)],
                "samples": [{"at_seconds": n, "running": 5, "outstanding": 10,
                             "outbox_age_seconds": 1, "network_queued": 0, "controller_rss_kib": 50000} for n in range(1801)],
                "worker_observations": [{"pid": 42, "rss_kib": 50000} for _ in range(3000)]}

    def test_complete_profile_is_validated_independently_for_each_width(self):
        for width in (10, 20):
            report = self.corpus()
            report["width"] = width
            self.assertEqual(validate(report, width)["verdict"], "pass")

    def test_each_missing_or_shortened_contract_fails(self):
        for field, value in [("duration_seconds", 1799), ("documents", 999), ("platforms", 1),
                             ("slices_per_work", 1), ("accepted", 1001), ("unaccounted_work", 1),
                             ("active_reservations_after_drain", 1), ("pending_outbox_after_drain", 1),
                             ("fifo_grant_inversions", 1), ("faults", []), ("samples", []),
                             ("worker_observations", []), ("server_receipts", 0),
                             ("worker_rss_cap_kib", 999999), ("rss_noise_kib", 999999)]:
            report = self.corpus()
            report[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                validate(report, 10)

    def test_oversubscription_lag_and_rss_growth_fail(self):
        for field, value in [("running", 11), ("outstanding", 21), ("outbox_age_seconds", 61),
                             ("controller_rss_kib", 270000)]:
            report = copy.deepcopy(self.corpus())
            report["samples"][-1][field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                validate(report, 10)
        report = self.corpus()
        report["worker_observations"][-1]["rss_kib"] = 60000
        with self.assertRaisesRegex(ValueError, "growth"):
            validate(report, 10)

    def test_sample_timestamps_must_form_a_real_time_series(self):
        for value in (0, -1, float("nan")):
            report = self.corpus()
            report["samples"][1]["at_seconds"] = value
            with self.subTest(value=value), self.assertRaisesRegex(ValueError, "timestamps"):
                validate(report, 10)
