<?php

namespace App\Http\Requests;

use App\Models\Action;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReviewActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $action = $this->route('action');

        return $action instanceof Action
            && $this->user()?->can('reviewByChef', $action) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['valider', 'rejeter'])],
            'motif' => [
                Rule::requiredIf(fn (): bool => $this->string('decision')->toString() === 'rejeter'),
                'nullable',
                'string',
                'max:5000',
            ],
            'progress_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'signature_consent' => [
                Rule::excludeIf(fn (): bool => $this->string('decision')->toString() !== 'valider'),
                Rule::requiredIf(fn (): bool => $this->string('decision')->toString() === 'valider'),
                'accepted',
            ],
            'current_password' => [
                Rule::excludeIf(fn (): bool => $this->string('decision')->toString() !== 'valider'),
                Rule::requiredIf(fn (): bool => $this->string('decision')->toString() === 'valider'),
                'string',
                'current_password',
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();
                if ($this->string('decision')->toString() === 'valider'
                    && (! filled($user?->signature_path) || ! filled($user?->signature_sha256))) {
                    $validator->errors()->add(
                        'signature_consent',
                        'Enregistrez d’abord votre signature dans votre profil avant de signer cette étape.'
                    );
                }
            },
        ];
    }
}
