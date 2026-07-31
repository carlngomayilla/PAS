<?php

namespace App\Http\Requests;

use App\Models\BudgetOverrunRequest;
use App\Models\User;
use App\Services\FinancialMonitoringService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewBudgetOverrunRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $overrun = $this->route('budgetOverrunRequest');

        return $user instanceof User
            && $overrun instanceof BudgetOverrunRequest
            && ($user->hasRole(User::ROLE_DG) || app(FinancialMonitoringService::class)->isDafDirector($user));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['transmit', 'approve', 'reject'])],
            'note' => ['required', 'string', 'min:5', 'max:3000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => trim((string) $this->input('note'))]);
    }
}
