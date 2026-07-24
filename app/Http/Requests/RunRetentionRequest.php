<?php

namespace App\Http\Requests;

use App\Models\RetentionRun;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunRetentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasPermission('retention.manage');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['dry-run', RetentionRun::MODE_EXECUTE])],
            'scope' => ['required', Rule::in([RetentionRun::SCOPE_DATA, RetentionRun::SCOPE_PLANNING])],
        ];
    }
}
