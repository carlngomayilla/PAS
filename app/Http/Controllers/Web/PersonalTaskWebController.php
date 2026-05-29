<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PersonalTaskService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PersonalTaskWebController extends Controller
{
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

        return view('workspace.tasks.index', [
            'user' => $user,
            'personalTasks' => $taskService->forUser($user, 100),
        ]);
    }
}
