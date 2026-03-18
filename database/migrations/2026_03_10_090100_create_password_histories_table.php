<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('password_hash');
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'password_histories_user_created_index');
        });

        $rows = DB::table('users')
            ->select(['id as user_id', 'password'])
            ->get()
            ->map(fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'password_hash' => (string) $row->password,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($rows !== []) {
            DB::table('password_histories')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};
