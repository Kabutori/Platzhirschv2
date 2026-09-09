import type { ModuleUiManifest } from './types';
export function navigationFor(
  manifests: ModuleUiManifest[],
  installed: string[],
  permissions: string[],
  scope: 'administration' | 'restaurant',
) {
  const keys = new Set<string>();
  const codes = new Set<string>();
  for (const manifest of manifests) {
    if (codes.has(manifest.code)) throw new Error('Doppeltes UI-Modul: ' + manifest.code);
    codes.add(manifest.code);
    for (const entry of manifest.nav) {
      if (keys.has(entry.key)) throw new Error('Doppelte Modulnavigation: ' + entry.key);
      keys.add(entry.key);
    }
  }
  return manifests
    .filter((m) => installed.includes(m.code))
    .flatMap((m) => m.nav)
    .filter((n) => n.scope === scope && (permissions.includes('*') || permissions.includes(n.permission)))
    .sort((a, b) => a.order - b.order);
}
