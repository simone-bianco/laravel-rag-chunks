<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimoneBianco\LaravelRagChunks\Exceptions\InvalidFileException;
use Throwable;
use ZanySoft\Zip\Facades\Zip;

class FileService
{
    public function getDisk(): string
    {
        return 'local';
    }

    public function __construct(
        protected ?Filesystem $storage = null,
    ) {
        $this->storage ??= Storage::disk($this->getDisk());
    }

    public function getStorage(): ?Filesystem
    {
        return $this->storage;
    }

    public function getTempDirPath(): string
    {
        return DIRECTORY_SEPARATOR . 'temp';
    }

    public function getDownloadDirPath(): string
    {
        return DIRECTORY_SEPARATOR . 'download';
    }

    public function createDirectoryIfNotExists(string $relativePath): void
    {
        if (! $this->storage->directoryExists($relativePath)) {
            $this->storage->makeDirectory($relativePath);
        }
    }

    /**
     * A2R = Absolute to Relative
     *
     * @param string $sourceAbsolutePath
     * @param string $targetRelativePath
     * @return void
     */
    public function moveFileA2R(string $sourceAbsolutePath, string $targetRelativePath): void
    {
        $targetAbsolutePath = $this->getAbsolutePath($targetRelativePath);

        rename($sourceAbsolutePath, $targetAbsolutePath);
    }

    public function generateTempDirPath(?string $dirName = null): string
    {
        $dirName ??= now()->timestamp . '-' . Str::uuid()->toString();
        return $this->getTempDirPath() . DIRECTORY_SEPARATOR . now()->format('d-m-Y') . DIRECTORY_SEPARATOR . $dirName;
    }

    public function generateDownloadDirPath(): string
    {
        return $this->getDownloadDirPath() . DIRECTORY_SEPARATOR . now()->format('d-m-Y');
    }

    public function delete(string $relativePath): void
    {
        $this->storage->delete($relativePath);
    }

    public function files(string $relativePath): array
    {
        return $this->storage->files($relativePath);
    }

    public function exists(string $relativePath): bool
    {
        return $this->storage->exists($relativePath);
    }

    public function getAbsolutePath(string $relativePath): string
    {
        return $this->storage->path($relativePath);
    }

    public function put(string $relativeFilePath, string $content): string
    {
        return $this->storage->put($relativeFilePath, $content);
    }

    public function readStream(string $relativePath)
    {
        return $this->storage->readStream($relativePath);
    }

    public function writeStream(string $relativePath, string $mode = 'a+')
    {
        return fopen($this->getAbsolutePath($relativePath), $mode);
    }

    public function getRelativePath(string $absolutePath): string
    {
        $path = str_replace($this->storage->path(''), '', $absolutePath);

        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }

    public function closeStreams(...$streams): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)){
                fclose($stream);
            }
        }
    }

    public function writeOnStream($stream, string $data, ?int $length = null): void
    {
        fwrite($stream, $data, $length);
    }

    /**
     * @param string $relativeFilePath
     * @param string|null $relativeDestinationPath
     * @return string
     * @throws InvalidFileException
     */
    public function extract(string $relativeFilePath, ?string $relativeDestinationPath = null): string
    {
        $absoluteFilePath = $this->storage->path($relativeFilePath);
        if (!Zip::check($absoluteFilePath)) {
            throw new InvalidFileException('File is not a valid zip (check failed)');
        }

        if (! $relativeDestinationPath) {
            $relativeDestinationPath = dirname($relativeFilePath);
        }

        $zip = Zip::open($absoluteFilePath);
        try {
            if (! $zip || ! $zip->extract($this->storage->path($relativeDestinationPath))) {
                throw new InvalidFileException('Zip extraction failed');
            }

            $zip->close();
        } catch (Throwable $exception) {
            throw new InvalidFileException(message: 'Zip extraction failed', previous: $exception);
        }

        return $relativeDestinationPath;
    }

    protected function addPrefix(string $filename): string
    {
        return now()->timestamp . '_' . Str::random(6) . '-' . $filename;
    }

    /**
     * @param UploadedFile $file
     * @param string|null $relativePath
     * @return string
     * @throws InvalidFileException
     */
    public function saveFile(UploadedFile $file, ?string $relativePath = null): string
    {
        $relativePath ??= sprintf(
            '%s%s%s',
            $this->generateDownloadDirPath(),
            DIRECTORY_SEPARATOR,
            $this->addPrefix($file->getClientOriginalName())
        );

        $this->createDirectoryIfNotExists(dirname($relativePath));

        $saved = $this->storage->put($relativePath, file_get_contents($file->path()));

        if (!$saved) {
            throw new InvalidFileException('File saving failed');
        }

        return $relativePath;
    }

    /**
     * @param string $absoluteSourcePath
     * @param string|null $relativePath
     * @return string
     * @throws InvalidFileException
     */
    public function saveFileByAbsolutePath(string $absoluteSourcePath, ?string $relativePath = null): string
    {
        $relativePath ??= sprintf(
            '%s%s%s.%s',
            $this->generateDownloadDirPath(),
            DIRECTORY_SEPARATOR,
            $this->addPrefix(pathinfo($absoluteSourcePath, PATHINFO_FILENAME)),
            pathinfo($absoluteSourcePath, PATHINFO_EXTENSION)
        );

        $this->createDirectoryIfNotExists(dirname($relativePath));

        $saved = $this->storage->put($relativePath, file_get_contents($absoluteSourcePath));

        if (!$saved) {
            throw new InvalidFileException('File saving failed');
        }

        return $relativePath;
    }

    /**
     * @param string $relativeFilePath
     * @param string|null $relativeDestinationPath
     * @return string
     * @throws InvalidFileException
     */
    public function extractAndDelete(string $relativeFilePath, ?string $relativeDestinationPath = null): string
    {
        $relativeDestinationPath = $this->extract($relativeFilePath, $relativeDestinationPath);

        $this->storage->delete($relativeFilePath);

        return $relativeDestinationPath;
    }
}
