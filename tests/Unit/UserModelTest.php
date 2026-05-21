<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserModelTest extends TestCase
{
    public function test_is_agent_depends_only_on_role(): void
    {
        // role et is_agent ne sont plus mass-assignables (cf. A02) : on les pose
        // via forceFill pour reproduire l etat persiste qui interesse le test.
        $agent = (new User())->forceFill([
            'role' => User::ROLE_AGENT,
            'is_agent' => false,
        ]);

        $serviceWithLegacyFlag = (new User())->forceFill([
            'role' => User::ROLE_SERVICE,
            'is_agent' => true,
        ]);

        $this->assertTrue($agent->isAgent());
        $this->assertFalse($serviceWithLegacyFlag->isAgent());
    }
}
