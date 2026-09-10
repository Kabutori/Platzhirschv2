<?php
namespace App\Modules\Support\Http;
use Illuminate\Http\Request;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\Rule;
use App\Contracts\Module\AuditSink;
class ReleaseController
{
    public function __construct(private DatabaseManager $db, private AuditSink $audit) {}
    public function index(Request $r)
    {
        abort_unless($r->user()->active, 403);
        return $this->db
            ->table('support_releases')
            ->when($r->user()->role !== 'system_admin', fn($q) => $q->whereNotNull('published_at'))
            ->latest('id')
            ->paginate(50);
    }
    public function save(Request $r, ?int $id = null)
    {
        abort_unless($r->user()->active && $r->user()->role === 'system_admin', 403);
        $v = $r->validate([
            'module' => ['required', 'string', 'max:80'],
            'version' => ['required', 'string', 'max:80'],
            'category' => ['required', Rule::in(['feature', 'improvement', 'fix', 'security'])],
            'notes' => ['required', 'string', 'max:10000'],
            'git_url' => ['nullable', 'url:https', 'max:500'],
            'publish' => ['required', 'boolean'],
            'revision' => [$id ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);
        return $this->db->transaction(function () use ($r, $id, $v) {
            $old = $id
                ? $this->db->table('support_releases')->where('id', $id)->lockForUpdate()->first()
                : null;
            if ($id) {
                abort_unless($old, 404);
                abort_unless(
                    $old->revision === $v['revision'],
                    409,
                    'Versionshinweis wurde inzwischen geändert.',
                );
            }
            $values = array_intersect_key(
                $v,
                array_flip(['module', 'version', 'category', 'notes', 'git_url']),
            );
            $values['published_at'] = $v['publish'] ? $old?->published_at ?? now() : null;
            $values['revision'] = ($old?->revision ?? 0) + 1;
            $values['updated_at'] = now();
            if ($id) {
                $this->db->table('support_releases')->where('id', $id)->update($values);
            } else {
                $id = $this->db->table('support_releases')->insertGetId([...$values, 'created_at' => now()]);
            }
            $this->audit->record('support.release_saved', 'release:' . $id);
            return $this->db->table('support_releases')->where('id', $id)->first();
        });
    }
}
