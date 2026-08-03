<?php

namespace App\Http\Requests;

use App\Models\InstitutionalReport;
use App\Models\User;
use App\Services\InstitutionalReportingService;
use Illuminate\Foundation\Http\FormRequest;

class PostponeInstitutionalMeetingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('institutionalReport') instanceof InstitutionalReport
            && app(InstitutionalReportingService::class)->canPostponeMeeting($this->user(), $this->route('institutionalReport'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }
}
