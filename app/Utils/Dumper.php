<?php

namespace App\Utils;

use App\Enums\DatabaseType;
use App\Services\BackupJob\BackupJobObject;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\Exceptions\CannotStartDump;
use Spatie\DbDumper\Exceptions\DumpFailed;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Finder\Finder;
use Throwable;

class Dumper
{
    private Filesystem $filesystem;
    private Manifest $manifest;
    private TemporaryDirectory $temporaryDirectory;
    private string $backupZip;
    private BackupJobObject $backupJob;
    protected array $errors = [];

    public function __construct(BackupJobObject $backupJob)
    {
        $this->backupJob = $backupJob;

        $this->start();

        $this->filesystem = new Filesystem;
    }

    public function start(): void
    {
        $this->temporaryDirectory = (new TemporaryDirectory)->create();
        $this->backupZip          = $this->temporaryDirectory->path('backup.zip');
        $this->manifest           = new Manifest($this->temporaryDirectory->path('manifest.txt'));

        register_shutdown_function(fn() => $this->temporaryDirectory->delete());
    }

    /**
     * @throws DumpFailed
     * @throws CannotStartDump
     */
    private function dumpDatabase(string $database): void
    {
        $filename = Str::of($database)
                       ->slug()
                       ->append('.sql.gz');

        switch ($this->backupJob->getDatabaseType()) {
            case DatabaseType::MySql:
                $dumper = new MySql();
                $dumper->setUserName('root');
                break;
            case DatabaseType::PostgreSql:
                $dumper = new PostgreSql();
                $dumper->setUserName('postgres');
                break;
            default:
                throw new CannotStartDump();
        }

        $dumper->setDbName($database);
        $dumper->setPassword($this->backupJob->getDatabasePassword());
        $dumper->useCompressor(new GzipCompressor);
        $dumper->dumpToFile($dumpPath = $this->temporaryDirectory->path($filename));

        $this->manifest->addFiles($dumpPath);
    }

    protected function fillManifest(): void
    {
        $finder = new Finder;
        $finder->ignoreDotFiles(false);
        $finder->ignoreVCS(false);

        Collection::make($this->backupJob->getIncludeFiles())
                  ->each(function(string $path) use ($finder) {
                      $this->rescue(function() use ($path, $finder) {
                          if ($this->filesystem->isFile($path)) {
                              if (!$this->shouldExcludePath($path)) {
                                  $this->manifest->addFiles($path);
                              }

                              return;
                          }

                          if ($this->filesystem->isDirectory($path)) {
                              foreach ($finder->in($path)->getIterator() as $directory) {
                                  if (!$this->shouldExcludePath($directory)) {
                                      $this->manifest->addFiles($directory->getPathname());
                                  }
                              }
                          }
                      });
                  });
    }

    public function deleteFromFilesystem(string $backupName): void
    {
        $this->rescue(function() use ($backupName) {
            $this->filesystem($this->backupJob->getDiskConfig())->delete($backupName . '.zip');
        });
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function shouldExcludePath(string $path): bool
    {
        $path = realpath($path) ?: $path;

        if ($this->filesystem->isDirectory($path) && !Str::endsWith($path, DIRECTORY_SEPARATOR)) {
            $path .= DIRECTORY_SEPARATOR;
        }

        $excludeFiles = $this->backupJob->getExcludeFiles();

        foreach ($excludeFiles as $excludedPath) {
            if (Str::contains($excludedPath, '*')) {
                if (Str::is($excludedPath, $path)) {
                    return true;
                }

                continue;
            }

            if ($this->filesystem->isDirectory($excludedPath) && !Str::endsWith($excludedPath, DIRECTORY_SEPARATOR)) {
                $excludedPath .= DIRECTORY_SEPARATOR;
            }

            if (Str::startsWith($path, $excludedPath)) {
                if ($path !== $excludedPath && $this->filesystem->isFile($excludedPath)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    public function copyFilesToZipArchive(): void
    {
        $this->rescue(function() {
            $this->fillManifest();

            Zip::createForManifest($this->manifest, $this->backupZip);
        });
    }

    protected function filesystem(array $config): \Illuminate\Contracts\Filesystem\Filesystem
    {
        $driver    = $config['driver'] ?? null;
        $useSshKey = $config['use_ssh_key'] ?? false;

        if ($driver === 'sftp' && $useSshKey) {
            $config['privateKey'] = File::get($config['privateKey']);
        }

        return Storage::build($config);
    }

    public function getZipArchiveSize(): int
    {
        return $this->filesystem->size($this->backupZip);
    }

    public function uploadZipArchive(): void
    {
        $this->rescue(function() {
            $this->filesystem($this->backupJob->getDiskConfig())
                 ->writeStream(
                     $this->backupJob->getName() . '.zip',
                     fopen($this->backupZip, 'r')
                 );
        });
    }

    public function dumpDatabases(): self
    {
        Collection::make($this->backupJob->getDatabases())
                  ->map(function(string $database) {
                      $this->rescue(fn() => $this->dumpDatabase($database));
                  });

        return $this;
    }

    public function deleteTemporaryDirectory(): void
    {
        $this->temporaryDirectory->delete();
    }

    private function rescue(callable $callback): void
    {
        try {
            $callback();

            return;
        } catch (Throwable $ex) {
            $this->errors[] = $ex->getMessage() ?: 'An unknown error occurred';
        }
    }
}
