<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserModelTest extends TestCase
{
    public function test_is_agent_depends_only_on_role(): void
    {
        $agent = new User([
            'role' => User::ROLE_AGENT,
            'is_agent' => false,
        ]);

        $serviceWithLegacyFlag = new User([
            'role' => User::ROLE_SERVICE,
            'is_agent' => true,
        ]);

        $this->assertTrue($agent->isAgent());
        $this->assertFalse($serviceWithLegacyFlag->isAgent());
    }
}
