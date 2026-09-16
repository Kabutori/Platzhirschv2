<?php
namespace App\ModuleUpdates;
use Illuminate\Support\Facades\{DB, File};
class Registry
{
    public function seedPath(): string
    {
        return config('module_updates.seed', resource_path('module-registry'));
    }
    public function seed(): array
    {
        $file = $this->seedPath() . '/index.json';
        return is_file($file)
            ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
            : ['repositories' => []];
    }
    public function catalog(): array
    {
        $rows = $this->seed()['repositories'];
        foreach (DB::table('platform_module_releases')->get() as $r) {
            $rows[$r->repository]['versions'][$r->version] = json_decode(
                $r->metadata,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }
        return $rows;
    }
    public function preview(array $selection): array
    {
        $catalog = $this->catalog();
        $packages = [];
        $chosen = [];
        abort_unless(
            count($catalog) > 0 && count($selection) === count($catalog),
            422,
            'Bitte alle Repository-Versionen auswählen.',
        );
        foreach ($catalog as $repo => $row) {
            $v = $selection[$repo] ?? '';
            $release = $row['versions'][$v] ?? null;
            abort_unless($release, 422, 'Unbekannte Version: ' . $repo);
            $chosen[$repo] = ['version' => $v, 'commit' => $release['commit']];
            foreach ($release['packages'] as $p) {
                abort_if(isset($packages[$p['name']]), 422, 'Doppelter Paketname.');
                $packages[$p['name']] = $p;
            }
        }
        $errors = [];
        foreach ($packages as $p) {
            $m = $p['manifest'];
            $deps =
                $p['kind'] === 'php'
                    ? $m['require'] ?? []
                    : array_merge($m['dependencies'] ?? [], $m['peerDependencies'] ?? []);
            foreach ($deps as $name => $v) {
                if (str_starts_with($name, 'platzhirsch/') || str_starts_with($name, '@platzhirsch/')) {
                    if (($packages[$name]['version'] ?? null) !== $v) {
                        $errors[] = $p['name'] . ' benötigt ' . $name . ' ' . $v;
                    }
                }
            }
        }
        return [
            'compatible' => !$errors,
            'errors' => $errors,
            'selection' => $chosen,
            'packages' => array_values($packages),
        ];
    }
    public function sync(string $repo, Settings $settings): array
    {
        abort_unless(isset($this->seed()['repositories'][$repo]), 422, 'Unbekanntes Repository.');
        $releases = $settings->github('Kabutori/' . $repo . '/releases?per_page=30');
        $added = 0;
        foreach ($releases as $release) {
            if (
                $release['draft'] ||
                $release['prerelease'] ||
                !preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $release['tag_name'])
            ) {
                continue;
            }
            $v = substr($release['tag_name'], 1);
            if (isset($this->catalog()[$repo]['versions'][$v])) {
                continue;
            }
            $assets = array_column($release['assets'], null, 'name');
            abort_unless(isset($assets['packages.json']), 422, 'Release enthält kein packages.json.');
            $meta = json_decode(
                $settings->asset($repo, $assets['packages.json']),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $commit = $meta['sourceCommit'] ?? '';
            abort_unless(preg_match('/^[a-f0-9]{40}$/D', $commit), 422, 'Paketcommit fehlt.');
            $baseline = $this->seed()['repositories'][$repo];
            $expected = $baseline['versions'][$baseline['installed']]['packages'];
            $records = [];
            abort_unless(
                count($meta['packages'] ?? []) === count($expected),
                422,
                'Unvollständiger Modulrelease.',
            );
            foreach ($expected as $p) {
                $record = collect($meta['packages'])->firstWhere('name', $p['name']);
                abort_unless(
                    $record &&
                        $record['version'] === $v &&
                        preg_match('/^[a-f0-9]{64}$/D', $record['sha256'] ?? '') &&
                        isset($assets[$record['file'] ?? '']),
                    422,
                    'Ungültige Paketmetadaten.',
                );
                $source = $p['source'] === '.' ? '' : $p['source'] . '/';
                $manifest = $settings->github(
                    'Kabutori/' .
                        $repo .
                        '/contents/' .
                        $source .
                        ($p['kind'] === 'php' ? 'composer.json' : 'package.json') .
                        '?ref=' .
                        $commit,
                );
                $manifest = json_decode(base64_decode($manifest['content']), true, 512, JSON_THROW_ON_ERROR);
                abort_unless(
                    $manifest['name'] === $p['name'] && $manifest['version'] === $v,
                    422,
                    'Paketmanifest passt nicht zum Release.',
                );
                $body = $settings->asset($repo, $assets[$record['file']]);
                abort_unless(
                    hash_equals($record['sha256'], hash('sha256', $body)),
                    422,
                    'Paketprüfsumme stimmt nicht.',
                );
                $dir = storage_path('app/private/module-registry');
                File::ensureDirectoryExists($dir);
                $path = $dir . '/' . $record['sha256'];
                if (!is_file($path)) {
                    File::put($path, $body);
                }
                $records[] = [
                    ...$p,
                    'version' => $v,
                    'manifest' => $manifest,
                    'sha256' => $record['sha256'],
                    'sha1' => sha1($body),
                    'file' => $record['file'],
                ];
            }
            DB::table('platform_module_releases')->insert([
                'repository' => $repo,
                'version' => $v,
                'metadata' => json_encode(['commit' => $commit, 'packages' => $records], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $added++;
        }
        return ['added' => $added];
    }
    public function file(string $hash): string
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $hash), 404);
        foreach ($this->catalog() as $r) {
            foreach ($r['versions'] as $v) {
                foreach ($v['packages'] as $p) {
                    if ($p['sha256'] === $hash) {
                        $file = storage_path('app/private/module-registry/' . $hash);
                        if (!is_file($file)) {
                            $file = $this->seedPath() . '/' . $p['file'];
                        }
                        abort_unless(is_file($file) && hash_equals($hash, hash_file('sha256', $file)), 404);
                        return $file;
                    }
                };
            };
        }
        abort(404);
    }
    public function metadata(string $base, ?string $npm = null): array
    {
        $packages = [];
        foreach ($this->catalog() as $r) {
            foreach ($r['versions'] as $v) {
                foreach ($v['packages'] as $p) {
                    if ($npm === null && $p['kind'] === 'php') {
                        $packages[$p['name']][$p['version']] = [
                            ...$p['manifest'],
                            'dist' => [
                                'type' => 'zip',
                                'url' => $base . '/files/' . $p['sha256'],
                                'shasum' => $p['sha1'],
                            ],
                        ];
                    }
                    if ($npm === $p['name'] && $p['kind'] === 'npm') {
                        $packages[$p['version']] = [
                            ...$p['manifest'],
                            'dist' => ['tarball' => $base . '/files/' . $p['sha256'], 'shasum' => $p['sha1']],
                        ];
                    }
                };
            };
        }
        if ($npm === null) {
            return ['packages' => (object) $packages];
        }
        abort_unless($packages, 404);
        $versions = array_keys($packages);
        usort($versions, 'version_compare');
        return ['name' => $npm, 'versions' => $packages, 'dist-tags' => ['latest' => end($versions)]];
    }
}
