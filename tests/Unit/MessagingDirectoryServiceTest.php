<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Messaging\MessagingDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingDirectoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_org_chart_uses_fixed_business_order_for_directions_and_services(): void
    {
        $viewer = User::query()->where('email', 'admin@anbg.ga')->firstOrFail();
        $tree = app(MessagingDirectoryService::class)->orgChart($viewer, []);

        $root = $tree['tree'][0] ?? null;
        $this->assertIsArray($root);

        $directionLabels = collect($root['children'] ?? [])
            ->filter(fn (array $node): bool => ($node['type'] ?? null) === 'direction')
            ->pluck('label')
            ->filter(fn (string $label): bool => $label !== 'Pilotage global')
            ->values()
            ->all();

        $this->assertSame(['DG', 'DGA', 'SCIQ', 'UCAS', 'DS', 'DSIC', 'DAF'], $directionLabels);

        $dafNode = collect($root['children'] ?? [])
            ->first(fn (array $node): bool => ($node['label'] ?? null) === 'DAF');

        $this->assertIsArray($dafNode);

        $dafServiceLabels = collect($dafNode['children'] ?? [])
            ->filter(fn (array $node): bool => ($node['type'] ?? null) === 'service')
            ->pluck('label')
            ->values()
            ->all();

        $this->assertSame(['DIRECTION', 'AJARH', 'SFC', 'AMG'], $dafServiceLabels);
    }

    public function test_org_chart_orders_people_inside_services_by_responsibility_then_name(): void
    {
        $viewer = User::query()->where('email', 'admin@anbg.ga')->firstOrFail();
        $tree = app(MessagingDirectoryService::class)->orgChart($viewer, []);

        $root = $tree['tree'][0] ?? null;
        $this->assertIsArray($root);

        $dafNode = collect($root['children'] ?? [])
            ->first(fn (array $node): bool => ($node['label'] ?? null) === 'DAF');

        $this->assertIsArray($dafNode);

        $sfcNode = collect($dafNode['children'] ?? [])
            ->first(fn (array $node): bool => ($node['label'] ?? null) === 'SFC');

        $this->assertIsArray($sfcNode);
        $this->assertSame('EKOMI Robert', $sfcNode['manager_name'] ?? null);

        $sfcPeople = collect($sfcNode['children'] ?? [])
            ->filter(fn (array $node): bool => ($node['type'] ?? null) === 'user')
            ->pluck('label')
            ->values()
            ->all();

        $this->assertSame([
            'MOULOUNGUI Audrey',
            'ABOGO Melissa',
            'MADIBA Herbert',
            'MAPAGA Yannis',
            'MOUALOUANGO Molan',
            'MOUEBI Yannick',
            'MOUKONGO Candy',
            'NDAKISSA Tassiana',
        ], $sfcPeople);
    }
}
