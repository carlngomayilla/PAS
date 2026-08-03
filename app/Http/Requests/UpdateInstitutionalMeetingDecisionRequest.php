<?php

namespace App\Http\Requests;

use App\Models\InstitutionalMeetingDecision;
use App\Models\InstitutionalReport;
use App\Models\User;
use App\Services\InstitutionalReportingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInstitutionalMeetingDecisionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('institutionalReport') instanceof InstitutionalReport
            && $this->route('decision') instanceof InstitutionalMeetingDecision
            && app(InstitutionalReportingService::class)->canUpdateMeetingDecision(
                $this->user(),
                $this->route('institutionalReport'),
                $this->route('decision'),
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
            'status' => ['required', Rule::in([
                InstitutionalMeetingDecision::STATUS_TO_DO,
                InstitutionalMeetingDecision::STATUS_IN_PROGRESS,
                InstitutionalMeetingDecision::STATUS_COMPLETED,
                InstitutionalMeetingDecision::STATUS_SUSPENDED,
            ])],
            'follow_up_note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['follow_up_note' => trim((string) $this->input('follow_up_note'))]);
    }
}
