"""Isolated loopback fixtures; sequences and receipt log are owned by the server."""
import gzip
import base64
import hashlib
import http.server
import json
import select
import socket
import socketserver
import ssl
import sys
import threading
import time
from pathlib import Path
from urllib.parse import urlsplit

root = Path(sys.argv[1])
lock = threading.Lock()
sequences = {}


def record(data):
    with lock:
        with (root / "requests.jsonl").open("a") as output:
            output.write(json.dumps(data) + "\n")


class Http(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def do_GET(self):
        request_path = urlsplit(self.path).path
        route_key = self.command + " " + request_path
        with lock:
            sequence_key = route_key if (root / "responses.json").exists() else self.path
            ordinal = sequences.get(sequence_key, 0) + 1
            sequences[sequence_key] = ordinal
        length = int(self.headers.get("Content-Length", "0"))
        if length < 0 or length > 16 * 1024 * 1024:
            self.send_error(413)
            return
        request_body = self.rfile.read(length)
        record({"kind": "http", "path": self.path, "method": self.command, "ordinal": ordinal,
                "headers": dict(self.headers), "body_sha256": hashlib.sha256(request_body).hexdigest()})
        if (root / "responses.json").exists():
            definitions = json.loads((root / "responses.json").read_text())
            responses = definitions.get(route_key)
            if responses:
                response = responses[min(ordinal - 1, len(responses) - 1)]
                body = base64.b64decode(response["body_base64"], validate=True)
                self.send_response(response["status"])
                for name, value in response["headers"].items():
                    self.send_header(name, value)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                if self.command != "HEAD":
                    self.wfile.write(body)
                self.wfile.flush()
                return
        if request_path == "/unavailable-then-drop":
            if ordinal == 1:
                body = b"temporary unavailable"
                self.send_response(503)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
                self.wfile.flush()
            else:
                self.connection.shutdown(socket.SHUT_RDWR)
                self.connection.close()
            return
        if self.path == "/drop" or (self.path == "/sequence" and ordinal <= 2):
            self.connection.shutdown(socket.SHUT_RDWR)
            self.connection.close()
            return
        if self.path == "/headers-late":
            time.sleep(2)
        if request_path == "/stall":
            time.sleep(60)
            return
        if request_path == "/body-partial-stall":
            self.send_response(200)
            self.send_header("Content-Length", "50000")
            self.end_headers()
            self.wfile.write(b"x" * 12415)
            self.wfile.flush()
            time.sleep(60)
            return
        if request_path == "/body-stall":
            self.send_response(200)
            self.send_header("Content-Length", "100")
            self.end_headers()
            self.wfile.flush()
            time.sleep(60)
            return
        if self.path == "/slow45":
            self.send_response(200)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.send_header("Content-Length", "92")
            self.end_headers()
            for _ in range(46):
                self.wfile.write(b"xx")
                self.wfile.flush()
                time.sleep(1)
            return
        body = "Привет".encode()
        status = 200
        headers = {"Content-Type": "text/plain; charset=utf-8"}
        if self.path == "/truncated":
            headers["Content-Length"] = "1000"
            self.close_connection = True
        if self.path in ("/gzip", "/gzip-file", "/biggzip", "/badgzip"):
            body = gzip.compress(body if self.path != "/biggzip" else b"a" * 1000000)
            if self.path != "/gzip-file":
                headers["Content-Encoding"] = "gzip"
            if self.path == "/badgzip":
                body = b"this is not gzip"
        if self.path == "/unsupported":
            headers["Content-Encoding"] = "imaginary"
        if self.path == "/invalid-utf8":
            body = b"\xff"
        if self.path == "/large":
            body = b"x" * 200000
        if self.path.startswith("/redirect"):
            status = 302
            headers["Location"] = {"/redirect": "/ok", "/redirect-loop": "/redirect-loop",
                                   "/redirect-cross": f"http://second.test:{http_service.server_address[1]}/ok",
                                   "/redirect-private": "http://127.0.0.1:1/forbidden",
                                   "/redirect-downgrade": f"http://fixture.test:{http_service.server_address[1]}/ok"}[self.path]
        if self.path.startswith("/quota-") and self.path != "/quota-long":
            body = {"/quota-invalid-utf8": b"\xff", "/quota-badgzip": b"this is not gzip",
                    "/quota-biggzip": gzip.compress(b"a" * 1000000), "/quota-large": b"x" * 200000,
                    "/quota-unsupported": b"encoded"}[self.path]
            if self.path in ("/quota-badgzip", "/quota-biggzip"):
                headers["Content-Encoding"] = "gzip"
            if self.path == "/quota-unsupported":
                headers["Content-Encoding"] = "imaginary"
        if self.path == "/quota" or (self.path.startswith("/quota-") and self.path != "/quota-long"):
            status = 429
            headers["Retry-After"] = "120"
        if self.path == "/quota-long":
            status = 429
            headers["Retry-After"] = "86401"
        if self.path == "/unavailable":
            status = 503
        self.send_response(status)
        for name, value in headers.items():
            self.send_header(name, value)
        if "Content-Length" not in headers:
            self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)
        self.wfile.flush()

    do_HEAD = do_GET
    do_POST = do_GET
    do_PUT = do_GET
    do_PATCH = do_GET
    do_DELETE = do_GET


class Proxy(socketserver.StreamRequestHandler):
    def handle(self):
        line = self.rfile.readline().decode().strip()
        while self.rfile.readline().strip():
            pass
        record({"kind": "connect", "request": line})
        if self.server.stall:
            time.sleep(60)
            return
        if self.server.reject:
            self.wfile.write(b"HTTP/1.1 407 Proxy Authentication Required\r\nContent-Length: 0\r\n\r\n")
            return
        method, target, _ = line.split(" ")
        host, port = target.rsplit(":", 1)
        if method != "CONNECT" or host != "127.0.0.1":
            self.wfile.write(b"HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n")
            return
        with socket.create_connection((host, int(port)), timeout=5) as upstream:
            self.wfile.write(b"HTTP/1.1 200 Connection Established\r\n\r\n")
            self.wfile.flush()
            sockets = [self.connection, upstream]
            while True:
                ready, _, _ = select.select(sockets, [], [], 5)
                if not ready:
                    return
                for source in ready:
                    data = source.recv(65536)
                    if not data:
                        return
                    (upstream if source is self.connection else self.connection).sendall(data)


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


http_service = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Http)
tls = http.server.ThreadingHTTPServer(("127.0.0.1", int(sys.argv[2]) if len(sys.argv) > 2 else 0), Http)
trust = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
trust.load_cert_chain(root / "cert.pem", root / "key.pem")
tls.socket = trust.wrap_socket(tls.socket, server_side=True)
proxy = Server(("127.0.0.1", 0), Proxy)
proxy.reject = False
proxy.stall = False
auth = Server(("127.0.0.1", 0), Proxy)
auth.reject = True
auth.stall = False
stall_proxy = Server(("127.0.0.1", 0), Proxy)
stall_proxy.reject = False
stall_proxy.stall = True
for service in (http_service, tls, proxy, auth, stall_proxy):
    threading.Thread(target=service.serve_forever, daemon=True).start()
print(json.dumps({"http": http_service.server_address[1], "tls": tls.server_address[1],
                  "proxy": proxy.server_address[1], "auth": auth.server_address[1], "stall_proxy": stall_proxy.server_address[1]}), flush=True)
threading.Event().wait()
