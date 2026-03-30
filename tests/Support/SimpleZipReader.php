<?php

namespace Tests\Support;

use RuntimeException;

class SimpleZipReader
{
    /**
     * @return array<string, string>
     */
    public function read(string $path): array
    {
        $binary = file_get_contents($path);
        if ($binary === false) {
            throw new RuntimeException('Unable to read ZIP archive.');
        }

        $entries = [];
        $offset = 0;
        $length = strlen($binary);

        while ($offset + 4 <= $length) {
            $signature = unpack('Vsignature', substr($binary, $offset, 4))['signature'] ?? 0;
            if ($signature !== 0x04034b50) {
                break;
            }

            $header = unpack(
                'vversion/vflags/vcompression/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength',
                substr($binary, $offset + 4, 26)
            );

            $compression = (int) ($header['compression'] ?? 0);
            if ($compression !== 0) {
                throw new RuntimeException('SimpleZipReader only supports store-only ZIP entries.');
            }

            $nameLength = (int) ($header['nameLength'] ?? 0);
            $extraLength = (int) ($header['extraLength'] ?? 0);
            $compressedLength = (int) ($header['compressed'] ?? 0);
            $cursor = $offset + 30;
            $name = substr($binary, $cursor, $nameLength);
            $dataOffset = $cursor + $nameLength + $extraLength;
            $entries[$name] = substr($binary, $dataOffset, $compressedLength);

            $offset = $dataOffset + $compressedLength;
        }

        return $entries;
    }
}
