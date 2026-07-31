<?php

namespace App\Http\Requests;

use App\Models\BudgetOverrunRequest;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use App\Services\FinancialMonitoringService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetOverrunRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User && app(FinancialMonitoringService::class)->canRecord($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::in([
                BudgetOverrunRequest::SCOPE_ACTION,
                BudgetOverrunRequest::SCOPE_SERVICE,
                BudgetOverrunRequest::SCOPE_DIRECTION,
            ])],
            'scope_id' => ['required', 'integer', 'min:1'],
            'requested_extra' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'reason' => ['required', 'string', 'min:10', 'max:3000'],
            'proof' => ['nullable', 'file', 'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(), app(DocumentPolicySettings::class)->mimesRule()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }
}
