<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'agent')
            ->where(function ($query): void {
                $query->whereNull('agent_matricule')
                    ->orWhereRaw("TRIM(agent_matricule) = ''");
            })
            ->orderBy('id')
            ->eachById(function (object $user): void {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['agent_matricule' => 'AGT-AUTO-'.$user->id]);
            }, 100, 'id', 'id');
    }

    public function down(): void
    {
        // Preserve generated identifiers to keep the historical audit trail intact.
    }
};
