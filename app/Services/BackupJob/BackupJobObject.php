<?php

namespace App\Services\BackupJob;


use Illuminate\Http\Client\Response;

class BackupJobObject
{
    private object $backupJob;


    public function __construct(Response $response)
    {
        $this->backupJob = $response->object();
    }

    public function getName(): string
    {
        return $this->backupJob->name ?? '';
    }

    public function getPatchUrl(): string
    {
        return $this->backupJob->patch_url ?? '';
    }

    public function getDiskConfig(): array
    {
        return (array) $this->backupJob->disk_config;
    }

    public function getDatabaseType(): string
    {
        return $this->backupJob->database_type ?? '';
    }

    public function getDatabasePassword(): string
    {
        return $this->backupJob->database_password ?? '';
    }

    public function getExcludeFiles(): array
    {
        return (array) $this->backupJob->exclude_files;
    }

    public function getIncludeFiles(): array
    {
        return (array) $this->backupJob->include_files;
    }

    public function getBackupsToDelete(): array
    {
        return (array) $this->backupJob->backups_to_delete;
    }

    public function getDatabases(): array
    {
        return (array) $this->backupJob->databases;
    }
}
