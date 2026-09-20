<?php
namespace App\Core\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Database\DatabaseManager;
class RunExport implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 600;
    public function __construct(public string $id) {}
    public function handle(ExportJobs $exports, DatabaseManager $db): void
    {
        $claimed = $db
            ->table('platform_export_jobs')
            ->where('id', $this->id)
            ->where('status', 'queued')
            ->update(['status' => 'running', 'updated_at' => now()]);
        if (!$claimed) {
            return;
        }
        $file = $exports->path($this->id);
        $out = null;
        try {
            $job = $db->table('platform_export_jobs')->find($this->id);
            $user = app('auth')->guard('web')->getProvider()->retrieveById($job->user_id);
            abort_unless($user, 403);
            $exports->authorize($job, $user);
            $source = $exports->source($job->source);
            $columns = $source->columns();
            if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true)) {
                throw new \RuntimeException('Export directory unavailable');
            }
            $out = fopen($file . '.part', 'x');
            if (!$out) {
                throw new \RuntimeException('Export unavailable');
            }
            chmod($file . '.part', 0600);
            if (fwrite($out, "\xEF\xBB\xBF") === false) {
                throw new \RuntimeException('Export write failed');
            }
            $write = function (array $row) use ($out) {
                $row = array_map(
                    fn($v) => is_string($v) && preg_match('/^[\s]*[=+@\-]|^[\t\r\n]/u', $v) ? "'" . $v : $v,
                    $row,
                );
                if (fputcsv($out, $row, ';', '"', '') === false) {
                    throw new \RuntimeException('Export write failed');
                }
            };
            $write(array_values($columns));
            $count = 0;
            $query = $source->query($user);
            $max = (clone $query)->max('id');
            foreach (
                $query
                    ->where('id', '<=', $max ?? 0)
                    ->select(array_keys($columns))
                    ->lazyById(1000)
                as $row
            ) {
                if (++$count > 1000000) {
                    throw new \RuntimeException('Export row limit exceeded');
                }
                $write(array_values((array) $row));
            }
            fclose($out);
            $out = null;
            // Re-read the owner before publishing: revoked permissions must not expose the file.
            $user = app('auth')->guard('web')->getProvider()->retrieveById($job->user_id);
            abort_unless($user, 403);
            $exports->authorize($job, $user);
            if (!rename($file . '.part', $file)) {
                throw new \RuntimeException('Export unavailable');
            }
            $db->table('platform_export_jobs')
                ->where('id', $this->id)
                ->update(['status' => 'completed', 'row_count' => $count, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($file . '.part');
            @unlink($file);
            $db->table('platform_export_jobs')
                ->where('id', $this->id)
                ->update(['status' => 'failed', 'updated_at' => now()]);
            throw new \RuntimeException('Export failed; check availability and current permissions.');
        }
    }
    public function failed(?\Throwable $exception): void
    {
        app(DatabaseManager::class)
            ->table('platform_export_jobs')
            ->where('id', $this->id)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'updated_at' => now()]);
        $file = app(ExportJobs::class)->path($this->id);
        @unlink($file . '.part');
    }
}
