<?php

namespace App\Http\Requests;

use App\Models\Meeting;
use App\Models\User;
use App\Services\DocumentPolicySettings;
use App\Services\Meetings\MeetingAccessService;
use Illuminate\Foundation\Http\FormRequest;

class SubmitMeetingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('meeting') instanceof Meeting
            && app(MeetingAccessService::class)->canSubmitReport($this->user(), $this->route('meeting'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $documents = app(DocumentPolicySettings::class);

        return [
            'report' => ['required', 'file', 'max:'.$documents->maxUploadKilobytes(), $documents->mimesRule()],
            'observation' => ['nullable', 'string', 'max:3000'],
            'summary' => ['required', 'string', 'min:20', 'max:5000'],
            'actual_agenda' => ['nullable', 'string', 'max:5000'],
            'decisions' => ['nullable', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'difficulties' => ['nullable', 'string', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $fields = ['observation', 'summary', 'actual_agenda', 'decisions', 'recommendations', 'difficulties', 'observations'];
        $this->merge(collect($fields)->mapWithKeys(
            fn (string $field): array => [$field => trim((string) $this->input($field))]
        )->all());
    }
}
