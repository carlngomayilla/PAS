<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', User::ROLE_SERVICE)
            ->where('is_agent', true)
            ->update([
                'role' => User::ROLE_AGENT,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', User::ROLE_AGENT)
            ->update([
                'role' => User::ROLE_SERVICE,
                'is_agent' => true,
                'updated_at' => now(),
            ]);
    }
};
