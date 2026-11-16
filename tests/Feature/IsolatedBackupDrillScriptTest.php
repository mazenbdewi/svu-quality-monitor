<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IsolatedBackupDrillScriptTest extends TestCase
{
    public function test_successful_compose_exit_without_verification_marker_is_rejected(): void
    {
        $process = $this->runWithStubbedDocker('Container exited successfully');

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('did not produce an explicit successful verification result', $process->getErrorOutput());
    }

    public function test_verified_drill_marker_is_accepted(): void
    {
        $process = $this->runWithStubbedDocker('{"drill_passed": true}');

        $this->assertSame(0, $process->getExitCode());
    }

    public function test_failed_compose_exit_is_rejected_even_if_output_contains_marker(): void
    {
        $process = $this->runWithStubbedDocker('{"drill_passed": true}', 1);

        $this->assertSame(1, $process->getExitCode());
    }

    private function runWithStubbedDocker(string $output, int $upExitCode = 0): Process
    {
        $directory = sys_get_temp_dir().'/svu-drill-script-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $stub = $directory.'/docker';
        file_put_contents($stub, "#!/bin/sh\ncase \" \$* \" in\n  *' up '*) printf '%s\\n' \"\$SVU_DRILL_STUB_OUTPUT\"; exit \"\$SVU_DRILL_STUB_UP_EXIT\" ;;\nesac\nexit 0\n");
        chmod($stub, 0700);

        try {
            $process = new Process(['bash', base_path('scripts/verify-backup-isolated.sh')], base_path(), [
                'PATH' => $directory.':'.getenv('PATH'),
                'SVU_DRILL_STUB_OUTPUT' => $output,
                'SVU_DRILL_STUB_UP_EXIT' => (string) $upExitCode,
            ]);
            $process->run();

            return $process;
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
