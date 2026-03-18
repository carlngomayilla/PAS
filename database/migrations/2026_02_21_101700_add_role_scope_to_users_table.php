<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('direction_id')
                ->nullable()
                ->after('email_verified_at')
                ->constrained('directions')
                ->nullOnDelete();

            $table->unsignedBigInteger('service_id')
                ->nullable()
                ->after('direction_id');

            $table->string('role', 30)
                ->default('service')
                ->after('service_id');

            $table->index('role', 'users_role_index');
            $table->index(['direction_id', 'service_id'], 'users_direction_service_index');

            $table->foreign(['service_id', 'direction_id'], 'users_service_direction_fk')
                ->references(['id', 'direction_id'])
                ->on('services')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign('users_service_direction_fk');
            $table->dropForeign(['direction_id']);
            $table->dropIndex('users_role_index');
            $table->dropIndex('users_direction_service_index');
            $table->dropColumn(['role', 'service_id', 'direction_id']);
        });
    }
};

