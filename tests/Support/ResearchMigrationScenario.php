<?php

namespace Tests\Support;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResearchMigrationScenario
{
    public static function run(): array
    {
        if (Schema::hasTable('users')) {
            throw new \RuntimeException('Upgrade scenario requires an EMPTY isolated database.');
        }
        $all = glob(database_path('migrations/*.php'));
        sort($all);
        $new = array_values(array_filter($all, fn ($p) => preg_match('/2026_09_(16|17|18|19)_000001_/', basename($p))));
        $old = array_values(array_diff($all, $new));
        self::command('migrate', ['--path' => $old, '--realpath' => true, '--force' => true]);
        app(RolesAndPermissionsSeeder::class)->run();
        $uid = DB::table('users')->insertGetId(['name' => 'Upgrade sentinel', 'email' => 'upgrade@example.invalid', 'password' => 'not-a-login-password', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        DB::table('model_has_roles')->insert(['role_id' => DB::table('roles')->where('name', 'viewer')->value('id'), 'model_type' => User::class, 'model_id' => $uid]);
        $sid = DB::table('monitored_services')->insertGetId(['name' => 'Legacy sentinel', 'url' => 'https://example.invalid', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        DB::table('service_checks')->insert(['monitored_service_id' => $sid, 'checked_at' => '2026-09-01 01:00:00', 'source' => 'automatic', 'is_success' => true, 'is_slow' => false, 'response_time_ms' => 123]);
        DB::table('service_incidents')->insert(['monitored_service_id' => $sid, 'started_at' => '2026-09-01 02:00:00', 'confirmed_at' => '2026-09-01 02:01:00', 'status' => 'open', 'incident_type' => 'availability', 'severity' => 'critical']);
        $period = ['monitored_service_id' => $sid, 'period_start' => '2026-09-01 00:00:00', 'period_end' => '2026-09-02 00:00:00'];
        DB::table('reliability_metrics')->insert($period + ['availability_percent' => 98.7654]);
        DB::table('sla_metrics')->insert($period + ['target_percent' => 99.9, 'status' => 'met', 'calculated_at' => '2026-09-02 00:00:00']);
        $cid = DB::table('control_charts')->insertGetId($period + ['chart_type' => 'i_chart', 'metric_name' => 'response_time_ms', 'center_line' => 123]);
        DB::table('control_chart_points')->insert(['control_chart_id' => $cid, 'point_time' => '2026-09-01 01:00:00', 'value' => 123, 'is_out_of_control' => false]);
        $tables = ['users', 'roles', 'permissions', 'model_has_roles', 'role_has_permissions', 'monitored_services', 'service_checks', 'service_incidents', 'reliability_metrics', 'sla_metrics', 'control_charts', 'control_chart_points'];
        $before = [];
        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);
            $before[$table] = ['columns' => $columns, 'rows' => DB::table($table)->orderBy($columns[0])->get($columns)->toArray()];
        }
        self::command('migrate', ['--path' => $new, '--realpath' => true, '--force' => true]);
        self::preserved($before);
        if (! Schema::hasTable('spc_signals') || ! Schema::hasColumn('reliability_metrics', 'measurement_context')) {
            throw new \RuntimeException('Upgrade schema incomplete');
        }
        self::command('migrate:rollback', ['--step' => count($new), '--force' => true]);
        self::preserved($before);
        if (Schema::hasTable('spc_signals')) {
            throw new \RuntimeException('Rollback schema incomplete');
        }
        self::command('migrate', ['--path' => $new, '--realpath' => true, '--force' => true]);
        self::preserved($before);

        return ['legacy_migrations' => count($old), 'research_migrations' => count($new), 'preserved_tables' => array_map(fn ($v) => count($v['rows']), $before), 'upgrade' => 'passed', 'rollback' => 'passed', 'reapply' => 'passed'];
    }

    private static function preserved(array $before): void
    {
        foreach ($before as $table => $snapshot) {
            if (json_encode($snapshot['rows']) !== json_encode(DB::table($table)->orderBy($snapshot['columns'][0])->get($snapshot['columns'])->toArray())) {
                throw new \RuntimeException('Legacy data changed: '.$table);
            }
        }
    }

    private static function command(string $name, array $args): void
    {
        if (Artisan::call($name, $args) !== 0) {
            throw new \RuntimeException(Artisan::output());
        }
    }
}
