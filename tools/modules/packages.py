"""Build independently versioned PHP/npm source packages using only tracked files.

No install, upload, registry credentials or production changes are performed.
"""
import argparse
import gzip
import hashlib
import io
import json
import re
import subprocess
import tarfile
import zipfile
from pathlib import Path

VERSION = re.compile(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z')


def read(path):
    return json.loads(path.read_text(encoding='utf-8'))


def tracked(root):
    result = subprocess.run(['git', 'ls-files', '-z'], cwd=root, check=True, capture_output=True)
    return [Path(p) for p in result.stdout.decode().split('\0') if p]


def catalog(root):
    rows = []
    for kind, folder, filename in [('php', 'app/packages', 'composer.json'), ('npm', 'admin-ui/packages', 'package.json')]:
        for path in sorted((root / folder).glob('*/' + filename)):
            data = read(path)
            if not VERSION.fullmatch(data.get('version', '')):
                raise ValueError(f'Invalid fixed version: {path}')
            rows.append({'kind': kind, 'directory': str(path.parent.relative_to(root)).replace('\\', '/'),
                         'name': data['name'], 'version': data['version'], 'manifest': data})
    names = {row['name']: row for row in rows}
    if len(names) != len(rows):
        raise ValueError('Duplicate package names')
    for path, key in [('app/composer.json', 'require'), ('admin-ui/package.json', 'dependencies')]:
        if (root / path).exists():
            for name, version in read(root / path).get(key, {}).items():
                if name.startswith(('platzhirsch/', '@platzhirsch/')) and (name not in names or names[name]['version'] != version):
                    raise ValueError(f'Application pin does not match {name} {version}')
    for row in rows:
        data = row['manifest']
        deps = data.get('require', {}) if row['kind'] == 'php' else {**data.get('dependencies', {}), **data.get('peerDependencies', {})}
        for name, version in deps.items():
            if name.startswith(('platzhirsch/', '@platzhirsch/')):
                if name not in names or names[name]['version'] != version:
                    raise ValueError(f"{row['name']} requires {name} {version}; no matching package in composition")
    # A module's PHP and UI are released together; unrelated modules keep their versions.
    php = {Path(r['directory']).name: r for r in rows if r['kind'] == 'php'}
    npm = {Path(r['directory']).name: r for r in rows if r['kind'] == 'npm'}
    for code in php.keys() & npm.keys():
        if code != 'module-host' and php[code]['version'] != npm[code]['version']:
            raise ValueError(f'PHP/UI versions differ for {code}')
    return rows


def package_files(root, row, paths):
    directory = Path(row['directory'])
    manifest = 'composer.json' if row['kind'] == 'php' else 'package.json'
    allowed = ['src'] if row['kind'] == 'php' else row['manifest'].get('files', ['src'])
    if any(not isinstance(v, str) or v.startswith('/') or '..' in Path(v).parts or '*' in v for v in allowed):
        raise ValueError('Only explicit package directories/files are supported')
    files = []
    for path in paths:
        if not path.is_relative_to(directory):
            continue
        local = path.relative_to(directory)
        if str(local) not in (manifest, 'README.md', 'LICENSE') and not any(local == Path(a) or local.is_relative_to(Path(a)) for a in allowed):
            continue
        if local.suffix in ('.key', '.pfx', '.pem') or any(part.startswith('.') or part in ('node_modules', 'vendor', '__pycache__') for part in local.parts):
            raise ValueError(f'Hidden/runtime file in package: {path}')
        full = root / path
        if full.is_symlink() or not full.resolve().is_relative_to((root / directory).resolve()):
            raise ValueError(f'Symlink outside package policy: {path}')
        files.append((local.as_posix(), full.read_bytes()))
    if not any(name == manifest for name, _ in files):
        raise ValueError(f'Package manifest is not tracked: {directory}')
    return sorted(files)


def archive(row, files):
    result = io.BytesIO()
    if row['kind'] == 'php':
        with zipfile.ZipFile(result, 'w', compression=zipfile.ZIP_DEFLATED) as z:
            for name, data in files:
                info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, data)
    else:
        with gzip.GzipFile(fileobj=result, mode='wb', mtime=0, filename='') as gz:
            with tarfile.open(fileobj=gz, mode='w') as tar:
                for name, data in files:
                    info = tarfile.TarInfo('package/' + name)
                    info.size = len(data)
                    info.mode = 0o644
                    info.mtime = 0
                    tar.addfile(info, io.BytesIO(data))
    return result.getvalue()


def build(root, output, module=None):
    rows = catalog(root)
    paths = tracked(root)
    selected = rows if module is None else [r for r in rows if Path(r['directory']).name == module]
    if not selected:
        raise ValueError('Unknown module')
    # Prepare every archive before touching the destination.
    artifacts = []
    for row in selected:
        filename = row['name'].replace('@', '').replace('/', '-') + '-' + row['version'] + ('.zip' if row['kind'] == 'php' else '.tgz')
        data = archive(row, package_files(root, row, paths))
        artifacts.append((row, filename, data))
    output.mkdir(parents=True, exist_ok=False)
    records = []
    commit = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=root, check=True, capture_output=True, text=True).stdout.strip()
    for row, filename, data in artifacts:
        (output / filename).write_bytes(data)
        records.append({'name': row['name'], 'kind': row['kind'], 'version': row['version'], 'file': filename,
                        'sha256': hashlib.sha256(data).hexdigest(),
                        'requires': row['manifest'].get('require', row['manifest'].get('dependencies', {})),
                        'peers': row['manifest'].get('peerDependencies', {})})
    (output / 'modules.json').write_text(json.dumps({'sourceCommit': commit, 'packages': records}, indent=2) + '\n')
    (output / 'SHA256SUMS.txt').write_text(''.join(f"{r['sha256']}  {r['file']}\n" for r in records))
    return records


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--output', type=Path)
    parser.add_argument('--module', help='Package directory, e.g. reporting; default: all packages')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    try:
        if args.check:
            print(f'{len(catalog(args.root))} package versions and dependencies validated')
        else:
            if not args.output:
                parser.error('--output is required for a build')
            print(json.dumps(build(args.root, args.output, args.module), indent=2))
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        parser.exit(1, str(error) + '\n')
