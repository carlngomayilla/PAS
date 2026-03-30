<?php

namespace App\Support\Zip;

use RuntimeException;
use ZipArchive;

class SimpleZipWriter
{
    /**
     * @param array<string, string> $entries
     */
    public function write(string $path, array $entries): void
    {
        if (class_exists(ZipArchive::class)) {
            $this->writeWithZipArchive($path, $entries);

            return;
        }

        $this->writeStoreOnlyArchive($path, $entries);
    }

    /**
     * @param array<string, string> $entries
     */
    private function writeWithZipArchive(string $path, array $entries): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open XLSX archive for writing.');
        }

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
    }

    /**
     * @param array<string, string> $entries
     */
    private function writeStoreOnlyArchive(string $path, array $entries): void
    {
        $archive = '';
        $centralDirectory = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->dosDateTime();

        foreach ($entries as $name => $contents) {
            $name = str_replace('\\', '/', $name);
            $nameLength = strlen($name);
            $contentsLength = strlen($contents);
            $crc = $this->normalizeCrc32($contents);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $contentsLength,
                $contentsLength,
                $nameLength,
                0
            );

            $archive .= $localHeader.$name.$contents;

            $centralDirectory .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $contentsLength,
                $contentsLength,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            ).$name;

            $offset += strlen($localHeader) + $nameLength + $contentsLength;
        }

        $centralSize = strlen($centralDirectory);
        $endOfCentralDirectory = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($entries),
            count($entries),
            $centralSize,
            $offset,
            0
        );

        if (file_put_contents($path, $archive.$centralDirectory.$endOfCentralDirectory) === false) {
            throw new RuntimeException('Unable to write fallback XLSX archive.');
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private function dosDateTime(): array
    {
        $now = getdate();
        $year = max(1980, (int) $now['year']);
        $month = (int) $now['mon'];
        $day = (int) $now['mday'];
        $hour = (int) $now['hours'];
        $minute = (int) $now['minutes'];
        $second = intdiv((int) $now['seconds'], 2);

        $dosTime = ($hour << 11) | ($minute << 5) | $second;
        $dosDate = (($year - 1980) << 9) | ($month << 5) | $day;

        return [$dosTime, $dosDate];
    }

    private function normalizeCrc32(string $contents): int
    {
        $crc = crc32($contents);
        if ($crc < 0) {
            $crc += 4294967296;
        }

        return $crc;
    }
}
