<?php

namespace App\Http\Requests;

use App\Models\Action;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use App\Services\FinancialMonitoringService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('action') instanceof Action
            && app(FinancialMonitoringService::class)->canRecord($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'operation_type' => ['required', Rule::in([FinancialTransaction::TYPE_COMMITMENT, FinancialTransaction::TYPE_DISBURSEMENT])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'operated_on' => ['required', 'date'],
            'payment_method' => ['nullable', Rule::in(['virement', 'cheque', 'especes', 'ordre_paiement', 'autre'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'beneficiary' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:3000'],
            'proof' => [
                'required_if:operation_type,'.FinancialTransaction::TYPE_DISBURSEMENT,
                'nullable',
                'file',
                'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(),
                app(DocumentPolicySettings::class)->mimesRule(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference' => trim((string) $this->input('reference')),
            'beneficiary' => trim((string) $this->input('beneficiary')),
            'comment' => trim((string) $this->input('comment')),
        ]);
    }
}
