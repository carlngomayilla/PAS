<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPaoObjectifOperationnel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaoObjectifOperationnelRequest extends FormRequest
{
    use ValidatesPaoObjectifOperationnel;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->paoObjectifOperationnelRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->paoObjectifOperationnelMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->paoObjectifOperationnelAttributes();
    }

    public function withValidator(Validator $validator): void
    {
        $this->applyPaoObjectifOperationnelBusinessRules($validator);
    }
}
