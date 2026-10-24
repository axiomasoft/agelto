"""Local-only wire fixtures. No forwarding to arbitrary upstreams."""
import http.client
import http.server
import json
import sys
from urllib.parse import urlsplit


class Fixture(http.server.BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def do_CONNECT(self):
        self.send_error(403, "CONNECT suite_not_implemented")

    def do_GET(self):
        if sys.argv[1] == "proxy":
            target = urlsplit(self.path)
            if target.hostname != "fixture-http" or target.port not in (None, 8080):
                self.send_error(403, "fixture destination required")
                return
            conn = http.client.HTTPConnection("fixture-http", 8080, timeout=3)
            conn.request("GET", target.path or "/")
            response = conn.getresponse()
            body = response.read()
            conn.close()
        else:
            body = json.dumps({"fixture": sys.argv[1], "ok": True}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        # Explicitly a fixture, never an assertion of the future AI wire contract.
        self.rfile.read(int(self.headers.get("Content-Length", "0")))
        self.do_GET()


if __name__ == "__main__":
    http.server.ThreadingHTTPServer(("0.0.0.0", 8080), Fixture).serve_forever()
