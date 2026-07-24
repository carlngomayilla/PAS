<?php

namespace App\Http\Requests;

use App\Models\ExportTemplate;
use App\Models\User;
use App\Services\RoleRegistryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExportTemplateAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $template = $this->route('template');
        $template = $template instanceof ExportTemplate ? $template : null;

        return [
            'module' => ['required', Rule::in(array_filter([$template?->module]))],
            'report_type' => ['required', Rule::in(array_filter([$template?->report_type]))],
            'format' => ['required', Rule::in(array_filter([$template?->format]))],
            'target_profile' => ['nullable', Rule::in(app(RoleRegistryService::class)->codes())],
            'reading_level' => ['nullable', Rule::in(['interne', 'provisoire', 'valide', 'officiel'])],
            'direction_id' => [
                'nullable',
                'integer',
                Rule::exists('directions', 'id')->where('actif', true),
            ],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where('actif', true),
            ],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'module.in' => 'Le module de l’affectation doit être celui du template.',
            'report_type.in' => 'Le type de rapport doit être celui du template.',
            'format.in' => 'Le format de l’affectation doit être celui du template.',
            'direction_id.exists' => 'La direction sélectionnée doit être active.',
            'service_id.exists' => 'Le service sélectionné doit être actif.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_default' => $this->boolean('is_default') ? '1' : '0',
            'is_active' => $this->boolean('is_active') ? '1' : '0',
        ]);
    }
}
