<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class StreamService
{
    public function __construct(
        protected ?Filesystem $storage = null,
    ) {
        $this->storage ??= Storage::disk('local');
    }

    /**
     * @param $stream
     * @param int $len
     * @return int
     */
    public function countLines($stream, int $len = 8192): int
    {
        $lines = 0;

        rewind($stream);
        while (!feof($stream)) {
            $chunk = fread($stream, $len);
            $lines += substr_count($chunk, "\n");
        }

        rewind($stream);

        return $lines;
    }

    public function goToLine($stream, int $lineNumber): void
    {
        rewind($stream);

        if ($lineNumber <= 0) {
            return;
        }

        for ($i = 0; $i < $lineNumber; $i++) {
            if (feof($stream)) {
                break;
            }

            fgets($stream);
        }
    }

    public function goToEnd($stream): void
    {
        if (fseek($stream, 0, SEEK_END) === -1) {
            while (!feof($stream)) {
                fread($stream, 8192);
            }
        }
    }
}
