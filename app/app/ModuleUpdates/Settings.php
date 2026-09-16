<?php
namespace App\ModuleUpdates;
use Illuminate\Support\Facades\{Crypt, DB, Http};
class Settings
{
    public const REPOSITORY = 'Kabutori/Platzhirschv2';
    public function read(): array
    {
        $raw = DB::table('platform_module_settings')->where('id', 1)->value('encrypted');
        return $raw
            ? json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR)
            : ['github_token' => '', 'reader_hash' => ''];
    }
    public function save(array $value): void
    {
        DB::table('platform_module_settings')->updateOrInsert(
            ['id' => 1],
            ['encrypted' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR))],
        );
    }
    public function github(string $path, string $method = 'GET', array $body = []): mixed
    {
        $token = $this->read()['github_token'];
        abort_unless($token, 422, 'Bitte zuerst den GitHub-Zugang speichern.');
        $r = Http::withToken($token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(25)
            ->connectTimeout(8)
            ->withoutRedirecting()
            ->send(
                $method,
                'https://api.github.com/repos/' . $path,
                $method === 'GET' ? [] : ['json' => $body],
            );
        abort_unless(
            $r->successful(),
            502,
            'GitHub-Anfrage fehlgeschlagen (HTTP ' .
                $r->status() .
                '). Repository-Zugriff und Actions-Berechtigung prüfen.',
        );
        return $r->json();
    }
    public function asset(string $repository, array $asset): string
    {
        abort_unless(
            ($asset['size'] ?? PHP_INT_MAX) <= 20 * 1024 * 1024 && is_int($asset['id'] ?? null),
            422,
            'Paketdatei ungültig oder zu groß.',
        );
        $r = Http::withToken($this->read()['github_token'])
            ->withHeaders(['Accept' => 'application/octet-stream'])
            ->withoutRedirecting()
            ->timeout(60)
            ->get(
                'https://api.github.com/repos/Kabutori/' . $repository . '/releases/assets/' . $asset['id'],
            );
        if ($r->redirect()) {
            $url = $r->header('Location');
            $host = parse_url($url, PHP_URL_HOST);
            abort_unless(
                parse_url($url, PHP_URL_SCHEME) === 'https' &&
                    in_array(
                        $host,
                        ['release-assets.githubusercontent.com', 'objects.githubusercontent.com'],
                        true,
                    ),
                502,
                'Unerwartetes Downloadziel.',
            );
            // Never forward a GitHub token to the storage host.
            $r = Http::withoutRedirecting()->timeout(60)->get($url);
        }
        abort_unless(
            $r->successful() && strlen($r->body()) <= 20 * 1024 * 1024,
            502,
            'Paketdownload fehlgeschlagen.',
        );
        return $r->body();
    }
}
