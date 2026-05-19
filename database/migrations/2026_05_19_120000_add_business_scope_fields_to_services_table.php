<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'type')) {
                $table->string('type', 40)->default('service')->after('libelle');
            }

            if (! Schema::hasColumn('services', 'has_global_view')) {
                $table->boolean('has_global_view')->default(false)->after('type');
            }

            if (! Schema::hasColumn('services', 'has_global_write')) {
                $table->boolean('has_global_write')->default(false)->after('has_global_view');
            }

            if (! Schema::hasColumn('services', 'has_dual_interface')) {
                $table->boolean('has_dual_interface')->default(false)->after('has_global_write');
            }

            if (! Schema::hasColumn('services', 'is_control_unit')) {
                $table->boolean('is_control_unit')->default(false)->after('has_dual_interface');
            }

            if (! Schema::hasColumn('services', 'is_operational')) {
                $table->boolean('is_operational')->default(true)->after('is_control_unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            foreach ([
                'is_operational',
                'is_control_unit',
                'has_dual_interface',
                'has_global_write',
                'has_global_view',
                'type',
            ] as $column) {
                if (Schema::hasColumn('services', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
