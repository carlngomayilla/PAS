<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPaoObjectifStrategique;
use Illuminate\Foundation\Http\FormRequest;

class StorePaoObjectifStrategiqueRequest extends FormRequest
{
    use ValidatesPaoObjectifStrategique;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->paoObjectifStrategiqueRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->paoObjectifStrategiqueMessages();
    }
}
