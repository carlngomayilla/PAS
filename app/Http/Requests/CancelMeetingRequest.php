<?php

namespace App\Http\Requests;

use App\Models\Meeting;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;
use Illuminate\Foundation\Http\FormRequest;

class CancelMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('meeting') instanceof Meeting
            && app(MeetingAccessService::class)->canCancel($this->user(), $this->route('meeting'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:3000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }
}
