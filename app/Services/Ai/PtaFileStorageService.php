<?php

namespace App\Services\Ai;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PtaFileStorageService
{
    /**
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xlsx', 'csv', 'png', 'jpg', 'jpeg'];

    /**
     * @return array{path:string,file_type:string}
     */
    public function store(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Format de fichier PTA non autorise.');
        }

        $directory = 'ai-imports/pta/'.now()->format('Y/m/d');
        $filename = (string) Str::uuid().'.'.$extension;
        $path = $file->storeAs($directory, $filename, 'local');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Impossible de stocker le fichier PTA.');
        }

        return [
            'path' => $path,
            'file_type' => $extension,
        ];
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk('local')->path($path);
    }
}
