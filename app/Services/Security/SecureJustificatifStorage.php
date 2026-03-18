<?php

namespace App\Services\Security;

use App\Models\Justificatif;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecureJustificatifStorage
{
    public function __construct(
        private readonly AntivirusScanner $scanner
    ) {
    }

    /**
     * @return array{path:string,mime_type:?string,taille_octets:int,nom_original:string,est_chiffre:bool}
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
                'taille_octets' => $size,
                'nom_original' => $originalName,
                'est_chiffre' => false,
            ];
        }

        $content = $file->get();
        $encryptedPayload = Crypt::encryptString(base64_encode($content));
        $path = $directory.'/'.Str::uuid()->toString().'.enc';
        $disk->put($path, $encryptedPayload);

        return [
            'path' => $path,
            'mime_type' => $mimeType,
            'taille_octets' => $size,
            'nom_original' => $originalName,
            'est_chiffre' => true,
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

    public function delete(Justificatif $justificatif): void
    {
        $this->deleteByPath($justificatif->chemin_stockage);
    }

    public function download(Justificatif $justificatif): StreamedResponse
    {
        $path = (string) $justificatif->chemin_stockage;
        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'Fichier introuvable.');
        }

        if (! $justificatif->est_chiffre) {
            return Storage::disk('local')->download($path, (string) $justificatif->nom_original);
        }

        try {
            $encryptedContent = Storage::disk('local')->get($path);
            $payload = Crypt::decryptString($encryptedContent);
            $binary = base64_decode($payload, true);
        } catch (DecryptException) {
            abort(500, 'Le justificatif chiffre ne peut pas etre dechiffre.');
        }

        if ($binary === false) {
            abort(500, 'Le justificatif chiffre est corrompu.');
        }

        return Response::streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            (string) $justificatif->nom_original,
            array_filter([
                'Content-Type' => $justificatif->mime_type,
                'Content-Length' => (string) $justificatif->taille_octets,
            ])
        );
    }
}
