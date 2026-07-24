<?php

namespace App\Http\Requests;

use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ApplyDeadlineExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $deadlineExtensionRequest = $this->route('deadlineExtensionRequest');

        return $user instanceof User
            && $deadlineExtensionRequest instanceof DeadlineExtensionRequest
            && $deadlineExtensionRequest->action !== null
            && $user->can('applyDeadlineExtension', $deadlineExtensionRequest->action);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
