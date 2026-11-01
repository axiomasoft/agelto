#!/usr/bin/env python3
"""Bounded local converter supervisor. Survives worker death; reaps adopted tool descendants."""
import ctypes
import json
import os
import resource
import signal
import subprocess
import sys
import time


def main():
    receipt, seconds, ceiling, memory, cpu, *command = sys.argv[1:]
    seconds, ceiling, memory, cpu = float(seconds), int(ceiling), int(memory), int(cpu)
    # Linux subreaper: grandchildren are ours to reap, including tools whose leader exited.
    if ctypes.CDLL(None, use_errno=True).prctl(36, 1, 0, 0, 0) != 0:
        raise RuntimeError("subreaper unavailable")
    stopped = False
    def stop(signum, frame):
        nonlocal stopped
        stopped = True
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    def limits():
        resource.setrlimit(resource.RLIMIT_AS, (memory, memory))
        resource.setrlimit(resource.RLIMIT_CPU, (cpu, cpu))
        resource.setrlimit(resource.RLIMIT_FSIZE, (ceiling, ceiling))
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    output, errors = receipt + '.out', receipt + '.err'
    started = time.monotonic()
    reason = None
    with open(output, 'xb') as out, open(errors, 'xb') as err:
        try:
            child = subprocess.Popen(command, stdout=out, stderr=err, start_new_session=True, preexec_fn=limits)
        except OSError:
            with open(receipt, 'x') as f:
                json.dump({'stopped': True, 'exit': 127, 'reason': 'converter_unavailable', 'pgid': None}, f)
            os.unlink(receipt + '.active')
            return 0
        pgid = child.pid
        def group_alive():
            try:
                os.killpg(pgid, 0)
                return True
            except ProcessLookupError:
                return False
        def children():
            path = '/proc/self/task/'+str(os.getpid())+'/children'
            with open(path) as f:
                return [int(pid) for pid in f.read().split()]
        try:
            while child.poll() is None:
                if stopped:
                    reason = 'cancelled'
                    break
                if time.monotonic() - started >= seconds:
                    reason = 'page_timeout'
                    break
                size = 0
                for base, dirs, files in os.walk(os.getcwd()):
                    for name in files:
                        path = os.path.join(base, name)
                        if not os.path.islink(path):
                            try:
                                size += os.stat(path).st_size
                            except FileNotFoundError:
                                pass
                if size > ceiling:
                    reason = 'tmp_limit'
                    break
                time.sleep(.05)
        finally:
            if group_alive():
                os.killpg(pgid, signal.SIGTERM)
            end = time.monotonic() + 1
            while group_alive() and time.monotonic() < end:
                child.poll()
                try:
                    while os.waitpid(-1, os.WNOHANG)[0] > 0:
                        pass
                except ChildProcessError:
                    pass
                time.sleep(.02)
            if group_alive():
                os.killpg(pgid, signal.SIGKILL)
            for pid in children():
                try:
                    os.kill(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
            child.wait()
            end = time.monotonic() + 2
            while time.monotonic() < end:
                try:
                    pid, _ = os.waitpid(-1, os.WNOHANG)
                    if pid == 0:
                        time.sleep(.02)
                        continue
                except ChildProcessError:
                    break
            clean = not group_alive() and not children()
            with open(receipt + '.new', 'x') as f:
                json.dump({'stopped': clean, 'exit': child.returncode, 'reason': reason, 'pgid': pgid}, f)
                f.flush()
                os.fsync(f.fileno())
            os.rename(receipt + '.new', receipt)
            if clean:
                os.unlink(receipt + '.active')
    return 0


if __name__ == '__main__':
    sys.exit(main())
