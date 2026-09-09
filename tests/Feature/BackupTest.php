<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemOperationsPage;
use App\Models\User;
use App\Services\BackupDatabase;
use App\Services\BackupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/svu-backup-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->sandbox.'/public');
        File::ensureDirectoryExists($this->sandbox.'/private');
        file_put_contents($this->sandbox.'/public/logo.txt', 'original');
        file_put_contents($this->sandbox.'/.env', 'PASSWORD=never-in-metadata');
        config(['backup.directory' => $this->sandbox.'/backups', 'backup.minimum_free_bytes' => 0,
            'backup.files' => ['public' => $this->sandbox.'/public', 'private' => $this->sandbox.'/private']]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $db = Mockery::mock(BackupDatabase::class);
        $db->shouldReceive('metadata')->andReturn(['engine' => 'mysql', 'version' => '8.4.0', 'migrations' => ['fixture']]);
        $db->shouldReceive('estimatedBytes')->andReturn(100);
        $db->shouldReceive('dump')->andReturnUsing(fn ($path) => file_put_contents($path, "-- MySQL dump\nCREATE TABLE fixture (id INT);\n-- Dump completed on test\n"));
        $db->shouldReceive('restore')->andReturnNull();
        $this->app->instance(BackupDatabase::class, $db);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    public function test_full_bundle_has_verified_database_files_metadata_and_manifest_but_no_env(): void
    {
        $service = app(BackupService::class);
        $result = $service->create(null, 'scheduled');
        $this->assertTrue($result['verified']);
        $path = $result['path'];
        foreach (['database.sql', 'metadata.json', 'manifest.json', 'files/public/logo.txt'] as $file) {
            $this->assertFileExists($path.'/'.$file);
        }
        $this->assertFileDoesNotExist($path.'/.env');
        $this->assertFileDoesNotExist($path.'/files/.env');
        $this->assertStringNotContainsString('never-in-metadata', file_get_contents($path.'/metadata.json'));
        $this->assertSame($result['size'], $service->verify($path)['size']);
        $this->assertSame('healthy', $service->health()['status']);
        $this->assertSame('scheduled', $service->health()['success']['type']);
        $this->assertDatabaseCount('audit_logs', 0);
        $manifest = json_decode(file_get_contents($path.'/manifest.json'), true);
        $this->assertSame(hash_file('sha256', $path.'/database.sql'), $manifest['database.sql']['sha256']);
        $this->assertSame(0600, fileperms($path.'/manifest.json') & 0777);
    }

    public function test_corrupted_backup_is_rejected_before_database_restore_and_is_audited(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('super_admin');
        $service = app(BackupService::class);
        $path = $service->create($actor)['path'];
        file_put_contents($path.'/database.sql', 'corrupt');
        try {
            $service->restore($path, $actor);
            $this->fail('Corrupted backup accepted');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('audit_logs', ['event' => 'backup.verification_failed', 'actor_id' => $actor->id]);
        }
        $this->assertSame('original', file_get_contents($this->sandbox.'/public/logo.txt'));
        $this->assertCount(1, File::directories($this->sandbox.'/backups'));
    }

    public function test_retention_preserves_newest_unknown_and_recovery_bundles(): void
    {
        config(['backup.keep_last' => 1]);
        $service = app(BackupService::class);
        $first = $service->create(null, 'scheduled')['path'];
        $this->travel(1)->seconds();
        $last = $service->create(null, 'scheduled')['path'];
        file_put_contents($this->sandbox.'/backups/unknown.txt', 'keep');
        $this->assertCount(1, $service->cleanup());
        $this->assertDirectoryDoesNotExist($first);
        $this->assertDirectoryExists($last);
        $this->assertFileExists($this->sandbox.'/backups/unknown.txt');
    }

    public function test_manual_permissions_and_restore_force_guards(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $this->artisan('backup:create', ['--actor-email' => $viewer->email])->assertFailed();
        $this->artisan('backup:create', ['--actor-email' => $admin->email])->assertSuccessful();
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup.created', 'actor_id' => $admin->id]);
        $this->artisan('backup:restore', ['path' => 'missing'])->assertFailed();
        $this->assertFalse($admin->can('backups.restore'));
        $this->assertFalse($viewer->can('backups.view'));
        $this->assertTrue($admin->can('backups.view'));
    }

    public function test_successful_restore_creates_checkpoint_restores_files_and_stays_offline(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('super_admin');
        $service = app(BackupService::class);
        $path = $service->create($actor)['path'];
        file_put_contents($this->sandbox.'/public/logo.txt', 'modified');
        file_put_contents($this->sandbox.'/public/new.txt', 'remove');
        Artisan::shouldReceive('call')->with('down', ['--retry' => 60])->once()->andReturn(0);
        Artisan::shouldReceive('call')->with('optimize:clear')->once()->andReturn(0);
        $service->restore($path, $actor);
        $this->assertSame('original', file_get_contents($this->sandbox.'/public/logo.txt'));
        $this->assertFileDoesNotExist($this->sandbox.'/public/new.txt');
        $records = collect(File::files($this->sandbox.'/backups/.runs'))->map(fn ($f) => json_decode(file_get_contents($f), true));
        $this->assertCount(1, $records->where('type', 'pre-restore')->where('status', 'success'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup.restore_completed']);
    }

    public function test_symlinks_and_unexpected_files_fail_verification(): void
    {
        $service = app(BackupService::class);
        $path = $service->create()['path'];
        symlink($this->sandbox.'/.env', $path.'/files/private/secret');
        $this->expectException(\RuntimeException::class);
        $service->verify($path);
    }

    public function test_failed_backup_is_visible_without_exception_secrets(): void
    {
        $db = Mockery::mock(BackupDatabase::class);
        $db->shouldReceive('metadata')->andThrow(new \RuntimeException('PASSWORD=secret-token'));
        $service = new BackupService($db);
        try {
            $service->create(null, 'scheduled');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('secret-token', $e->getMessage());
        }
        $this->assertSame('down', $service->health()['status']);
        $this->assertStringNotContainsString('secret-token', json_encode($service->health()));
    }

    public function test_failed_database_restore_never_reopens_application(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('super_admin');
        $path = app(BackupService::class)->create($actor)['path'];
        $db = Mockery::mock(BackupDatabase::class);
        $db->shouldReceive('metadata')->andReturn(['engine' => 'mysql', 'version' => '8.4.0', 'migrations' => ['fixture']]);
        $db->shouldReceive('estimatedBytes')->andReturn(100);
        $db->shouldReceive('dump')->andReturnUsing(fn ($p) => file_put_contents($p, "-- MySQL dump\nCREATE TABLE fixture (id INT);\n"));
        $db->shouldReceive('restore')->once()->andThrow(new \RuntimeException('unsafe internal details'));
        Artisan::shouldReceive('call')->with('down', ['--retry' => 60])->once()->andReturn(0);
        Artisan::shouldNotReceive('call')->with('up');
        try {
            (new BackupService($db))->restore($path, $actor);
            $this->fail('Restore should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maintenance mode retained', $e->getMessage());
            $this->assertStringNotContainsString('unsafe', $e->getMessage());
        }
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup.restore_failed']);
    }

    public function test_failed_pre_restore_backup_never_touches_database_or_enables_maintenance(): void
    {
        $path = app(BackupService::class)->create()['path'];
        $db = Mockery::mock(BackupDatabase::class);
        $db->shouldReceive('metadata')->andReturn(['engine' => 'mysql', 'version' => '8.4.0', 'migrations' => ['fixture']]);
        $db->shouldReceive('estimatedBytes')->andReturn(100);
        $db->shouldReceive('dump')->andThrow(new \RuntimeException('backup failure'));
        $db->shouldNotReceive('restore');
        Artisan::shouldReceive('call')->never();
        $this->expectException(\RuntimeException::class);
        (new BackupService($db))->restore($path);
    }

    public function test_backup_age_status_transitions_use_configured_thresholds(): void
    {
        $service = app(BackupService::class);
        $service->create();
        config(['backup.warning_hours' => 2, 'backup.down_hours' => 4]);
        $this->travel(3)->hours();
        $this->assertSame('warning', $service->health()['status']);
        $this->travel(2)->hours();
        $this->assertSame('down', $service->health()['status']);
    }

    public function test_unsafe_restore_target_is_rejected_before_import(): void
    {
        $path = app(BackupService::class)->create()['path'];
        config(['backup.files.public' => $this->sandbox.'/invalid-target']);
        $db = Mockery::mock(BackupDatabase::class);
        $db->shouldReceive('metadata')->andReturn(['engine' => 'mysql', 'version' => '8.4.0', 'migrations' => ['fixture']]);
        $db->shouldNotReceive('restore');
        $db->shouldNotReceive('dump');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsafe files restore destination');
        (new BackupService($db))->restore($path);
    }

    public function test_backup_role_seed_is_idempotent(): void
    {
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('permissions', 32);
        $this->assertDatabaseCount('role_has_permissions', 84);
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public function test_operations_backup_section_is_visible_only_with_backup_permission(): void
    {
        foreach (['administrator' => true, 'operator' => false, 'viewer' => false] as $role => $visible) {
            $actor = User::factory()->create();
            $actor->assignRole($role);
            // Viewer normally cannot open operations; grant just that permission to isolate the backup section.
            $actor->givePermissionTo('operations.view');
            $response = $this->actingAs($actor)->get(SystemOperationsPage::getUrl())->assertOk();
            if ($visible) {
                $response->assertSee(__('backup.last_success'));
            } else {
                $response->assertDontSee(__('backup.last_success'));
            }
        }
    }

    public function test_missing_manifest_and_unlisted_files_are_rejected(): void
    {
        $service = app(BackupService::class);
        $path = $service->create()['path'];
        file_put_contents($path.'/extra.txt', 'unexpected');
        $this->artisan('backup:verify', ['path' => $path])->assertFailed();
        unlink($path.'/extra.txt');
        unlink($path.'/manifest.json');
        $this->artisan('backup:verify', ['path' => $path])->assertFailed();
    }
}
