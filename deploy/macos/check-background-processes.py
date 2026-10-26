#!/usr/bin/env python3
"""Native macOS scheduler process-lifetime drill. Temporary file probes only; no app, DB or broker calls."""
import json
import os
from pathlib import Path
import plistlib
import shlex
import subprocess
import sys
import tempfile
import time

if sys.platform != 'darwin':
    raise SystemExit('This native launchd drill requires macOS.')

template = plistlib.loads((Path(__file__).parent / 'templates/launchd/scheduler.plist').read_bytes())
if template.get('AbandonProcessGroup') is not True:
    raise SystemExit('Scheduler template does not retain background jobs.')

results = {}
with tempfile.TemporaryDirectory(prefix='mcp-scheduler-process-drill-') as temporary:
    root = Path(temporary)
    for retained in [False, True]:
        label = f'local.investing-ops.process-drill-{os.getpid()}-{int(retained)}'
        target = f'gui/{os.getuid()}/{label}'
        marker = root / f'finished-{int(retained)}'
        job = {'Label': label, 'RunAtLoad': True, 'AbandonProcessGroup': retained,
               'ProgramArguments': ['/bin/sh', '-c', f'(/bin/sleep 2; /usr/bin/touch {shlex.quote(str(marker))}) >/dev/null 2>&1 &'],
               'StandardOutPath': str(root / 'out'), 'StandardErrorPath': str(root / 'err')}
        path = root / f'{label}.plist'
        path.write_bytes(plistlib.dumps(job))
        path.chmod(0o600)
        loaded = False
        try:
            subprocess.run(['launchctl', 'bootstrap', f'gui/{os.getuid()}', str(path)], check=True, capture_output=True, timeout=10)
            loaded = True
            # The child waits two seconds after its parent exits. Three seconds distinguishes retention from cleanup.
            time.sleep(3)
            results['configured' if retained else 'default_control'] = marker.exists()
        finally:
            if loaded:
                subprocess.run(['launchctl', 'bootout', target], capture_output=True, timeout=10)

print(json.dumps(results))
if results != {'default_control': False, 'configured': True}:
    raise SystemExit('Background process retention drill failed.')
