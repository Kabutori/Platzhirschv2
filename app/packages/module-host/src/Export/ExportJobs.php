<?php
namespace App\Core\Export;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class ExportJobs
{
    public function __construct(private DatabaseManager $db) {}
    public function source(string $key): ExportSource
    {
        foreach (app()->tagged('platzhirsch.export_sources') as $source) {
            if ($source->key() === $key) {
                return $source;
            }
        }
        abort(404);
    }
    public function direct(Request $r, string $key)
    {
        $source = $this->source($key);
        $source->authorize($r->user());
        $d = $r->validate(['format' => 'required|in:csv,xlsx,pdf']);
        $columns = $source->columns();
        $limit = $d['format'] === 'pdf' ? 501 : 10001;
        $rows = $source
            ->query($r->user())
            ->select(array_keys($columns))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn($row) => array_values((array) $row))
            ->all();
        return (new TableExport())->response([array_values($columns), ...$rows], $d['format'], $key, $key);
    }
    public function start(Request $r, string $key): array
    {
        $source = $this->source($key);
        $source->authorize($r->user());
        $r->validate(['format' => 'required|in:csv']);
        // Explicitly use the durable database queue even when a development server uses sync.
        $id = $this->db->transaction(function () use ($r, $key) {
            // A stable owner lock serializes the per-owner quota across workers.
            $this->db->table('users')->where('id', $r->user()->id)->lockForUpdate()->first();
            abort_if(
                $this->db
                    ->table('platform_export_jobs')
                    ->where('user_id', $r->user()->id)
                    ->where('expires_at', '>', now())
                    ->count() >= 20,
                429,
                'Höchstens 20 Exporte pro Tag.',
            );
            $id = (string) Str::uuid();
            $this->db
                ->table('platform_export_jobs')
                ->insert([
                    'id' => $id,
                    'source' => $key,
                    'user_id' => $r->user()->id,
                    'tenant_id' => $r->user()->tenant_id,
                    'token_id' => $r->attributes->get('api.token')?->id,
                    'operation' => $r->attributes->get('api.operation'),
                    'status' => 'queued',
                    'expires_at' => now()->addDay(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            RunExport::dispatch($id)->onConnection('database')->afterCommit();
            return $id;
        });
        return ['id' => $id, 'status' => 'queued', 'format' => 'csv', 'expires_in' => 86400];
    }
    public function owned(Request $r, string $key, string $id): object
    {
        $job = $this->db
            ->table('platform_export_jobs')
            ->where('id', $id)
            ->where('source', $key)
            ->where('user_id', $r->user()->id)
            ->first();
        abort_unless($job, 404);
        $this->authorize($job, $r->user());
        return $job;
    }
    public function authorize(object $job, object $user): void
    {
        abort_unless($user->active && (string) $job->tenant_id === (string) $user->tenant_id, 403);
        abort_if($job->expires_at <= now()->toDateTimeString(), 410, 'Export ist abgelaufen.');
        $this->source($job->source)->authorize($user);
        if ($job->token_id) {
            $results = app('events')->dispatch('platzhirsch.export.authorize', [$job, $user]);
            abort_unless($results && !in_array(false, $results, true), 403);
        }
    }
    public function status(Request $r, string $key, string $id): array
    {
        $job = $this->owned($r, $key, $id);
        return [
            'id' => $job->id,
            'status' => $job->status,
            'rows' => $job->row_count,
            'expires_at' => $job->expires_at,
            'format' => 'csv',
        ];
    }
    public function path(string $id): string
    {
        if (!Str::isUuid($id)) {
            throw new \InvalidArgumentException('Invalid export ID');
        }
        return storage_path('app/private/exports/' . $id . '.csv');
    }
    public function download(Request $r, string $key, string $id)
    {
        $job = $this->owned($r, $key, $id);
        abort_unless(
            $job->status === 'completed' && is_file($this->path($id)),
            409,
            'Export ist noch nicht verfügbar.',
        );
        return response()->download($this->path($id), $key . '.csv', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
    public function cleanup(): void
    {
        $this->db
            ->table('platform_export_jobs')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunk(100, function ($rows) {
                foreach ($rows as $job) {
                    @unlink($this->path($job->id));
                    @unlink($this->path($job->id) . '.part');
                }
            });
        $this->db->table('platform_export_jobs')->where('expires_at', '<=', now()->subDays(7))->delete();
    }
}
