<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Core\Export\{RunExport, ExportJobs};
class BackgroundExportTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create([
            'name' => 'Export Owner',
            'email' => 'export@example.test',
            'password' => 'test-password',
            'role' => 'system_admin',
        ])->fresh();
        $this->actingAs($this->owner);
    }
    private function start(): string
    {
        return $this->postJson('/api/v1/admin/customer-exports', ['format' => 'csv'])
            ->assertStatus(202)
            ->json('id');
    }
    private function executeExport(string $id): void
    {
        (new RunExport($id))->handle(
            app(ExportJobs::class),
            app(\Illuminate\Database\DatabaseManager::class),
        );
    }
    public function test_background_export_is_durable_private_owner_scoped_and_expires(): void
    {
        $id = $this->start();
        $this->assertDatabaseCount('jobs', 1);
        $this->getJson('/api/v1/admin/customer-exports/' . $id . '/download')->assertConflict();
        $this->executeExport($id);
        $this->getJson('/api/v1/admin/customer-exports/' . $id)
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->get('/api/v1/admin/customer-exports/' . $id . '/download')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=customers.csv');
        $other = User::create([
            'name' => 'Other',
            'email' => 'export-other@example.test',
            'password' => 'test-password',
            'role' => 'system_admin',
        ])->fresh();
        $this->actingAs($other)
            ->getJson('/api/v1/admin/customer-exports/' . $id)
            ->assertNotFound();
        $this->actingAs($this->owner);
        $this->travel(25)->hours();
        $this->getJson('/api/v1/admin/customer-exports/' . $id . '/download')->assertGone();
        app(ExportJobs::class)->cleanup();
        $this->assertFileDoesNotExist(app(ExportJobs::class)->path($id));
    }
    public function test_permission_revocation_prevents_execution_and_no_file_is_published(): void
    {
        $id = $this->start();
        DB::table('users')
            ->where('id', $this->owner->id)
            ->update(['active' => false]);
        try {
            $this->executeExport($id);
            $this->fail('Revoked export ran');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Export failed', $e->getMessage());
        }
        $this->assertDatabaseHas('platform_export_jobs', ['id' => $id, 'status' => 'failed']);
        $this->assertFileDoesNotExist(app(ExportJobs::class)->path($id));
    }
    public function test_direct_server_export_contains_only_whitelisted_columns(): void
    {
        DB::table('prov_db_servers')->insert([
            'name' => '=danger',
            'host' => 'db.example.test',
            'port' => 3306,
            'region' => 'EU',
            'purpose' => 'test',
            'database' => 'internal_database',
            'username' => 'hidden_user',
            'password' => 'encrypted_password',
            'tls_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $body = $this->get('/api/v1/admin/server-exports?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString("'=danger", $body);
        $this->assertStringNotContainsString('hidden_user', $body);
        $this->assertStringNotContainsString('encrypted_password', $body);
        $this->assertStringNotContainsString('internal_database', $body);
    }
}
