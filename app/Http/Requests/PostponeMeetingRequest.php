<?php

namespace App\Http\Requests;

use App\Models\Meeting;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class PostponeMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && $this->route('meeting') instanceof Meeting
            && app(MeetingAccessService::class)->canPostpone($this->user(), $this->route('meeting'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $meeting = $this->route('meeting');
            if (! $meeting instanceof Meeting || $validator->errors()->isNotEmpty()) {
                return;
            }

            $newDate = Carbon::createFromFormat(
                'Y-m-d H:i',
                $this->string('scheduled_date').' '.$this->string('scheduled_time')
            );

            if ($meeting->scheduledAt()?->gte($newDate)) {
                $validator->errors()->add('scheduled_date', 'La nouvelle date doit être postérieure à la programmation actuelle.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }
}
