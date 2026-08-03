<?php

namespace App\Http\Requests;

use App\Models\InstitutionalReport;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use App\Services\InstitutionalReportingService;
use Illuminate\Foundation\Http\FormRequest;

class ResubmitInstitutionalReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('institutionalReport') instanceof InstitutionalReport
            && (app(InstitutionalReportingService::class)->canAmend($this->user(), $this->route('institutionalReport'))
                || app(InstitutionalReportingService::class)->canPublishMeetingMinutes($this->user(), $this->route('institutionalReport')));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'summary' => ['nullable', 'string', 'max:5000'],
            'held_at' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'actual_agenda' => ['nullable', 'string', 'max:5000'],
            'decisions' => ['nullable', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'difficulties' => ['nullable', 'string', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'attachment' => [
                'nullable',
                'file',
                'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(),
                app(DocumentPolicySettings::class)->mimesRule(),
            ],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => [
                'file',
                'max:'.app(DocumentPolicySettings::class)->maxUploadKilobytes(),
                app(DocumentPolicySettings::class)->mimesRule(),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['summary' => trim((string) $this->input('summary'))]);
    }
}
