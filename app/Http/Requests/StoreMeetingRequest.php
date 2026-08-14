<?php

namespace App\Http\Requests;

use App\Enums\MeetingType;
use App\Models\Service;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $type = MeetingType::tryFrom((string) $this->input('meeting_type'));

        if (! $user instanceof User || ! $type instanceof MeetingType) {
            return $user instanceof User;
        }

        return app(MeetingAccessService::class)->canScheduleFor(
            $user,
            $type,
            $this->integer('direction_id'),
            $this->filled('service_id') ? $this->integer('service_id') : null
        );
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'direction_id' => ['required', 'integer', Rule::exists('directions', 'id')->where('actif', true)],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('actif', true)],
            'meeting_type' => ['required', Rule::enum(MeetingType::class)],
            'label' => ['required', 'string', 'min:5', 'max:255'],
            'location' => ['required', 'string', 'min:2', 'max:255'],
            'agenda' => ['nullable', 'string', 'max:5000'],
            'responsible_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'participant_ids' => ['nullable', 'array', 'max:200'],
            'participant_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'scheduled_time' => ['required', 'date_format:H:i'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = MeetingType::tryFrom((string) $this->input('meeting_type'));
            $serviceId = $this->integer('service_id');
            $directionId = $this->integer('direction_id');

            if ($type === MeetingType::Service && $serviceId <= 0) {
                $validator->errors()->add('service_id', 'Sélectionnez le service concerné.');
            }

            if ($type === MeetingType::Direction && $serviceId > 0) {
                $validator->errors()->add('service_id', 'Une réunion de direction concerne toute la direction.');
            }

            if ($serviceId > 0 && ! Service::query()
                ->whereKey($serviceId)
                ->where('direction_id', $directionId)
                ->exists()) {
                $validator->errors()->add('service_id', 'Le service sélectionné n’appartient pas à cette direction.');
            }

            $responsible = User::query()->find($this->integer('responsible_id'));
            if ($responsible instanceof User && (int) $responsible->direction_id !== $directionId) {
                $validator->errors()->add('responsible_id', 'Le responsable doit appartenir à la direction concernée.');
            }

            if ($type === MeetingType::Service && $responsible instanceof User
                && (int) $responsible->service_id !== $serviceId
                && ! $responsible->hasRole(User::ROLE_DIRECTION)) {
                $validator->errors()->add('responsible_id', 'Le responsable doit appartenir au service concerné ou en être le directeur.');
            }

            if ($type instanceof MeetingType && $responsible instanceof User
                && ! app(MeetingAccessService::class)->canScheduleFor(
                    $responsible,
                    $type,
                    $directionId,
                    $type->requiresService() ? $serviceId : null
                )) {
                $validator->errors()->add('responsible_id', 'Le responsable doit être le chef du service concerné ou le directeur.');
            }

            $hasParticipantOutsideDirection = User::query()
                ->whereIn('id', collect($this->input('participant_ids', []))->map(fn (mixed $id): int => (int) $id))
                ->where('direction_id', '!=', $directionId)
                ->exists();
            if ($hasParticipantOutsideDirection) {
                $validator->errors()->add('participant_ids', 'Tous les participants doivent appartenir à la direction concernée.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'label' => trim((string) $this->input('label')),
            'location' => trim((string) $this->input('location')),
            'agenda' => trim((string) $this->input('agenda')),
        ]);
    }
}
