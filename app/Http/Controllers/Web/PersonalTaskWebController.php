<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PersonalTaskService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PersonalTaskWebController extends Controller
{
    /** @var array<int, string> */
    private const VIEWS = ['toutes', 'retard', 'a_24h', 'critiques', 'sans_echeance'];

    /** @var array<int, string> */
    private const FAMILIES = ['execution', 'corrections', 'validations', 'financements', 'alertes', 'decisions', 'autres'];

    /** @var array<int, string> */
    private const SORTS = ['priorite', 'echeance', 'reception'];

    /** @var array<int, int> */
    private const PER_PAGE = [15, 25, 50];

    public function index(Request $request, PersonalTaskService $taskService): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $user->loadMissing([
            'direction:id,libelle,code',
            'service:id,libelle,code',
        ]);

        $filters = $this->filters($request);
        $personalTasks = $taskService->workspaceForUser(
            $user,
            $filters,
            $filters['per_page'],
            max(1, $this->integerQuery($request, 'page', 1))
        );
        $personalTasks['items']->appends($request->except('page'));

        return view('workspace.tasks.index', [
            'user' => $user,
            'personalTasks' => $personalTasks,
            'taskFilters' => $filters,
        ]);
    }

    /**
     * @return array{q: string, vue: string, famille: string, tri: string, per_page: int}
     */
    private function filters(Request $request): array
    {
        $view = strtolower(trim($this->stringQuery($request, 'vue', 'toutes')));
        $family = strtolower(trim($this->stringQuery($request, 'famille')));
        $sort = strtolower(trim($this->stringQuery($request, 'tri', 'priorite')));
        $perPage = $this->integerQuery($request, 'per_page', 15);

        return [
            'q' => mb_substr(trim($this->stringQuery($request, 'q')), 0, 100),
            'vue' => in_array($view, self::VIEWS, true) ? $view : 'toutes',
            'famille' => in_array($family, self::FAMILIES, true) ? $family : '',
            'tri' => in_array($sort, self::SORTS, true) ? $sort : 'priorite',
            'per_page' => in_array($perPage, self::PER_PAGE, true) ? $perPage : 15,
        ];
    }

    private function stringQuery(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    private function integerQuery(Request $request, string $key, int $default): int
    {
        $value = $request->query($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
