#!/usr/bin/env python3
"""Freeze the working source in an isolated local Git release; never alter the developer index/branch."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess


def git(root, *arguments):
    return subprocess.check_output(['git', '-C', str(root), *arguments], stderr=subprocess.PIPE)


def update_env(content, values):
    for key, value in values.items():
        pattern = re.compile(r'^' + re.escape(key) + r'=.*$', re.M)
        line = key + '="' + value.replace('\\', '\\\\').replace('"', '\\"') + '"'
        content = pattern.sub(lambda _: line, content, count=1) if pattern.search(content) else content.rstrip() + '\n' + line + '\n'
    return content


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--runtime-root', type=Path, default=Path.home() / 'Services/investing-ops')
    args = parser.parse_args()
    source = args.source.resolve()
    root = args.runtime_root.resolve()
    app = root / 'app'
    if app.exists():
        raise SystemExit('Runtime app already exists; use the reviewed deployment workflow instead of overwriting it.')
    if not (source / '.env').is_file() or not (source / 'public/build/manifest.json').is_file():
        raise SystemExit('Source requires private .env and a successful frontend build.')
    if source == root or root in source.parents or source in root.parents:
        raise SystemExit('Keep runtime and development directories separate.')
    head = git(source, 'rev-parse', 'HEAD').decode().strip()
    paths = set(git(source, 'ls-files', '--cached', '--others', '--exclude-standard', '-z').decode().split('\0')) - {''}
    # No ignored credentials, dumps, dependencies or browser state enter the versioned source snapshot.
    if any(Path(name).name == '.env' or '..' in Path(name).parts for name in paths):
        raise SystemExit('Refusing a source snapshot containing private environment files or unsafe paths.')
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(root, 0o700)
    subprocess.run(['git', 'clone', '--quiet', '--no-hardlinks', str(source), str(app)], check=True)
    os.chmod(app, 0o700)
    hashes = {}
    for name in sorted(paths):
        origin = source / name
        target = app / name
        if not origin.exists():
            if target.is_file():
                target.unlink()
            continue
        if origin.is_symlink() or not origin.is_file():
            raise SystemExit('Refusing a source symlink or non-file: ' + name)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(origin, target)
        hashes[name] = hashlib.sha256(target.read_bytes()).hexdigest()
    shutil.copytree(source / 'public/build', app / 'public/build', dirs_exist_ok=True)
    artifacts = {str(p.relative_to(app)): hashlib.sha256(p.read_bytes()).hexdigest()
                 for p in (app / 'public/build').rglob('*') if p.is_file()}
    manifest = {'format': 1, 'source_commit': head, 'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'source_sha256': hashes, 'built_asset_sha256': artifacts,
                'note': 'Frozen local working-tree release; developer branch and index unchanged. Private .env excluded.'}
    (app / 'DEPLOYMENT-SNAPSHOT.json').write_text(json.dumps(manifest, indent=2) + '\n')
    git(app, 'add', '--all')
    git(app, '-c', 'user.name=Investing Operations Deployment', '-c', 'user.email=local-deployment@localhost',
        'commit', '--quiet', '-m', 'Freeze verified local M4 runtime snapshot')
    # Application-owned credentials remain private and preserve APP_KEY/account authorization continuity.
    env = update_env((source / '.env').read_text(), {'APP_ENV': 'production', 'APP_DEBUG': 'false',
        'APP_URL': 'http://127.0.0.1:8080', 'MCP_OPERATIONS_ENABLED': 'false', 'MCP_MONITORING_ENABLED': 'false',
        'MCP_RESEARCH_ENABLED': 'false', 'LEGACY_CRYPTO_SCHEDULES_ENABLED': 'false', 'BROKERAGE_WRITES_ENABLED': 'false', 'TRADING_ENABLED': 'false'})
    descriptor = os.open(app / '.env', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w') as handle:
        handle.write(env)
    for directory in ['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app/backups']:
        (app / directory).mkdir(mode=0o700, parents=True, exist_ok=True)
    if git(app, 'ls-files', '.env').strip():
        raise SystemExit('Private environment unexpectedly tracked; do not install this release.')
    print(json.dumps({'runtime': str(app), 'release': git(app, 'rev-parse', 'HEAD').decode().strip(), 'source_files': len(hashes),
                      'built_assets': len(artifacts), 'developer_branch_and_index_changed': False, 'services_started': False}))


if __name__ == '__main__':
    main()
