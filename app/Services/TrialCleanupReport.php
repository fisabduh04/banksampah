<?php

namespace App\Services;

use RuntimeException;

class TrialCleanupReport
{
    private mixed $handle = null;

    private ?string $pending = null;

    private ?string $destination = null;

    public function check(string $path, int $requiredBytes): void
    {
        $directory = realpath(dirname($path));
        if ($directory === false || $directory !== realpath(storage_path('app/private')) || ! is_writable($directory)
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.jsonl\z/', basename($path))
            || file_exists($path) || is_link($path) || file_exists($path.'.pending') || is_link($path.'.pending')) {
            throw new RuntimeException('Laporan harus file baru dalam storage/app/private yang dapat ditulis.');
        }
        if ($this->freeBytes($directory) < max(1048576, $requiredBytes * 4)) {
            throw new RuntimeException('Ruang penyimpanan laporan tidak cukup.');
        }
    }

    protected function freeBytes(string $directory): float
    {
        $bytes = disk_free_space($directory);

        return $bytes === false ? 0 : $bytes;
    }

    /** Filesystem probe only; it never touches the database. */
    public function probe(string $path, int $requiredBytes): void
    {
        $this->check($path, $requiredBytes);
        $probe = $path.'.probe-'.bin2hex(random_bytes(8));
        $handle = fopen($probe, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Tidak dapat membuat file probe laporan.');
        }
        try {
            $this->writeBytes($handle, "report-write-probe\n");
        } finally {
            fclose($handle);
            if (! unlink($probe)) {
                throw new RuntimeException('File probe laporan tidak dapat dibersihkan.');
            }
        }
    }

    public function start(string $path, int $requiredBytes, array $context): void
    {
        $this->probe($path, $requiredBytes);
        $this->destination = $path;
        $this->pending = $path.'.pending';
        $this->handle = fopen($this->pending, 'xb');
        if ($this->handle === false) {
            throw new RuntimeException('Tidak dapat membuat file sementara laporan.');
        }
        $this->append(['status' => 'started', ...$context]);
    }

    public function append(array $entry): void
    {
        if (! is_resource($this->handle)) {
            throw new RuntimeException('File laporan tidak terbuka.');
        }
        $this->writeBytes($this->handle, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    protected function writeBytes(mixed $handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Penulisan laporan gagal atau tidak lengkap.');
            }
            $offset += $written;
        }
        if (! fflush($handle) || ! fsync($handle)) {
            throw new RuntimeException('Sinkronisasi laporan ke penyimpanan gagal.');
        }
    }

    public function finish(array $entry): void
    {
        $this->append($entry);
        $this->close();
        if ($this->destination === null || file_exists($this->destination) || is_link($this->destination)
            || ! rename($this->pending, $this->destination)) {
            throw new RuntimeException('Publikasi laporan gagal; periksa file .pending dan keadaan database.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
