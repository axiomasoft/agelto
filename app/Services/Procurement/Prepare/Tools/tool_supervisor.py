#!/usr/bin/env python3
"""Bounded local converter supervisor. Survives worker death; reaps adopted tool descendants."""
import ctypes
import json
import os
import platform
import resource
import signal
import subprocess
import sys
import time


class LandlockRulesetAttr(ctypes.Structure):
    _fields_ = [
        ('handled_access_fs', ctypes.c_uint64),
        ('handled_access_net', ctypes.c_uint64),
    ]


class LandlockPathBeneathAttr(ctypes.Structure):
    _pack_ = 1
    _fields_ = [
        ('allowed_access', ctypes.c_uint64),
        ('parent_fd', ctypes.c_int32),
    ]


class SockFilter(ctypes.Structure):
    _fields_ = [
        ('code', ctypes.c_uint16),
        ('jt', ctypes.c_uint8),
        ('jf', ctypes.c_uint8),
        ('k', ctypes.c_uint32),
    ]


class SockFprog(ctypes.Structure):
    _fields_ = [
        ('len', ctypes.c_ushort),
        ('filter', ctypes.POINTER(SockFilter)),
    ]


def main():
    proof_token = os.environ.pop('_AGELTO_SUPERVISOR_TOKEN', None)
    max_procs = 64
    max_output_bytes = None
    input_file = None
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
        if arg.startswith('--max-output-bytes='):
            max_output_bytes = int(arg.split('=', 1)[1])
            idx += 1
            continue
        if arg.startswith('--input-file='):
            input_file = arg.split('=', 1)[1]
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
    def write_receipt(stopped_ok, exit_code, stop_reason, group_id):
        payload = {
            'stopped': stopped_ok,
            'exit': exit_code,
            'reason': stop_reason,
            'pgid': group_id,
        }
        if proof_token is not None:
            payload['token'] = proof_token
        tmp_receipt = receipt + '.new'
        try:
            os.unlink(tmp_receipt)
        except FileNotFoundError:
            pass
        with open(tmp_receipt, 'w') as f:
            json.dump(payload, f)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp_receipt, receipt)
        active_path = receipt + '.active'
        if stopped_ok:
            try:
                os.unlink(active_path)
            except FileNotFoundError:
                pass
        else:
            with open(active_path, 'w') as f:
                f.write('supervised')
                f.flush()
                os.fsync(f.fileno())
    machine = platform.machine()
    if platform.system() != 'Linux' or machine != 'x86_64' or ctypes.sizeof(ctypes.c_void_p) != 8:
        print(f'Unsupported sandbox platform: {platform.system()} {machine}', file=sys.stderr)
        write_receipt(True, 126, 'unsupported_sandbox_architecture', None)
        return 0
    libc = ctypes.CDLL(None, use_errno=True)
    libc.prctl.argtypes = [ctypes.c_int, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong]
    libc.prctl.restype = ctypes.c_int
    # Linux subreaper: grandchildren are ours to reap, including tools whose leader exited.
    if libc.prctl(36, 1, 0, 0, 0) != 0:
        raise RuntimeError("subreaper unavailable")
    stopped = False
    def stop(signum, frame):
        nonlocal stopped
        stopped = True
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    real_cwd = os.path.realpath(os.getcwd())
    app_root = os.path.realpath(os.path.join(os.path.dirname(__file__), '..', '..', '..', '..'))
    forbidden_prefixes = (
        '/run',
        '/var/run',
        '/tmp',
        '/var/tmp',
        os.path.join(app_root, 'storage'),
        os.path.join(app_root, 'local', 'secrets'),
        '/var/www/html/storage',
        '/var/www/html/local/secrets',
    )
    def is_within(path, prefix):
        return path == prefix or path.startswith(prefix.rstrip('/') + '/')
    ro_input_files = []
    # Explicit inputs come from the caller's local staging area. Grant access to the file
    # itself so the sandbox need not expose the staging directory or copy large archives.
    if input_file is not None:
        real_input = os.path.realpath(input_file)
        if input_file not in command[1:] or not os.path.isfile(real_input):
            write_receipt(True, 126, 'invalid_converter_input', None)
            return 0
    # The executable path comes from tool configuration, independently of document metadata.
    if os.path.isabs(command[0]) and os.path.isfile(command[0]):
        ro_input_files.append(os.path.realpath(command[0]))
    for arg in command if input_file is None else ():
        if not arg or arg.startswith('-'):
            continue
        try:
            real_arg = os.path.realpath(arg)
        except OSError:
            continue
        if not os.path.isfile(real_arg) or is_within(real_arg, real_cwd):
            continue
        base_name = os.path.basename(real_arg)
        if base_name == '.env' or base_name.startswith('.env.'):
            continue
        if any(is_within(real_arg, prefix) for prefix in forbidden_prefixes):
            continue
        if real_arg not in ro_input_files:
            ro_input_files.append(real_arg)
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
        if libc.prctl(38, 1, 0, 0, 0) != 0:
            raise OSError('no_new_privs_unavailable')
        abi = libc.syscall(444, ctypes.c_void_p(None), ctypes.c_size_t(0), ctypes.c_uint32(1))
        if abi < 1:
            raise OSError('landlock_unavailable')
        fs_mask = (1 << 13) - 1 if abi == 1 else ((1 << 14) - 1 if abi == 2 else (1 << 15) - 1)
        net_mask = 3 if abi >= 4 else 0
        attr = LandlockRulesetAttr(fs_mask, net_mask)
        attr_size = ctypes.sizeof(attr) if abi >= 4 else 8
        ruleset_fd = libc.syscall(444, ctypes.byref(attr), ctypes.c_size_t(attr_size), ctypes.c_uint32(0))
        if ruleset_fd < 0:
            raise OSError('landlock_ruleset_failed')
        o_path = getattr(os, 'O_PATH', 0o10000000)
        file_mask = ((1 << 0) | (1 << 1) | (1 << 2) | (1 << 14)) & fs_mask
        ro_dir_mask = ((1 << 0) | (1 << 2) | (1 << 3)) & fs_mask
        ro_file_mask = ((1 << 0) | (1 << 2)) & fs_mask
        def add_landlock_path(path, access):
            if not os.path.exists(path):
                return
            try:
                fd = os.open(path, o_path | os.O_CLOEXEC)
            except OSError:
                return
            try:
                eff = access if os.path.isdir(path) else (access & file_mask)
                if eff == 0:
                    return
                rule = LandlockPathBeneathAttr(eff, fd)
                if libc.syscall(445, ctypes.c_int(ruleset_fd), ctypes.c_int(1), ctypes.byref(rule), ctypes.c_uint32(0)) != 0:
                    raise OSError('landlock_rule_failed')
            finally:
                os.close(fd)
        try:
            for rw_path in (real_cwd, '/dev/null', '/dev/zero', '/dev/urandom', '/dev/random', '/dev/shm'):
                add_landlock_path(rw_path, fs_mask)
            for sys_path in ('/usr', '/bin', '/sbin', '/lib', '/lib32', '/lib64', '/etc', '/proc', '/sys', '/var/cache', '/var/lib'):
                add_landlock_path(sys_path, ro_dir_mask)
            for ro_file in ro_input_files:
                add_landlock_path(ro_file, ro_file_mask)
            if input_file is not None:
                add_landlock_path(real_input, 1 << 2)
            if libc.syscall(446, ctypes.c_int(ruleset_fd), ctypes.c_uint32(0)) != 0:
                raise OSError('landlock_restrict_failed')
        finally:
            os.close(ruleset_fd)
        insns = (SockFilter * 11)(
            SockFilter(0x20, 0, 0, 4),
            SockFilter(0x15, 0, 7, 0xc000003e),
            SockFilter(0x20, 0, 0, 0),
            SockFilter(0x15, 5, 0, 42),
            SockFilter(0x15, 4, 0, 49),
            SockFilter(0x15, 0, 4, 41),
            SockFilter(0x20, 0, 0, 16),
            SockFilter(0x15, 1, 0, 2),
            SockFilter(0x15, 0, 1, 10),
            SockFilter(0x06, 0, 0, 0x0005000d),
            SockFilter(0x06, 0, 0, 0x7fff0000),
        )
        prog = SockFprog(len(insns), insns)
        if libc.prctl(22, 2, ctypes.addressof(prog), 0, 0) != 0:
            raise OSError('seccomp_unavailable')
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
            child = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=out, stderr=err, close_fds=True, start_new_session=True, preexec_fn=limits, env=child_env)
        except OSError:
            write_receipt(True, 127, 'converter_unavailable', None)
            return 0
        except subprocess.SubprocessError:
            write_receipt(True, 126, 'sandbox_setup_failed', None)
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
                if max_output_bytes is not None and os.fstat(out.fileno()).st_size > max_output_bytes:
                    reason = 'tool_output_limit'
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
            if reason is None and max_output_bytes is not None and os.fstat(out.fileno()).st_size > max_output_bytes:
                reason = 'tool_output_limit'
            clean = not group_alive() and not descendants()
            write_receipt(clean, child_exit if child_exit is not None else child.returncode, reason, pgid)
    return 0


if __name__ == '__main__':
    sys.exit(main())
