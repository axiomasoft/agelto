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
    def workspace_size():
        total = 0
        for base, dirs, files in os.walk(os.getcwd()):
            for name in files:
                path = os.path.join(base, name)
                if not os.path.islink(path):
                    try:
                        total += os.stat(path).st_size
                    except FileNotFoundError:
                        pass
        return total
    allowed_env = (
        'PATH', 'LANG', 'LC_ALL', 'LC_CTYPE', 'LD_LIBRARY_PATH',
        'TESSDATA_PREFIX', 'FONTCONFIG_PATH', 'SOURCE_DATE_EPOCH', 'TZ',
    )
    child_env = {k: os.environ[k] for k in allowed_env if k in os.environ}
    child_env['TMPDIR'] = os.getcwd()
    child_env['HOME'] = os.getcwd()
    child_env['OMP_THREAD_LIMIT'] = '1'
    clk_tck = max(1, os.sysconf('SC_CLK_TCK'))
    page_size = max(4096, os.sysconf('SC_PAGE_SIZE'))
    seen_cpu = {}
    output, errors = receipt + '.out', receipt + '.err'
    started = time.monotonic()
    reason = None
    with open(output, 'xb') as out, open(errors, 'xb') as err:
        try:
            child = subprocess.Popen(command, stdout=out, stderr=err, start_new_session=True, preexec_fn=limits, env=child_env)
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
        def direct_children(pid):
            found = []
            task_dir = f'/proc/{pid}/task'
            try:
                tids = os.listdir(task_dir)
            except OSError:
                return found
            for tid in tids:
                try:
                    with open(f'{task_dir}/{tid}/children') as f:
                        found.extend(int(p) for p in f.read().split())
                except OSError:
                    pass
            return found
        def descendants():
            self_pid = os.getpid()
            queue = direct_children(self_pid)
            visited = set()
            order = []
            while queue:
                pid = queue.pop(0)
                if pid == self_pid or pid in visited:
                    continue
                visited.add(pid)
                order.append(pid)
                queue.extend(direct_children(pid))
            return order
        def proc_usage(pid):
            try:
                with open(f'/proc/{pid}/stat') as f:
                    data = f.read()
                rparen = data.rfind(')')
                if rparen == -1:
                    return 0.0, 0
                fields = data[rparen + 2:].split()
                utime = int(fields[11])
                stime = int(fields[12])
                rss_pages = int(fields[21])
                return (utime + stime) / clk_tck, max(0, rss_pages) * page_size
            except (OSError, ValueError, IndexError):
                return 0.0, 0
        def reap_nonblocking():
            try:
                while os.waitpid(-1, os.WNOHANG)[0] > 0:
                    pass
            except ChildProcessError:
                pass
        try:
            while child.poll() is None:
                reap_nonblocking()
                if stopped:
                    reason = 'cancelled'
                    break
                if time.monotonic() - started >= seconds:
                    reason = 'page_timeout'
                    break
                live_rss = 0
                live_cpu = 0.0
                for pid in descendants():
                    p_cpu, p_rss = proc_usage(pid)
                    if p_cpu > seen_cpu.get(pid, 0.0):
                        seen_cpu[pid] = p_cpu
                    live_cpu += p_cpu
                    live_rss += p_rss
                rusage = resource.getrusage(resource.RUSAGE_CHILDREN)
                reaped_cpu = float(rusage.ru_utime + rusage.ru_stime)
                if live_rss > memory:
                    reason = 'memory_limit'
                    break
                if max(sum(seen_cpu.values()), reaped_cpu + live_cpu) > cpu:
                    reason = 'cpu_limit'
                    break
                if workspace_size() > ceiling:
                    reason = 'tmp_limit'
                    break
                time.sleep(.05)
        finally:
            if group_alive():
                try:
                    os.killpg(pgid, signal.SIGTERM)
                except ProcessLookupError:
                    pass
            for pid in descendants():
                try:
                    os.kill(pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass
            end = time.monotonic() + 1
            while (group_alive() or descendants()) and time.monotonic() < end:
                child.poll()
                reap_nonblocking()
                time.sleep(.02)
            end = time.monotonic() + 2
            while time.monotonic() < end:
                if group_alive():
                    try:
                        os.killpg(pgid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                for pid in descendants():
                    try:
                        os.kill(pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                child.poll()
                reap_nonblocking()
                if not group_alive() and not descendants() and child.poll() is not None:
                    break
                time.sleep(.02)
            child.wait()
            reap_nonblocking()
            if reason is None and workspace_size() > ceiling:
                reason = 'tmp_limit'
            clean = not group_alive() and not descendants()
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
