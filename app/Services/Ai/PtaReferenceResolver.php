<?php

namespace App\Services\Ai;

use App\Models\Direction;
use App\Models\Exercice;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class PtaReferenceResolver
{
    public function findDirection(?string $value): ?Direction
    {
        return $this->findByCodeOrLabel(Direction::query()->get(), $value);
    }

    public function findService(?string $value, ?Direction $direction = null): ?Service
    {
        $query = Service::query();
        if ($direction instanceof Direction) {
            $query->where('direction_id', $direction->id);
        }

        return $this->findByCodeOrLabel($query->get(), $value);
    }

    public function findResponsible(?string $value): ?User
    {
        $needle = $this->key((string) $value);
        if ($needle === '') {
            return null;
        }

        return User::query()
            ->get()
            ->first(function (User $user) use ($needle): bool {
                return $this->key((string) $user->email) === $needle
                    || $this->key((string) $user->name) === $needle;
            });
    }

    public function findOrCreateExercice(mixed $value): Exercice
    {
        $year = $this->yearFrom($value) ?? (int) now()->year;

        return Exercice::query()->firstOrCreate(
            ['annee' => $year],
            [
                'libelle' => 'Exercice '.$year,
                'date_debut' => Carbon::create($year, 1, 1)->toDateString(),
                'date_fin' => Carbon::create($year, 12, 31)->toDateString(),
                'statut' => Exercice::STATUT_OUVERT,
                'is_active' => false,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function findOrCreatePta(array $payload, ?User $actor = null): Pta
    {
        $ptaId = (int) ($payload['pta_id'] ?? 0);
        if ($ptaId > 0) {
            $pta = Pta::query()->find($ptaId);
            if ($pta instanceof Pta) {
                return $pta;
            }
        }

        $ptaCode = trim((string) ($payload['pta_code'] ?? ''));
        if ($ptaCode !== '') {
            $pta = Pta::query()->where('code', $ptaCode)->first();
            if ($pta instanceof Pta) {
                return $pta;
            }
        }

        $direction = $this->findDirection((string) ($payload['direction'] ?? ''));
        $service = $this->findService((string) ($payload['service'] ?? ''), $direction);

        if (! $direction instanceof Direction || ! $service instanceof Service) {
            throw new RuntimeException('Direction ou service introuvable pour creer le PTA cible.');
        }

        $exercice = $this->findOrCreateExercice($payload['exercice'] ?? null);
        $year = (int) $exercice->annee;

        $pas = Pas::query()->firstOrCreate(
            [
                'periode_debut' => $year,
                'periode_fin' => $year,
            ],
            [
                'exercice_id' => $exercice->id,
                'titre' => 'PAS '.$year,
                'created_by' => $actor?->id,
            ]
        );

        $pao = Pao::query()->firstOrCreate(
            [
                'pas_id' => $pas->id,
                'annee' => $year,
                'direction_id' => $direction->id,
            ],
            [
                'exercice_id' => $exercice->id,
                'code' => 'PAO-'.$year.'-'.$this->codePart((string) $direction->code),
                'titre' => 'PAO '.$year.' - '.$direction->libelle,
            ]
        );

        return Pta::query()->firstOrCreate(
            [
                'pao_id' => $pao->id,
                'service_id' => $service->id,
            ],
            [
                'exercice_id' => $exercice->id,
                'code' => $ptaCode !== '' ? $ptaCode : 'PTA-'.$year.'-'.$this->codePart((string) $direction->code).'-'.$this->codePart((string) $service->code),
                'direction_id' => $direction->id,
                'titre' => 'PTA '.$year.' - '.$service->libelle,
                'description' => 'PTA cree depuis un import IA valide.',
            ]
        );
    }

    public function yearFrom(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $year = (int) $value;

            return $year >= 2000 && $year <= 2100 ? $year : null;
        }

        if (preg_match('/(20[0-9]{2}|2100)/', (string) $value, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function findByCodeOrLabel($items, ?string $value): mixed
    {
        $needle = $this->key((string) $value);
        if ($needle === '') {
            return null;
        }

        return $items->first(function ($item) use ($needle): bool {
            return $this->key((string) ($item->code ?? '')) === $needle
                || $this->key((string) ($item->libelle ?? '')) === $needle;
        });
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function codePart(string $value): string
    {
        $value = strtoupper(Str::ascii($value));
        $value = preg_replace('/[^A-Z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-') ?: 'REF';
    }
}
