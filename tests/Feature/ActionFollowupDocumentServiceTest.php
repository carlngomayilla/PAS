<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionFollowupDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActionFollowupDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['security.uploads.encrypt_justificatifs' => true]);
    }

    public function test_it_snapshots_a_profile_signature_immutably(): void
    {
        [$action, $actor, $signature] = $this->fixture();
        $receipt = app(ActionFollowupDocumentService::class)->recordSignature($action, $actor, ActionFollowupDocumentService::STAGE_RMO);

        Storage::disk('local')->put((string) $actor->signature_path, 'signature-remplacee');
        $actor->forceFill(['signature_sha256' => hash('sha256', 'signature-remplacee')])->save();

        $this->assertSame('data:image/png;base64,'.base64_encode($signature), $receipt->fresh()->signature_snapshot);
        $this->assertSame(hash('sha256', $signature), $receipt->fresh()->signature_sha256);
    }

    public function test_it_generates_an_encrypted_versioned_pdf_linked_to_action_attachments(): void
    {
        [$action, $rmo] = $this->fixture();
        $chef = $this->signedUser(User::ROLE_SERVICE, 'Chef Service');
        $service = app(ActionFollowupDocumentService::class);
        $rmoReceipt = $service->recordSignature($action, $rmo, ActionFollowupDocumentService::STAGE_RMO);
        $chefReceipt = $service->recordSignature($action, $chef, ActionFollowupDocumentService::STAGE_CHEF);
        $document = $service->generate($action, ActionFollowupDocumentService::STAGE_CHEF, $chef);

        $this->assertSame(1, $document->version);
        $this->assertSame($rmoReceipt->id, $document->rmo_signature_receipt_id);
        $this->assertSame($chefReceipt->id, $document->chef_signature_receipt_id);
        $this->assertNull($document->planification_signature_receipt_id);
        $this->assertSame(ActionFollowupDocumentService::CATEGORY, $document->justificatif->categorie);
        $this->assertTrue($document->justificatif->est_chiffre);
        $this->assertSame('application/pdf', $document->justificatif->mime_type);
        Storage::disk('local')->assertExists($document->justificatif->chemin_stockage);

        $encrypted = Storage::disk('local')->get($document->justificatif->chemin_stockage);
        $pdf = base64_decode(Crypt::decryptString($encrypted), true);
        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(hash('sha256', $pdf), $document->document_sha256);
    }

    public function test_each_validation_creates_a_new_document_version(): void
    {
        [$action, $rmo] = $this->fixture();
        $chef = $this->signedUser(User::ROLE_SERVICE, 'Chef Service');
        $planification = $this->signedUser(User::ROLE_PLANIFICATION, 'Planification');
        $service = app(ActionFollowupDocumentService::class);
        $service->recordSignature($action, $rmo, ActionFollowupDocumentService::STAGE_RMO);
        $service->recordSignature($action, $chef, ActionFollowupDocumentService::STAGE_CHEF);
        $first = $service->generate($action, ActionFollowupDocumentService::STAGE_CHEF, $chef);
        $planificationReceipt = $service->recordSignature($action, $planification, ActionFollowupDocumentService::STAGE_PLANIFICATION);
        $second = $service->generate($action, ActionFollowupDocumentService::STAGE_PLANIFICATION, $planification);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertNotSame($first->justificatif->chemin_stockage, $second->justificatif->chemin_stockage);
        $this->assertSame($planificationReceipt->id, $second->planification_signature_receipt_id);
        $this->assertDatabaseCount('action_followup_documents', 2);
    }

    public function test_planification_version_does_not_reuse_a_previous_optional_sciq_visa(): void
    {
        [$action, $rmo] = $this->fixture();
        $chef = $this->signedUser(User::ROLE_SERVICE, 'Chef Service');
        $planification = $this->signedUser(User::ROLE_PLANIFICATION, 'Planification');
        $sciq = $this->signedUser(User::ROLE_SCIQ, 'Contrôle SCIQ');
        $service = app(ActionFollowupDocumentService::class);
        $service->recordSignature($action, $rmo, ActionFollowupDocumentService::STAGE_RMO);
        $service->recordSignature($action, $chef, ActionFollowupDocumentService::STAGE_CHEF);
        $service->recordSignature($action, $planification, ActionFollowupDocumentService::STAGE_PLANIFICATION);
        $service->recordSignature($action, $sciq, ActionFollowupDocumentService::STAGE_SCIQ);
        $service->generate($action, ActionFollowupDocumentService::STAGE_SCIQ, $sciq);
        $service->recordSignature($action, $planification, ActionFollowupDocumentService::STAGE_PLANIFICATION);
        $newVersion = $service->generate($action, ActionFollowupDocumentService::STAGE_PLANIFICATION, $planification);

        $this->assertNull($newVersion->sciq_signature_receipt_id);
    }

    public function test_legacy_action_without_rmo_receipt_can_be_documented_by_current_validator(): void
    {
        [$action] = $this->fixture();
        $chef = $this->signedUser(User::ROLE_SERVICE, 'Chef Service');
        $service = app(ActionFollowupDocumentService::class);
        $service->recordSignature($action, $chef, ActionFollowupDocumentService::STAGE_CHEF);
        $document = $service->generate($action, ActionFollowupDocumentService::STAGE_CHEF, $chef);

        $this->assertNull($document->rmo_signature_receipt_id);
        $this->assertNotNull($document->chef_signature_receipt_id);
    }

    public function test_generation_is_refused_without_current_stage_receipt_and_writes_nothing(): void
    {
        [$action, $rmo] = $this->fixture();
        $service = app(ActionFollowupDocumentService::class);
        $service->recordSignature($action, $rmo, ActionFollowupDocumentService::STAGE_RMO);

        try {
            $service->generate($action, ActionFollowupDocumentService::STAGE_CHEF, $rmo);
            $this->fail('La génération sans visa courant aurait dû être refusée.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('visa', $exception->getMessage());
        }

        $this->assertDatabaseCount('action_followup_documents', 0);
        $this->assertDatabaseCount('justificatifs', 0);
    }

    public function test_signature_recording_rejects_unknown_stage(): void
    {
        [$action, $actor] = $this->fixture();
        $this->expectException(\InvalidArgumentException::class);
        app(ActionFollowupDocumentService::class)->recordSignature($action, $actor, 'direction');
    }

    public function test_signature_recording_rejects_tampered_profile_file(): void
    {
        [$action, $actor] = $this->fixture();
        Storage::disk('local')->put((string) $actor->signature_path, 'contenu-altere');
        $this->expectException(\DomainException::class);
        app(ActionFollowupDocumentService::class)->recordSignature($action, $actor, ActionFollowupDocumentService::STAGE_RMO);
    }

    public function test_receipts_and_documents_cannot_be_changed_or_deleted_independently(): void
    {
        [$action, $rmo] = $this->fixture();
        $service = app(ActionFollowupDocumentService::class);
        $receipt = $service->recordSignature($action, $rmo, ActionFollowupDocumentService::STAGE_RMO);
        $document = $service->generate($action, ActionFollowupDocumentService::STAGE_RMO, $rmo);

        foreach ([
            static fn () => $receipt->update(['actor_name' => 'Nom modifié']),
            static fn () => $receipt->delete(),
            static fn () => $document->update(['stage' => ActionFollowupDocumentService::STAGE_CHEF]),
            static fn () => $document->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Une mutation d un historique immuable aurait dû être refusée.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertModelExists($receipt);
        $this->assertModelExists($document);
    }

    /** @return array{Action, User, string} */
    private function fixture(): array
    {
        $rmo = $this->signedUser(User::ROLE_AGENT, 'Responsable Mise en Oeuvre');
        $direction = Direction::query()->create(['code' => 'DSIGN', 'libelle' => 'Direction Signature']);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SSIGN', 'libelle' => 'Service Signature']);
        $pas = Pas::query()->create(['titre' => 'PAS Signature', 'periode_debut' => 2026, 'periode_fin' => 2030]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PAO Signature',
            'annee' => 2026,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA Signature',
        ]);
        $action = Action::query()->create([
            'code' => 'ACT-SIGN-001',
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'responsable_id' => $rmo->id,
            'libelle' => 'Identifier les données à sauvegarder',
            'type_action' => Action::TYPE_QUANTITATIVE,
            'statut_parametrage' => 'parametre',
            'quantite_cible' => 10,
            'quantite_realisee' => 8,
            'unite_cible' => 'dossiers',
            'date_fin' => '2026-12-31',
            'resultat_attendu' => 'Liste des données validée',
            'justificatif_obligatoire' => false,
        ]);

        return [$action, $rmo, Storage::disk('local')->get((string) $rmo->signature_path)];
    }

    private function signedUser(string $role, string $name): User
    {
        $user = User::factory()->create(['role' => $role, 'name' => $name]);
        $signature = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->assertIsString($signature);
        $path = 'signatures/'.$user->id.'/signature.png';
        Storage::disk('local')->put($path, $signature);
        $user->forceFill([
            'signature_path' => $path,
            'signature_sha256' => hash('sha256', $signature),
            'signature_updated_at' => now(),
        ])->save();

        return $user->refresh();
    }
}
