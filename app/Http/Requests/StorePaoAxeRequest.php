<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPaoAxe;
use Illuminate\Foundation\Http\FormRequest;

class StorePaoAxeRequest extends FormRequest
{
    use ValidatesPaoAxe;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->paoAxeRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->paoAxeMessages();
    }
}
