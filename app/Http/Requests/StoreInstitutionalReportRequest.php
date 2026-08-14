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
                InstitutionalReport::TYPE_INCIDENT,
                InstitutionalReport::TYPE_ACTIVITY,
                InstitutionalReport::TYPE_OTHER,
            ])],
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'direction_id' => ['nullable', 'integer', 'exists:directions,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
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
            if (! $this->hasFile('attachment') && ! $this->hasFile('attachments')) {
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
