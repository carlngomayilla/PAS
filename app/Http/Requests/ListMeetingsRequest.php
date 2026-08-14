<?php

namespace App\Http\Requests;

use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMeetingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(MeetingAccessService::class)->canViewModule($this->user());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', Rule::in(['all', 'plans', 'reviews', 'corrections'])],
            'year' => ['nullable', 'integer', 'between:2020,'.(now()->year + 3)],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'meeting_type' => ['nullable', Rule::enum(MeetingType::class)],
            'status' => ['nullable', Rule::enum(MeetingStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['q' => trim((string) $this->input('q'))]);
    }
}
