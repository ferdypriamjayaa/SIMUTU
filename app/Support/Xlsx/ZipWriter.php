<?php

namespace App\Support\Xlsx;

use RuntimeException;

/**
 * Minimal ZIP archive writer (deflate, no ZIP64) for building .xlsx files.
 *
 * It needs only zlib, so exports work on PHP builds without ext-zip. Entries
 * are written straight to the target file; only the small central directory
 * is kept in memory.
 */
class ZipWriter
{
    /** @var resource */
    private $handle;

    private string $centralDirectory = '';

    private int $entries = 0;

    private int $offset = 0;

    private readonly int $dosTime;

    private readonly int $dosDate;

    public function __construct(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Berkas ekspor tidak dapat dibuat.');
        }

        $this->handle = $handle;

        $now = getdate();
        $this->dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $this->dosDate = ((max(1980, $now['year']) - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];
    }

    public function add(string $name, string $contents): void
    {
        $compressed = gzdeflate($contents, 6);

        if ($compressed === false) {
            throw new RuntimeException('Berkas ekspor tidak dapat dikompresi.');
        }

        $crc = crc32($contents);
        $size = strlen($contents);
        $compressedSize = strlen($compressed);

        if ($this->offset + $compressedSize > 0xFFFFFFFF - 1024) {
            throw new RuntimeException('Data ekspor terlalu besar. Persempit filter laporan.');
        }

        // Bit 11: the entry name is UTF-8.
        $common = pack('vvvvvVVVv', 20, 0x0800, 8, $this->dosTime, $this->dosDate, $crc, $compressedSize, $size, strlen($name));

        $this->write(pack('V', 0x04034B50).$common.pack('v', 0).$name);
        $this->write($compressed);

        $this->centralDirectory .= pack('Vv', 0x02014B50, 20).$common.pack('vvvvVV', 0, 0, 0, 0, 0, $this->offset).$name;
        $this->offset += 30 + strlen($name) + $compressedSize;
        $this->entries++;
    }

    public function close(): void
    {
        $this->write($this->centralDirectory);
        $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, $this->entries, $this->entries, strlen($this->centralDirectory), $this->offset, 0));
        fclose($this->handle);
    }

    private function write(string $bytes): void
    {
        if (fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Berkas ekspor tidak dapat ditulis.');
        }
    }
}
