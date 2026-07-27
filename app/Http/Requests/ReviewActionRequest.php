<?php

namespace App\Http\Requests;

use App\Models\Action;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }
}
