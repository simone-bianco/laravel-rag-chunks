<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use ZanySoft\Zip\Facades\Zip;

class FileService
{
    public function __construct(
        protected ?Filesystem $storage = null,
    ) {
        $this->storage ??= Storage::disk('private');
    }

    public function getTempDirPath(): string
    {
        return '/download/temp';
    }

    protected function createDirectoryIfNotExists(string $relativePath): void
    {
        if (! $this->storage->directoryExists($relativePath)) {
            $this->storage->makeDirectory($relativePath);
        }

        $this->createDirectoryIfNotExists($this->getTempDirPath());
    }

    public function generateDirPath(): string
    {
        return $this->getTempDirPath() . '/' . now()->format('d-m-Y') . '/' . Str::random(8);
    }

    public function generateFilePath(?string $relativePath = null, ?string $extension = null): string
    {
        if (!$relativePath) {
            $relativePath = $this->generateDirPath();
        }

        $relativePath .= Str::random(8);

        if ($extension) {
            $relativePath .= ".$extension";
        }

        return $relativePath;
    }

    public function getAbsolutePath(string $relativePath): string
    {
        return $this->storage->path($relativePath);
    }

    /**
     * @param string $relativeFilePath
     * @param string|null $relativeDestinationPath
     * @return string
     * @throws InvalidFileException
     * @throws Exception
     */
    public function extractAndDelete(string $relativeFilePath, ?string $relativeDestinationPath = null): string
    {
        $absoluteFilePath = $this->storage->path($relativeFilePath);
        if (!Zip::check($absoluteFilePath)) {
            throw new InvalidFileException('File is not a valid zip');
        }

        if (! $relativeDestinationPath) {
            $relativeDestinationPath = dirname($relativeFilePath);
        }

        $zip = Zip::open($absoluteFilePath);
        if (! $zip || ! $zip->extract($this->storage->path($relativeDestinationPath))) {
            throw new InvalidFileException('Zip extraction failed');
        }

        $zip->close();

        $this->storage->delete($relativeFilePath);

        return $relativeDestinationPath;
    }
}
