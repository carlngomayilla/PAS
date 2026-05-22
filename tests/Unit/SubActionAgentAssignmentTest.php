<?php

namespace Tests\Unit;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Web\ActionWebController;
use App\Models\Action;
use App\Models\SousAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SubActionAgentAssignmentTest extends TestCase
{
    public function test_sub_action_payload_keeps_selected_rmo_when_authorized(): void
    {
        $controller = new ActionWebController;
        $method = new ReflectionMethod($controller, 'resolveSubActionAgentId');
        $method->setAccessible(true);

        $resolved = $method->invoke($controller, ['agent_id' => 22], null, [11, 22], 11);

        $this->assertSame(22, $resolved);
    }

    public function test_sub_action_payload_keeps_existing_agent_when_submission_is_empty(): void
    {
        $controller = new ActionWebController;
        $method = new ReflectionMethod($controller, 'resolveSubActionAgentId');
        $method->setAccessible(true);

        $subAction = new SousAction(['agent_id' => 22]);
        $resolved = $method->invoke($controller, ['agent_id' => null], $subAction, [11, 22], 11);

        $this->assertSame(22, $resolved);
    }

    public function test_new_sub_action_without_explicit_agent_is_distributed_by_row_order(): void
    {
        $controller = new ActionWebController;
        $method = new ReflectionMethod($controller, 'resolveSubActionAgentId');
        $method->setAccessible(true);

        $resolved = $method->invoke($controller, ['agent_id' => null], null, [11, 22], 11, 1);

        $this->assertSame(22, $resolved);
    }

    public function test_dashboard_agent_rows_include_agents_assigned_only_on_sub_actions(): void
    {
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'actionAgents');
        $method->setAccessible(true);

        $primary = new User(['name' => 'Premier RMO']);
        $primary->id = 11;
        $subAgent = new User(['name' => 'Deuxieme RMO']);
        $subAgent->id = 22;

        $subAction = new SousAction(['agent_id' => 22]);
        $subAction->setRelation('agent', $subAgent);

        $action = new Action(['responsable_id' => 11]);
        $action->setRelation('responsable', $primary);
        $action->setRelation('responsables', new EloquentCollection([$primary]));
        $action->setRelation('sousActions', new EloquentCollection([$subAction]));

        $agents = $method->invoke($controller, $action);

        $this->assertSame([11, 22], $agents->pluck('id')->all());
    }
}
