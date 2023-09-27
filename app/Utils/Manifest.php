<?php

namespace App\Utils;

use SplFileObject;

/**
 * From spatie/laravel-backup @link https://github.com/spatie/laravel-backup/blob/main/src/Tasks/Backup/Manifest.php
 */
class Manifest
{
    protected string $manifestPath;

    public static function create(string $manifestPath): self
    {
        return new static($manifestPath);
    }

    public function __construct(string $manifestPath)
    {
        $this->manifestPath = $manifestPath;

        touch($manifestPath);
    }

    public function path(): string
    {
        return $this->manifestPath;
    }

    public function addFiles($filePaths): self
    {
        if (is_string($filePaths)) {
            $filePaths = [$filePaths];
        }

        foreach ($filePaths as $filePath) {
            if (! empty($filePath)) {
                file_put_contents($this->manifestPath, $filePath.PHP_EOL, FILE_APPEND);
            }
        }

        return $this;
    }

    public function files()
    {
        $file = new SplFileObject($this->path());

        while (! $file->eof()) {
            $filePath = $file->fgets();

            if (! empty($filePath)) {
                yield trim($filePath);
            }
        }
    }

    public function count(): int
    {
        $file = new SplFileObject($this->manifestPath, 'r');

        $file->seek(PHP_INT_MAX);

        return $file->key();
    }
}
