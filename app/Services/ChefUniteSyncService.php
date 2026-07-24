<?php

namespace App\Services;

use App\Models\UniteDg;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChefUniteSyncService
{
    /**
     * @return array<string, string>
     */
    private function roleToUniteCodeMap(): array
    {
        return [
            User::ROLE_CHEF_UNITE_SCIQ => UniteDg::CODE_SCIQ,
            User::ROLE_CHEF_UNITE_DGA => UniteDg::CODE_DGA,
            User::ROLE_CHEF_UNITE_CABINET => UniteDg::CODE_CABINET,
            User::ROLE_CHEF_UNITE_UCAS => UniteDg::CODE_UCAS,
        ];
    }

    public function sync(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $managedUser = User::query()->lockForUpdate()->find($user->getKey());

            if (! $managedUser instanceof User) {
                return;
            }

            $units = $this->lockUnits();
            $this->clearChiefDesignations($units, (int) $managedUser->id);

            if (! (bool) $managedUser->is_active) {
                return;
            }

            $unitCode = $this->roleToUniteCodeMap()[(string) $managedUser->role] ?? null;
            if ($unitCode === null) {
                return;
            }

            $unit = $units->firstWhere('code', $unitCode);
            if (! $unit instanceof UniteDg || ! (bool) $unit->actif) {
                throw ValidationException::withMessages([
                    'role' => 'L’unité correspondant à ce rôle de chef est absente ou inactive.',
                ]);
            }

            $this->alignUserWithUnit($managedUser, $unit);
            $unit->forceFill(['chef_user_id' => $managedUser->id])->save();
        });
    }

    public function assignChief(UniteDg $unit, ?int $chiefUserId): UniteDg
    {
        return DB::transaction(function () use ($unit, $chiefUserId): UniteDg {
            $chief = $chiefUserId !== null
                ? User::query()->lockForUpdate()->find($chiefUserId)
                : null;
            $units = $this->lockUnits();
            $managedUnit = $units->firstWhere('id', $unit->getKey());

            if (! $managedUnit instanceof UniteDg) {
                throw ValidationException::withMessages([
                    'chef_user_id' => 'L’unité sélectionnée est introuvable.',
                ]);
            }

            if ($chiefUserId === null) {
                $managedUnit->forceFill(['chef_user_id' => null])->save();

                return $managedUnit->fresh(['chef']) ?? $managedUnit;
            }

            if (! (bool) $managedUnit->actif) {
                throw ValidationException::withMessages([
                    'chef_user_id' => 'Un chef ne peut pas être désigné sur une unité inactive.',
                ]);
            }

            if (! $chief instanceof User || ! (bool) $chief->is_active) {
                throw ValidationException::withMessages([
                    'chef_user_id' => 'Le chef sélectionné doit disposer d’un compte actif.',
                ]);
            }

            if (! $this->isCompatibleChief($chief, $managedUnit)) {
                throw ValidationException::withMessages([
                    'chef_user_id' => 'Le chef sélectionné doit appartenir à l’unité ou porter le rôle de chef correspondant.',
                ]);
            }

            $this->clearChiefDesignations($units, (int) $chief->id);
            $this->alignUserWithUnit($chief, $managedUnit);
            $managedUnit->forceFill(['chef_user_id' => $chief->id])->save();

            return $managedUnit->fresh(['chef']) ?? $managedUnit;
        });
    }

    /**
     * @return Collection<int, UniteDg>
     */
    private function lockUnits(): Collection
    {
        return UniteDg::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, UniteDg>  $units
     */
    private function clearChiefDesignations(Collection $units, int $userId): void
    {
        $units
            ->filter(fn (UniteDg $unit): bool => (int) ($unit->chef_user_id ?? 0) === $userId)
            ->each(function (UniteDg $unit): void {
                $unit->forceFill(['chef_user_id' => null])->save();
            });
    }

    private function alignUserWithUnit(User $user, UniteDg $unit): void
    {
        if ((int) ($user->unite_dg_id ?? 0) === (int) $unit->id) {
            return;
        }

        $user->forceFill(['unite_dg_id' => $unit->id])->save();
    }

    private function isCompatibleChief(User $user, UniteDg $unit): bool
    {
        if ((int) ($user->unite_dg_id ?? 0) === (int) $unit->id) {
            return true;
        }

        $matchingRole = array_search((string) $unit->code, $this->roleToUniteCodeMap(), true);

        return is_string($matchingRole) && (string) $user->role === $matchingRole;
    }
}
