import os
import signal
import subprocess
import sys
import time

signal.signal(signal.SIGTERM, signal.SIG_IGN)
child = subprocess.Popen([sys.executable, '-c', "import signal,time;signal.signal(signal.SIGTERM,signal.SIG_IGN);time.sleep(60)"])
with open(sys.argv[1]+'/pids', 'w') as f:
    f.write(str(os.getpid())+' '+str(child.pid))
while True:
    time.sleep(.1)
