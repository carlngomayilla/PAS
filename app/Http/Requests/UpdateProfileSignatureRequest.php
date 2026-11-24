<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileSignatureRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'signature' => ['required', 'file', 'image', 'mimes:png', 'extensions:png', 'max:1024', 'dimensions:min_width=40,min_height=20,max_width=2400,max_height=1200'],
            'current_password' => ['required', 'string', 'current_password'],
            'signature_ownership_confirmed' => ['required', 'accepted'],
        ];
    }
}
