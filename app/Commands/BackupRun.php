<?php

namespace App\Commands;

use App\Services\BackupJob\BackupJobObject;
use App\Services\BackupJob\BackupJobService;
use App\Utils\Dumper;
use Illuminate\Support\Collection;
use Throwable;
use LaravelZero\Framework\Commands\Command;

class BackupRun extends Command
{
    /**
     * The signature of the command.
     * @var string
     */
    protected $signature = 'backup:run {url}';
    /**
     * The description of the command.
     * @var string
     */
    protected $description = 'Run backup to external storage';
    private Dumper $dumper;
    private BackupJobObject $backupJob;

    public function handle(): int
    {
        $backupJobService = new BackupJobService;
        $this->backupJob  = $backupJobService->get($this->argument('url'));

        $start = microtime(true);

        try {
            $size = $this->databaseDumpPipeline();
        } catch (Throwable $ex) {
            $this->error('An error occurred while running the backup:');

            $errorMessage = implode(PHP_EOL, [$ex->getMessage(), ...$this->dumper->getErrors()]);

            $this->error($errorMessage);

            $backupJobService->patch(
                $this->backupJob->getPatchUrl(),
                [
                    'is_success' => false,
                    'error'      => $errorMessage,
                ]
            );

            return Command::FAILURE;
        }

        $allErrors    = $this->dumper->getErrors();
        $errorMessage = implode(PHP_EOL, $allErrors);

        if (empty($allErrors)) {
            $this->info('Backup complete');
        } else {
            $this->error('Backup complete with errors:');
            $this->error($errorMessage);
        }

        $this->info('Sending backup info to API');

        $end      = microtime(true);
        $duration = round($end - $start, 0);

        Collection::make(
            $backupJobService->patch(
                $this->backupJob->getPatchUrl(),
                [
                    'is_success' => empty($allErrors),
                    'error'      => $errorMessage,
                    'duration'   => $duration,
                    'size'       => $size,
                ]
            )->getBackupsToDelete()
        )->each(function(string $backupName) {
            $this->info("Deleting old backup {$backupName}");
            $this->dumper->deleteFromFilesystem($backupName);
        });

        return Command::SUCCESS;
    }

    private function databaseDumpPipeline(): int
    {
        $this->dumper = new Dumper($this->backupJob);

        $this->info("Dumping databases");
        $this->dumper->dumpDatabases();

        $this->info('Copying files to zip archive');
        $this->dumper->copyFilesToZipArchive();

        $this->info('Calculating zip archive size');
        $size = $this->dumper->getZipArchiveSize();

        $this->info('Uploading zip archive');
        $this->dumper->uploadZipArchive();

        $this->info('Deleting temporary directory');
        $this->dumper->deleteTemporaryDirectory();

        return $size;
    }
}
