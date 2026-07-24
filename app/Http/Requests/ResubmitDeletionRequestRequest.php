<?php

namespace App\Http\Requests;

use App\Models\DeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ResubmitDeletionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $deletionRequest = $this->route('deletionRequest');

        return $user instanceof User
            && $deletionRequest instanceof DeletionRequest
            && (int) $deletionRequest->requested_by === (int) $user->id;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'complement' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
