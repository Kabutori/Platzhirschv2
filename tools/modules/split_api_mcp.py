"""Prepare API/MCP repositories without changing the active application pins."""
import argparse
import copy
import hashlib
import json
from pathlib import Path
import sources

ROOT = Path(__file__).resolve().parents[2]
GROUPS = {
    'platzhirsch-module-api': {'platzhirsch/api': '.'},
    'platzhirsch-module-mcp': {'platzhirsch/mcp': '.', '@platzhirsch/mcp': 'node'},
}


def prepare(root, output):
    sources.verify(root)
    lock = json.loads((root / 'modules.lock.json').read_text())
    host = next(r for r in lock['repositories'] if r['repository'] == 'Kabutori/platzhirsch-module-host')
    packages = {p['name']: p for p in host['packages']}
    expected = {name for group in GROUPS.values() for name in group}
    if not expected <= packages.keys():
        raise ValueError('Expected API/MCP packages are not owned by module-host')
    output.mkdir(parents=True, exist_ok=False)
    for repo, mapping in GROUPS.items():
        directory = output / repo
        metadata = []
        for name, source in mapping.items():
            package = copy.deepcopy(packages[name])
            package['source'] = source
            metadata.append({k: v for k, v in package.items() if k != 'files'})
            for relative, expected_hash in package['files'].items():
                data = (root / package['target'] / relative).read_bytes().replace(b'\r\n', b'\n')
                if hashlib.sha256(data).hexdigest() != expected_hash:
                    raise ValueError('Source changed while preparing split')
                destination = directory / source / relative
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(data)
        (directory / 'module.json').write_text(json.dumps({
            'schema': 1, 'repository': 'Kabutori/' + repo, 'packages': metadata,
        }, indent=2) + '\n')
        for template, target in [('package.py', 'tools/package.py'), ('workflow.yml', '.github/workflows/module.yml')]:
            destination = directory / target
            destination.parent.mkdir(parents=True, exist_ok=True)
            data = (Path(__file__).parent / 'templates' / template).read_text()
            if repo.endswith('-mcp') and template == 'workflow.yml':
                data = data.replace('      - run: python tools/package.py',
                    "      - uses: actions/setup-node@v4\n        with:\n          node-version: '22'\n"
                    '      - run: node --test node/src/server.test.mjs\n      - run: python tools/package.py')
            destination.write_text(data)
        (directory / '.gitignore').write_text('/dist/\n/vendor/\n/node/node_modules/\n')
        (directory / 'README.md').write_text(
            '# ' + repo + '\n\nExtracted from Kabutori/platzhirsch-module-host at ' + host['commit'] +
            '. Package contents and versions are unchanged.\n\n'
            'Run `python tools/package.py` in a committed checkout to build versioned archives. '
            'Tags `v<package-version>` publish checked release assets. '
            'Application integration tests run in Kabutori/Platzhirschv2.\n')
    return list(GROUPS)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    print(json.dumps(prepare(ROOT, args.output), indent=2))
