<?php

namespace App\Services\BackupJob;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BackupJobService
{
    protected PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::timeout(30);
    }

    public function get(string $url): BackupJobObject
    {
        return new BackupJobObject(
            $this->http->acceptJson()
                       ->get($url)
        );
    }

    public function patch(string $url, array $data): BackupJobObject
    {
        return new BackupJobObject(
            $this->http->asJson()
                       ->patch($url, $data)
        );
    }
}
