<?php

namespace App\Http\Requests;

use App\Models\PlatformSettingSnapshot;
use App\Models\User;
use App\Services\PlatformSnapshotService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestorePlatformSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $snapshot = $this->route('snapshot');
        $availableGroups = $snapshot instanceof PlatformSettingSnapshot
            ? app(PlatformSnapshotService::class)->groupsForSnapshot($snapshot)
            : [];

        return [
            'partial_restore' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'groups' => ['exclude_unless:partial_restore,1', 'required', 'array', 'min:1'],
            'groups.*' => ['required', 'string', 'max:80', Rule::in($availableGroups)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'groups.required' => 'Sélectionnez au moins un groupe à restaurer.',
            'groups.min' => 'Sélectionnez au moins un groupe à restaurer.',
            'groups.*.in' => 'Un groupe demandé n’existe pas dans ce snapshot.',
        ];
    }
}
