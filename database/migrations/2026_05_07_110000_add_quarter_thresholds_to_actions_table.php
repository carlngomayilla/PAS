<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            if (! Schema::hasColumn('actions', 'seuil_mode')) {
                $table->string('seuil_mode', 20)->default('unique')->after('seuil_minimum');
            }

            foreach (['seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4'] as $column) {
                if (! Schema::hasColumn('actions', $column)) {
                    $table->decimal($column, 5, 2)->nullable()->after('seuil_mode');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['seuil_t1', 'seuil_t2', 'seuil_t3', 'seuil_t4', 'seuil_mode'],
                static fn (string $column): bool => Schema::hasColumn('actions', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
