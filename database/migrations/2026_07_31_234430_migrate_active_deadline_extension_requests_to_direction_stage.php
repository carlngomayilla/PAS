<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('deadline_extension_requests')
            ->whereIn('status', ['transmise_controle', 'transmise_validation_finale'])
            ->update([
                'status' => 'transmise_direction',
                'director_decision' => null,
                'director_comment' => null,
                'director_reviewed_by' => null,
                'director_reviewed_at' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('deadline_extension_requests')
            ->where('status', 'transmise_direction')
            ->update(['status' => 'transmise_controle']);
    }
};
