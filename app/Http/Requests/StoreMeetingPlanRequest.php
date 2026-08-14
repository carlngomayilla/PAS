<?php

namespace App\Http\Requests;

use App\Enums\MeetingType;
use App\Models\Service;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMeetingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(MeetingAccessService::class)->canDefinePlans($this->user());
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'direction_id' => ['required', 'integer', Rule::exists('directions', 'id')->where('actif', true)],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('actif', true)],
            'meeting_type' => ['required', Rule::enum(MeetingType::class)],
            'year' => ['required', 'integer', 'between:'.now()->year.','.(now()->year + 3)],
            'month' => ['required', 'integer', 'between:1,12'],
            'expected_count' => ['required', 'integer', 'between:0,31'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = MeetingType::tryFrom((string) $this->input('meeting_type'));
            $serviceId = $this->integer('service_id');

            if ($type === MeetingType::Service && $serviceId <= 0) {
                $validator->errors()->add('service_id', 'Le service est obligatoire pour un objectif de réunions de service.');
            }

            if ($type === MeetingType::Direction && $serviceId > 0) {
                $validator->errors()->add('service_id', 'Une réunion de direction ne doit pas être rattachée à un service.');
            }

            if ($serviceId > 0 && ! Service::query()
                ->whereKey($serviceId)
                ->where('direction_id', $this->integer('direction_id'))
                ->exists()) {
                $validator->errors()->add('service_id', 'Le service sélectionné n’appartient pas à cette direction.');
            }
        }];
    }
}
