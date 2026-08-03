<?php

namespace App\Http\Requests;

use App\Models\InstitutionalReport;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use App\Services\InstitutionalReportingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstitutionalReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(InstitutionalReportingService::class)->canSubmit($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'report_type' => ['required', Rule::in([
                InstitutionalReport::TYPE_MEETING,
                InstitutionalReport::TYPE_INCIDENT,
                InstitutionalReport::TYPE_ACTIVITY,
                InstitutionalReport::TYPE_OTHER,
            ])],
            'meeting_type' => ['nullable', Rule::in([
                InstitutionalReport::MEETING_TYPE_SERVICE,
                InstitutionalReport::MEETING_TYPE_DIRECTION,
            ])],
            'title' => ['nullable', 'string', 'min:5', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
            'scheduled_at' => ['nullable', 'date'],
            'held_at' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'participant_ids' => ['nullable', 'array', 'min:1', 'max:200'],
            'participant_ids.*' => ['integer', 'distinct', 'exists:users,id'],
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

    public function after(): array
    {
        return [function ($validator): void {
            $meetingScheduleOnly = $this->input('report_type') === InstitutionalReport::TYPE_MEETING
                && $this->filled('scheduled_at')
                && ! $this->filled('held_at');

            if ($this->input('report_type') === InstitutionalReport::TYPE_MEETING && ! $this->filled('scheduled_at')) {
                $validator->errors()->add('scheduled_at', 'La date de programmation est requise pour une réunion.');
            }
            if ($this->input('report_type') === InstitutionalReport::TYPE_MEETING && ! $this->filled('meeting_type')) {
                $validator->errors()->add('meeting_type', 'Indiquez s il s agit d une réunion de service ou de direction.');
            }
            if ($this->input('report_type') === InstitutionalReport::TYPE_MEETING && ! $this->filled('responsible_id')) {
                $validator->errors()->add('responsible_id', 'Désignez le responsable de la réunion.');
            }
            if ($this->input('report_type') === InstitutionalReport::TYPE_MEETING && ! $this->filled('location')) {
                $validator->errors()->add('location', 'Indiquez le lieu de la réunion.');
            }
            if ($this->input('report_type') === InstitutionalReport::TYPE_MEETING && ! is_array($this->input('participant_ids'))) {
                $validator->errors()->add('participant_ids', 'Sélectionnez au moins un participant.');
            }
            if ($this->input('report_type') !== InstitutionalReport::TYPE_MEETING && ! $this->filled('title')) {
                $validator->errors()->add('title', 'L objet est requis pour ce type de rapport.');
            }

            if (! $meetingScheduleOnly && ! $this->hasFile('attachment') && ! $this->hasFile('attachments')) {
                $validator->errors()->add('attachment', 'Une piece jointe est requise pour deposer ce rapport.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'summary' => trim((string) $this->input('summary')),
        ]);
    }
}
