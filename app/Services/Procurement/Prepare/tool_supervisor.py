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
    max_procs = 64
    args = sys.argv[1:]
    pos = []
    idx = 0
    while idx < len(args):
        arg = args[idx]
        if arg == '--max-procs':
            if idx + 1 >= len(args):
                raise ValueError('--max-procs requires an integer argument')
            max_procs = int(args[idx + 1])
            idx += 2
            continue
        if arg.startswith('--max-procs='):
            max_procs = int(arg.split('=', 1)[1])
            idx += 1
            continue
        if len(pos) < 5:
            pos.append(arg)
            idx += 1
            continue
        break
    receipt, seconds, ceiling, memory, cpu = pos
    command = args[idx:]
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
        try:
            _, hard_nofile = resource.getrlimit(resource.RLIMIT_NOFILE)
            target_nofile = 1024 if hard_nofile == resource.RLIM_INFINITY else min(1024, hard_nofile)
            if target_nofile > 0:
                resource.setrlimit(resource.RLIMIT_NOFILE, (target_nofile, target_nofile))
        except (OSError, ValueError):
            pass
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
        child_exit = None
        def poll_child():
            nonlocal child_exit
            if child_exit is not None:
                return child_exit
            code = child.poll()
            if code is not None:
                child_exit = code
            return child_exit
        def reap_nonblocking():
            nonlocal child_exit
            try:
                while True:
                    pid, status = os.waitpid(-1, os.WNOHANG)
                    if pid <= 0:
                        break
                    if pid == child.pid:
                        child_exit = os.waitstatus_to_exitcode(status)
                        child.returncode = child_exit
            except ChildProcessError:
                pass
        try:
            while poll_child() is None:
                reap_nonblocking()
                if child_exit is not None:
                    break
                if stopped:
                    reason = 'cancelled'
                    break
                if time.monotonic() - started >= seconds:
                    reason = 'page_timeout'
                    break
                pids = descendants()
                if max_procs > 0 and len(pids) > max_procs:
                    reason = 'nproc_limit'
                    if group_alive():
                        try:
                            os.killpg(pgid, signal.SIGKILL)
                        except ProcessLookupError:
                            pass
                    for pid in pids:
                        try:
                            os.kill(pid, signal.SIGKILL)
                        except ProcessLookupError:
                            pass
                    break
                live_rss = 0
                live_cpu = 0.0
                for pid in pids:
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
            if reason is None and max_procs > 0 and len(descendants()) > max_procs:
                reason = 'nproc_limit'
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
                reap_nonblocking()
                poll_child()
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
                reap_nonblocking()
                poll_child()
                if not group_alive() and not descendants() and poll_child() is not None:
                    break
                time.sleep(.02)
            if poll_child() is None:
                try:
                    _, status = os.waitpid(child.pid, 0)
                    child_exit = os.waitstatus_to_exitcode(status)
                    child.returncode = child_exit
                except ChildProcessError:
                    poll_child()
            reap_nonblocking()
            if reason is None and workspace_size() > ceiling:
                reason = 'tmp_limit'
            clean = not group_alive() and not descendants()
            with open(receipt + '.new', 'x') as f:
                json.dump({'stopped': clean, 'exit': child_exit if child_exit is not None else child.returncode, 'reason': reason, 'pgid': pgid}, f)
                f.flush()
                os.fsync(f.fileno())
            os.rename(receipt + '.new', receipt)
            if clean:
                os.unlink(receipt + '.active')
    return 0


if __name__ == '__main__':
    sys.exit(main())
