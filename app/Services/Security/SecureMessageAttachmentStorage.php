<?php

namespace App\Services\Security;

use App\Models\Message;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecureMessageAttachmentStorage
{
    public function __construct(
        private readonly AntivirusScanner $scanner
    ) {
    }

    /**
     * @return array{path:string,mime_type:?string,size_bytes:int,original_name:string,is_encrypted:bool}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $this->scanner->scanUploadedFile($file);

        $disk = Storage::disk('local');
        $directory = trim($directory, '/');
        $encrypt = (bool) config('security.uploads.encrypt_justificatifs', true);
        $originalName = (string) $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType();
        $size = (int) $file->getSize();

        if (! $encrypt) {
            $path = $file->store($directory, 'local');

            return [
                'path' => $path,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'original_name' => $originalName,
                'is_encrypted' => false,
            ];
        }

        $content = $file->get();
        $encryptedPayload = Crypt::encryptString(base64_encode($content));
        $path = $directory.'/'.Str::uuid()->toString().'.enc';
        $disk->put($path, $encryptedPayload);

        return [
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'original_name' => $originalName,
            'is_encrypted' => true,
        ];
    }

    public function deleteByPath(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    public function delete(Message $message): void
    {
        $this->deleteByPath($message->attachment_path);
    }

    public function download(Message $message): StreamedResponse
    {
        $path = (string) $message->attachment_path;
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404, 'Piece jointe introuvable.');
        }

        $downloadName = (string) ($message->attachment_original_name ?: 'piece-jointe');

        if (! $message->attachment_is_encrypted) {
            return Storage::disk('local')->download($path, $downloadName);
        }

        try {
            $encryptedContent = Storage::disk('local')->get($path);
            $payload = Crypt::decryptString($encryptedContent);
            $binary = base64_decode($payload, true);
        } catch (DecryptException) {
            abort(500, 'La piece jointe chiffree ne peut pas etre dechiffree.');
        }

        if ($binary === false) {
            abort(500, 'La piece jointe chiffree est corrompue.');
        }

        return Response::streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            $downloadName,
            array_filter([
                'Content-Type' => $message->attachment_mime_type,
                'Content-Length' => (string) $message->attachment_size_bytes,
            ])
        );
    }
}
