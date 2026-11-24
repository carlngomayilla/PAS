<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\ActionFollowupDocument;
use App\Models\ActionSignatureReceipt;
use App\Models\User;
use App\Services\Security\SecureJustificatifStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ActionFollowupDocumentService
{
    public const CATEGORY = 'fiche_suivi_generee';

    public const STAGE_RMO = 'rmo';

    public const STAGE_CHEF = 'chef_service';

    public const STAGE_PLANIFICATION = 'planification';

    public const STAGE_SCIQ = 'sciq';

    /** @var list<string> */
    private const STAGES = [
        self::STAGE_RMO,
        self::STAGE_CHEF,
        self::STAGE_PLANIFICATION,
        self::STAGE_SCIQ,
    ];

    public function __construct(private readonly SecureJustificatifStorage $storage) {}

    public function recordSignature(Action $action, User $actor, string $stage): ActionSignatureReceipt
    {
        $this->assertStage($stage);

        $path = is_string($actor->signature_path) ? $actor->signature_path : '';
        if ($path === ''
            || ! str_starts_with($path, 'signatures/'.$actor->id.'/')
            || str_contains($path, '..')
            || ! Storage::disk('local')->exists($path)) {
            throw new \DomainException('Aucune signature personnelle valide n est enregistrée pour cet utilisateur.');
        }

        $binary = Storage::disk('local')->get($path);
        $digest = hash('sha256', $binary);
        if (is_string($actor->signature_sha256)
            && $actor->signature_sha256 !== ''
            && ! hash_equals($actor->signature_sha256, $digest)) {
            throw new \DomainException('La signature personnelle enregistrée ne correspond plus à son empreinte de sécurité.');
        }

        return $action->signatureReceipts()->create([
            'user_id' => $actor->id,
            'stage' => $stage,
            'actor_name' => (string) $actor->name,
            'actor_role' => is_string($actor->role) ? $actor->role : null,
            'actor_matricule' => is_string($actor->agent_matricule) ? $actor->agent_matricule : null,
            'signature_snapshot' => 'data:image/png;base64,'.base64_encode($binary),
            'signature_sha256' => $digest,
            'source_signature_updated_at' => $actor->signature_updated_at,
            'signed_at' => now(),
        ]);
    }

    public function generate(Action $action, string $stage, User $actor): ActionFollowupDocument
    {
        $this->assertStage($stage);

        $storedPath = null;

        try {
            return DB::transaction(function () use ($action, $stage, $actor, &$storedPath): ActionFollowupDocument {
                $lockedAction = Action::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
                $lockedAction->loadMissing([
                    'exercice',
                    'pta',
                    'pao.pasObjectif.pasAxe',
                    'objectifOperationnel.pasObjectif.pasAxe',
                    'responsable',
                    'primaryKpi',
                ]);

                $currentStagePosition = array_search($stage, self::STAGES, true);
                $includedStages = array_slice(self::STAGES, 0, ((int) $currentStagePosition) + 1);
                $receipts = collect(self::STAGES)->mapWithKeys(static fn (string $receiptStage): array => [$receiptStage => null]);
                $currentReceipts = collect($includedStages)
                    ->mapWithKeys(fn (string $receiptStage): array => [
                        $receiptStage => ActionSignatureReceipt::query()
                            ->where('action_id', $lockedAction->id)
                            ->where('stage', $receiptStage)
                            ->latest('signed_at')
                            ->latest('id')
                            ->first(),
                    ]);
                $receipts = $receipts->merge($currentReceipts);

                if (! $receipts->get($stage) instanceof ActionSignatureReceipt) {
                    throw new \DomainException('Le visa de l étape courante doit être enregistré avant la génération du document.');
                }

                $version = ((int) ActionFollowupDocument::query()
                    ->where('action_id', $lockedAction->id)
                    ->max('version')) + 1;
                $generatedAt = now();
                $binary = Pdf::loadView('pdf.action-followup', [
                    'action' => $lockedAction,
                    'receipts' => $receipts,
                    'stage' => $stage,
                    'stageLabel' => $this->stageLabel($stage),
                    'version' => $version,
                    'generatedAt' => $generatedAt,
                    'logoDataUri' => $this->logoDataUri(),
                ])->setPaper('a4', 'portrait')->output();

                $code = Str::slug((string) ($lockedAction->code ?: 'action-'.$lockedAction->id));
                $name = sprintf('fiche-suivi-%s-v%d.pdf', $code, $version);
                $stored = $this->storage->storeGeneratedContent(
                    $binary,
                    $name,
                    'justificatifs/actions/'.$lockedAction->id.'/fiches-suivi',
                    'application/pdf'
                );
                $storedPath = $stored['path'];

                $justificatif = $lockedAction->justificatifs()->create([
                    'categorie' => self::CATEGORY,
                    'nom_original' => $stored['nom_original'],
                    'chemin_stockage' => $stored['path'],
                    'est_chiffre' => $stored['est_chiffre'],
                    'mime_type' => $stored['mime_type'],
                    'taille_octets' => $stored['taille_octets'],
                    'description' => sprintf(
                        'Fiche de suivi générée automatiquement — %s — version %d.',
                        $this->stageLabel($stage),
                        $version
                    ),
                    'ajoute_par' => $actor->id,
                ]);

                return ActionFollowupDocument::query()->create([
                    'action_id' => $lockedAction->id,
                    'justificatif_id' => $justificatif->id,
                    'version' => $version,
                    'stage' => $stage,
                    'generated_by' => $actor->id,
                    'rmo_signature_receipt_id' => $receipts->get(self::STAGE_RMO)?->id,
                    'chef_signature_receipt_id' => $receipts->get(self::STAGE_CHEF)?->id,
                    'planification_signature_receipt_id' => $receipts->get(self::STAGE_PLANIFICATION)?->id,
                    'sciq_signature_receipt_id' => $receipts->get(self::STAGE_SCIQ)?->id,
                    'document_sha256' => hash('sha256', $binary),
                    'generated_at' => $generatedAt,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->storage->deleteByPath($storedPath);

            throw $exception;
        }
    }

    private function assertStage(string $stage): void
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new \InvalidArgumentException('Étape de signature inconnue.');
        }
    }

    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            self::STAGE_RMO => 'Soumission du RMO',
            self::STAGE_CHEF => 'Visa du Chef de service',
            self::STAGE_PLANIFICATION => 'Visa de la Planification',
            self::STAGE_SCIQ => 'Contrôle SCIQ',
        };
    }

    private function logoDataUri(): ?string
    {
        $path = public_path('images/logo-anbg.png');
        if (! is_file($path)) {
            return null;
        }

        $binary = file_get_contents($path);

        return is_string($binary) ? 'data:image/png;base64,'.base64_encode($binary) : null;
    }
}
