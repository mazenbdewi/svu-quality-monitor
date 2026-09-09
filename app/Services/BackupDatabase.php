<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupDatabase
{
    public function metadata(): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('Backup requires MySQL.');
        }

        return ['engine' => 'mysql', 'version' => DB::selectOne('select version() as version')->version,
            'migrations' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all()];
    }

    public function estimatedBytes(): int
    {
        return (int) DB::selectOne('select coalesce(sum(data_length + index_length), 0) as bytes from information_schema.tables where table_schema = database()')->bytes;
    }

    public function dump(string $path): void
    {
        $nonTransactional = DB::selectOne("select count(*) as total from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' and engine <> 'InnoDB'")->total;
        if ($nonTransactional) {
            throw new RuntimeException('Backup requires InnoDB tables.');
        }
        $this->execute('mysqldump', ['--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--set-gtid-purged=OFF', '--hex-blob', '--routines', '--events', '--triggers', '--result-file='.$path]);
        chmod($path, 0600);
    }

    public function restore(string $path): void
    {
        $input = fopen($path, 'rb');
        try {
            $this->execute('mysql', ['--binary-mode', '--local-infile=0'], $input);
        } finally {
            fclose($input);
        }
    }

    private function execute(string $tool, array $arguments, mixed $input = null): void
    {
        $connection = DB::connection()->getConfig();
        $credentials = tempnam(sys_get_temp_dir(), 'svu-mysql-');
        chmod($credentials, 0600);
        $quote = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value).'"';
        try {
            file_put_contents($credentials, "[client]\nuser=".$quote($connection['username'])."\npassword=".$quote($connection['password'])."\nhost=".$quote($connection['host'])."\nport=".(int) $connection['port']."\n");
            $process = new Process([config('backup.'.$tool), '--defaults-extra-file='.$credentials, ...$arguments, $connection['database']], null, ['MYSQL_PWD' => false], $input, config('backup.timeout'));
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('MySQL backup tool failed; inspect connectivity and privileges.');
            }
        } catch (\Throwable) {
            // Process exceptions can contain commands/output: never propagate them.
            throw new RuntimeException('MySQL backup tool failed; inspect connectivity and privileges.');
        } finally {
            unlink($credentials);
        }
    }
}
