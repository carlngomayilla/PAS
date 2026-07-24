<?php

namespace App\Services\AiImport;

use App\Models\AiImportBatch;
use App\Models\AiImportSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentExtractionService
{
    public function __construct(
        private readonly PdfExtractionService $pdf,
        private readonly ExcelExtractionService $excel,
        private readonly ImportAuditService $audit
    ) {}

    public function createSession(UploadedFile $file, ?User $user, ?string $documentType = null): AiImportSession
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->store('ai-imports/sessions/'.now()->format('Y/m'), 'local');

        $session = AiImportSession::query()->create([
            'user_id' => $user?->id,
            'file_name' => $file->getClientOriginalName(),
            'original_file_path' => $path,
            'file_type' => $extension,
            'document_type' => $documentType !== null ? strtoupper($documentType) : $this->detectDocumentType($file->getClientOriginalName()),
            'status' => AiImportSession::STATUS_UPLOADED,
        ]);

        AiImportBatch::query()->create([
            'user_id' => $user?->id,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $extension,
            'status' => AiImportBatch::STATUS_UPLOADED,
        ]);

        $this->audit->record('upload', $session, $user, null, null, $session->toArray());

        return $session;
    }

    /**
     * @return array{source_type:string,text:string,rows:list<array<string,mixed>>,metadata:array<string,mixed>}
     */
    public function extract(AiImportSession $session): array
    {
        $session->forceFill([
            'status' => AiImportSession::STATUS_EXTRACTING,
            'started_at' => $session->started_at ?? now(),
        ])->save();

        $path = Storage::disk('local')->path($session->original_file_path);
        if (! is_file($path)) {
            throw new RuntimeException('Le fichier importe est introuvable dans le stockage local.');
        }

        $extension = strtolower((string) $session->file_type);
        $result = match ($extension) {
            'pdf' => $this->pdf->extract($path),
            'xlsx', 'csv' => $this->excel->extract($path),
            default => throw new RuntimeException('Type de fichier non pris en charge par l import IA.'),
        };

        $session->forceFill([
            'status' => AiImportSession::STATUS_ANALYZING,
        ])->save();

        return $result;
    }

    public function legacyBatchFor(AiImportSession $session): AiImportBatch
    {
        return AiImportBatch::query()
            ->where('file_path', $session->original_file_path)
            ->where('original_filename', $session->file_name)
            ->firstOrCreate([
                'file_path' => $session->original_file_path,
            ], [
                'user_id' => $session->user_id,
                'original_filename' => $session->file_name,
                'file_type' => $session->file_type,
                'status' => AiImportBatch::STATUS_UPLOADED,
            ]);
    }

    private function detectDocumentType(string $filename): string
    {
        $name = Str::of($filename)->ascii()->upper()->toString();

        return match (true) {
            str_contains($name, 'MIXTE') => 'MIXTE',
            str_contains($name, 'PAO') => 'PAO',
            str_contains($name, 'PTA') => 'PTA',
            str_contains($name, 'PAS') => 'PAS',
            default => 'MIXTE',
        };
    }
}
