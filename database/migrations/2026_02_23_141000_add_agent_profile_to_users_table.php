<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_agent')->default(false)->after('role');
            $table->string('agent_matricule', 80)->nullable()->after('is_agent');
            $table->string('agent_fonction', 120)->nullable()->after('agent_matricule');
            $table->string('agent_telephone', 40)->nullable()->after('agent_fonction');

            $table->index('is_agent', 'users_is_agent_index');
            $table->index('agent_matricule', 'users_agent_matricule_index');
        });

        DB::table('users')
            ->where('role', 'service')
            ->update(['is_agent' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_is_agent_index');
            $table->dropIndex('users_agent_matricule_index');
            $table->dropColumn([
                'is_agent',
                'agent_matricule',
                'agent_fonction',
                'agent_telephone',
            ]);
        });
    }
};
