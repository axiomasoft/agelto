"""Local deterministic wire fixtures; no arbitrary outbound destinations."""
import hashlib
import http.client
import http.server
import json
from pathlib import Path
import sys
import threading
from urllib.parse import urlsplit

CATALOG = Path(__file__).with_name("fixtures") / "wire-v1.json"
MAX_BODY = 65536
MAX_LOG = 1024


class FixtureServer(http.server.ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, address, role):
        if role not in ("http", "proxy", "gemini"):
            raise ValueError("unknown fixture role")
        catalog = json.loads(CATALOG.read_text())
        if catalog["schema_version"] != 1 or not catalog["cases"]:
            raise ValueError("unsupported/empty fixture catalog")
        self.role = role
        self.cases = {case["path"]: case for case in catalog["cases"] if case["role"] == role}
        self.lock = threading.Lock()
        self.counts = {}
        self.events = []
        super().__init__(address, Fixture)

    def record(self, case_id, method, body, responses):
        with self.lock:
            if len(self.events) >= MAX_LOG:
                return 507, {}, {"error": "fixture_log_full"}
            sequence = self.counts.get(case_id, 0) + 1
            self.counts[case_id] = sequence
            response = responses[min(sequence, len(responses)) - 1]
            self.events.append({"case": case_id, "sequence": sequence, "method": method,
                                "status": response["status"], "body_sha256": hashlib.sha256(body).hexdigest()})
            return response["status"], response["headers"], response["body"]


class Fixture(http.server.BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(3)

    def log_message(self, *_):
        pass

    def send_bytes(self, status, body, headers=None):
        self.send_response(status)
        self.send_header("Content-Length", str(len(body)))
        for key, value in (headers or {}).items():
            if key.lower() not in ("content-length", "connection", "transfer-encoding", "server", "date"):
                self.send_header(key, value)
        self.end_headers()
        self.wfile.write(body)

    def send_json(self, status, body, headers=None):
        self.send_bytes(status, json.dumps(body).encode(), {"Content-Type": "application/json", **(headers or {})})

    def do_CONNECT(self):
        self.send_json(501, {"error": "suite_not_implemented", "capability": "CONNECT/TLS"})

    def do_GET(self):
        self.dispatch()

    def do_POST(self):
        self.dispatch()

    def dispatch(self):
        length = self.headers.get("Content-Length", "0")
        if self.headers.get("Transfer-Encoding") or not length.isascii() or not length.isdecimal():
            self.send_json(400, {"error": "invalid_body_framing"})
            return
        if len(length) > 8 or int(length) > MAX_BODY:
            self.send_json(413, {"error": "body_too_large"})
            return
        body = self.rfile.read(int(length))
        if len(body) != int(length):
            self.send_json(400, {"error": "incomplete_body"})
            return
        try:
            target = urlsplit(self.path)
        except ValueError:
            self.send_json(400, {"error": "invalid_url"})
            return
        if not target.netloc and not target.scheme and target.path == "/__fixtures/log" and self.command == "GET":
            with self.server.lock:
                events = list(self.server.events)
            self.send_json(200, {"requests": events})
            return
        if not target.netloc and not target.scheme and target.path == "/__fixtures/reset" and self.command == "POST":
            with self.server.lock:
                self.server.counts.clear()
                self.server.events.clear()
            self.send_json(200, {"reset": True})
            return
        if self.server.role == "proxy":
            self.forward(target, body)
            return
        if target.netloc or target.scheme:
            self.send_json(400, {"error": "absolute_url_not_allowed"})
            return
        if self.command == "GET" and target.path in ("/", "/health"):
            self.send_json(200, {"fixture": self.server.role, "ok": True})
            return
        case = self.server.cases.get(target.path)
        if case is None:
            self.send_json(404, {"error": "unknown_fixture"})
            return
        if case["method"] != self.command:
            self.send_json(405, {"error": "fixture_method_mismatch"})
            return
        if self.server.role == "gemini":
            try:
                request = json.loads(body)
                valid = isinstance(request, dict) and isinstance(request.get("contents"), list) and bool(request["contents"])
            except (ValueError, UnicodeError):
                valid = False
            if not valid:
                self.send_json(400, {"error": "invalid_generate_content_request"})
                return
        status, headers, response = self.server.record(case["id"], self.command, body, case["responses"])
        self.send_json(status, response, headers)

    def origin_connection(self):
        return http.client.HTTPConnection("fixture-http", 8080, timeout=3)

    def forward(self, target, body):
        try:
            allowed = (target.scheme == "http" and target.hostname == "fixture-http"
                       and target.port in (None, 8080) and target.username is None
                       and target.password is None and not target.fragment)
        except ValueError:
            allowed = False
        if not allowed:
            self.send_json(403, {"error": "fixture_origin_denied"})
            return
        with self.server.lock:
            full = len(self.server.events) >= MAX_LOG
        if full:
            self.send_json(507, {"error": "fixture_log_full"})
            return
        connection = self.origin_connection()
        try:
            path = target.path or "/"
            if target.query:
                path += "?" + target.query
            connection.request(self.command, path, body=body, headers={"Content-Type": "application/json"})
            response = connection.getresponse()
            raw = response.read(MAX_BODY + 1)
            if len(raw) > MAX_BODY:
                raise ValueError("origin_body_too_large")
            status, _, _ = self.server.record("proxy-http", self.command, body, [{"status": response.status, "headers": {}, "body": {}}])
            if status == 507:
                self.send_json(status, {"error": "fixture_log_full"})
            else:
                self.send_bytes(status, raw, dict(response.getheaders()))
        except (OSError, http.client.HTTPException, ValueError):
            self.send_json(502, {"error": "fixture_origin_unavailable"})
        finally:
            connection.close()


if __name__ == "__main__":
    FixtureServer(("0.0.0.0", 8080), sys.argv[1]).serve_forever()
