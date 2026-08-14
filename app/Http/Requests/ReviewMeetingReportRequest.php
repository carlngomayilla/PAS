<?php

namespace App\Http\Requests;

use App\Enums\MeetingApprovalDecision;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewMeetingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $meeting = $this->route('meeting');
        $report = $this->route('meetingReport');

        return $this->user() instanceof User
            && $meeting instanceof Meeting
            && $report instanceof MeetingReport
            && (int) $report->meeting_id === (int) $meeting->id
            && app(MeetingAccessService::class)->canReviewReport($this->user(), $report);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(MeetingApprovalDecision::class)],
            'comment' => [
                Rule::requiredIf($this->input('decision') === MeetingApprovalDecision::CorrectionRequested->value),
                'nullable',
                'string',
                'min:5',
                'max:3000',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['comment' => trim((string) $this->input('comment'))]);
    }
}
