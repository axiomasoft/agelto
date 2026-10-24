"""Local wire fixtures; these assertions do not activate network/AI domain suites."""

import copy
import hashlib
import http.client
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest.mock import patch


HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]


def load(name):
    spec = importlib.util.spec_from_file_location(name, HERE / (name + ".py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


server = load("server")
manifest = load("fixture_manifest")


class ManifestTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        source = json.loads((ROOT / "tests/Fixtures/ProcessCore/manifest.json").read_text())
        self.entries = copy.deepcopy(source["contract_fixtures"])
        for entry in self.entries:
            target = self.root / entry["path"]
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((ROOT / entry["path"]).read_bytes())

    def verify(self):
        target = self.root / "tests/Fixtures/ProcessCore/manifest.json"
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps({"contract_fixtures": self.entries}))
        return manifest.verify(self.root)

    def test_versioned_inputs_have_manual_provenance_and_hashes(self):
        proof = self.verify()
        self.assertEqual(len(proof["fixtures"]), len(self.entries))
        self.assertTrue(any(entry["id"] == "local-wire-v1" for entry in proof["fixtures"]))
        self.assertEqual(len(proof["manifest_sha256"]), 64)

    def test_missing_changed_and_symlink_inputs_are_rejected(self):
        target = self.root / self.entries[0]["path"]
        original = target.read_bytes()
        for replacement in (None, b"changed"):
            with self.subTest(replacement=replacement):
                if replacement is None:
                    target.unlink()
                else:
                    target.write_bytes(replacement)
                with self.assertRaises(ValueError):
                    self.verify()
                target.write_bytes(original)
        other = self.root / "other.json"
        other.write_bytes(original)
        target.unlink()
        target.symlink_to(other)
        with self.assertRaises(ValueError):
            self.verify()

    def test_invalid_metadata_and_paths_are_rejected(self):
        original = copy.deepcopy(self.entries)
        changes = [("path", "../outside"), ("path", "/outside"), ("path", "bad\\path"),
                   ("sha256", "0" * 64), ("provenance", ""), ("captured_at", "2026-02-30"),
                   ("expected", {"schema_version": True, "assessment": "wire"}),
                   ("expected", {"schema_version": 1}), ("scenario_ids", []),
                   ("scenario_ids", ["T99"]), ("platform", 42), ("adapter_version", "")]
        for key, value in changes:
            with self.subTest(key=key, value=value):
                self.entries = copy.deepcopy(original)
                self.entries[0][key] = value
                with self.assertRaises(ValueError):
                    self.verify()
        self.entries = original + [copy.deepcopy(original[0])]
        with self.assertRaises(ValueError):
            self.verify()
        self.entries = []
        with self.assertRaises(ValueError):
            self.verify()

    def test_malformed_manifest_fails_closed_with_machine_error(self):
        for value in ([], {"contract_fixtures": [None]}, {"contract_fixtures": [42]}):
            with self.subTest(value=value):
                target = self.root / "tests/Fixtures/ProcessCore/manifest.json"
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(json.dumps(value))
                result = subprocess.run([sys.executable, "-B", str(HERE / "fixture_manifest.py"),
                                         str(self.root)], text=True, capture_output=True, check=False)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(json.loads(result.stdout)["error"], "fixture_manifest_invalid")


class WireTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.servers = {}
        cls.threads = []
        for role in ("http", "proxy", "gemini"):
            instance = server.FixtureServer(("127.0.0.1", 0), role)
            cls.servers[role] = instance
            thread = threading.Thread(target=instance.serve_forever,
                                      kwargs={"poll_interval": 0.05}, daemon=True)
            thread.start()
            cls.threads.append(thread)

    @classmethod
    def tearDownClass(cls):
        for instance in cls.servers.values():
            instance.shutdown()
            instance.server_close()
        for thread in cls.threads:
            thread.join(timeout=2)

    def setUp(self):
        for role in self.servers:
            self.request(role, "POST", "/__fixtures/reset")

    def request(self, role, method, path, body=None, headers=None):
        connection = http.client.HTTPConnection("127.0.0.1", self.servers[role].server_port, timeout=3)
        try:
            connection.request(method, path, body=body, headers=headers or {})
            response = connection.getresponse()
            return response.status, dict(response.getheaders()), json.loads(response.read())
        finally:
            connection.close()

    def test_sequence_is_server_owned_and_resettable(self):
        statuses = []
        for _ in range(4):
            status, _, body = self.request("http", "GET", "/fixtures/transient",
                                           headers={"X-Attempt": "999", "Authorization": "fixture-secret"})
            statuses.append(status)
        self.assertEqual(statuses, [503, 503, 200, 200])
        self.assertEqual(body, {"items": [{"id": "00042"}], "complete": True})
        events = self.request("http", "GET", "/__fixtures/log")[2]["requests"]
        self.assertEqual([event["sequence"] for event in events], [1, 2, 3, 4])
        self.assertNotIn("fixture-secret", json.dumps(events))
        self.assertNotIn("999", json.dumps(events))
        self.request("http", "POST", "/__fixtures/reset")
        self.assertEqual(self.request("http", "GET", "/fixtures/transient")[0], 503)

    def test_concurrent_clients_receive_distinct_server_sequences(self):
        barrier = threading.Barrier(8)
        statuses = []
        def send():
            barrier.wait(timeout=3)
            statuses.append(self.request("http", "GET", "/fixtures/transient")[0])
        threads = [threading.Thread(target=send) for _ in range(8)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join(timeout=5)
        self.assertEqual(sorted(statuses), [200] * 6 + [503] * 2)
        events = self.request("http", "GET", "/__fixtures/log")[2]["requests"]
        self.assertEqual(sorted(event["sequence"] for event in events), list(range(1, 9)))

    def test_proxy_uses_real_local_origin_and_records_requests(self):
        def local_origin(_):
            return http.client.HTTPConnection("127.0.0.1", self.servers["http"].server_port, timeout=3)
        with patch.object(server.Fixture, "origin_connection", local_origin):
            statuses = [self.request("proxy", "GET", "http://fixture-http:8080/fixtures/transient")[0]
                        for _ in range(3)]
        self.assertEqual(statuses, [503, 503, 200])
        events = self.request("proxy", "GET", "/__fixtures/log")[2]["requests"]
        self.assertEqual([event["status"] for event in events], statuses)
        self.assertEqual(len(self.request("http", "GET", "/__fixtures/log")[2]["requests"]), 3)

    def test_proxy_rejects_other_destinations_before_connecting(self):
        targets = ["http://example.invalid/", "http://127.0.0.1/", "https://fixture-http/",
                   "http://fixture-http:80/", "http://user:pass@fixture-http:8080/",
                   "http://fixture-http:8080/#fragment", "/relative"]
        with patch.object(server.Fixture, "origin_connection") as connect:
            for target in targets:
                with self.subTest(target=target):
                    self.assertEqual(self.request("proxy", "GET", target)[0], 403)
            status, _, body = self.request("proxy", "CONNECT", "fixture-http:443")
            self.assertEqual(status, 501)
            self.assertEqual(body["error"], "suite_not_implemented")
            connect.assert_not_called()

    def test_fake_llm_preserves_envelope_model_usage_and_request_ids(self):
        catalog = json.loads(server.CATALOG.read_text())
        payload = json.dumps({"contents": [{"role": "user", "parts": [{"text": "fixture"}]}]})
        for case in (case for case in catalog["cases"] if case["role"] == "gemini"):
            with self.subTest(case=case["id"]):
                status, headers, envelope = self.request("gemini", "POST", case["path"], payload,
                                                          {"Content-Type": "application/json"})
                expected = case["responses"][0]
                self.assertEqual(status, expected["status"])
                self.assertEqual(envelope, expected["body"])
                self.assertEqual(headers["x-goog-request-id"], expected["headers"]["x-goog-request-id"])
                candidate = envelope["candidates"][0]
                if candidate["finishReason"] == "STOP":
                    self.assertEqual(json.loads(candidate["content"]["parts"][0]["text"]),
                                     case["expected_model_json"])
                else:
                    self.assertEqual(candidate["finishReason"], "MAX_TOKENS")
                    with self.assertRaises(json.JSONDecodeError):
                        json.loads(candidate["content"]["parts"][0]["text"])
        events = self.request("gemini", "GET", "/__fixtures/log")[2]["requests"]
        self.assertEqual(len(events), 2)
        self.assertTrue(all(event["body_sha256"] == hashlib.sha256(payload.encode()).hexdigest()
                            for event in events))

    def test_invalid_requests_do_not_consume_sequence(self):
        path = "/v1beta/models/fixture-gemini:generateContent"
        self.assertEqual(self.request("gemini", "POST", path, "not json")[0], 400)
        self.assertEqual(self.request("gemini", "POST", path, "{}")[0], 400)
        self.assertEqual(self.request("gemini", "GET", path)[0], 405)
        self.assertEqual(self.request("http", "GET", "/missing")[0], 404)
        self.assertEqual(self.request("http", "POST", "/fixtures/transient", b"x" * 65537)[0], 413)
        self.assertEqual(self.request("gemini", "GET", "/__fixtures/log")[2]["requests"], [])
        self.assertEqual(self.request("http", "GET", "/__fixtures/log")[2]["requests"], [])

    def test_full_log_refuses_send_before_origin_connection(self):
        proxy = self.servers["proxy"]
        with proxy.lock:
            proxy.events = [{}] * server.MAX_LOG
        with patch.object(server.Fixture, "origin_connection") as connect:
            self.assertEqual(self.request("proxy", "GET", "http://fixture-http:8080/")[0], 507)
            connect.assert_not_called()


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromModule(sys.modules[__name__])
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    print(json.dumps({"executed": result.testsRun, "skipped": len(result.skipped)}))
    raise SystemExit(0 if result.wasSuccessful() and result.testsRun > 0 and not result.skipped else 1)
