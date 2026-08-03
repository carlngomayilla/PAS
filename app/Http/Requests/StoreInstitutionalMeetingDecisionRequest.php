<?php

namespace App\Http\Requests;

use App\Models\InstitutionalReport;
use App\Models\User;
use App\Services\InstitutionalReportingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstitutionalMeetingDecisionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('institutionalReport') instanceof InstitutionalReport
            && app(InstitutionalReportingService::class)->canManageMeetingDecisions(
                $this->user(),
                $this->route('institutionalReport'),
            );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'min:5', 'max:5000'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'critical'])],
            'due_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['description' => trim((string) $this->input('description'))]);
    }
}
