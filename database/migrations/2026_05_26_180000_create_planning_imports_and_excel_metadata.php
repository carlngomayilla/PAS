<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('planning_imports')) {
            Schema::create('planning_imports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('role')->nullable();
                $table->string('filename');
                $table->string('module')->default('imports_excel');
                $table->string('mode', 40)->default('create_only');
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('valid_rows')->default(0);
                $table->unsignedInteger('error_rows')->default(0);
                $table->unsignedInteger('created_count')->default(0);
                $table->unsignedInteger('updated_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->string('status', 40)->default('uploaded');
                $table->json('error_report')->nullable();
                $table->json('preview_payload')->nullable();
                $table->string('ip_address', 60)->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }

        $this->addColumnIfMissing('pas_axes', 'import_ordre', function (Blueprint $table): void {
            $table->unsignedInteger('import_ordre')->nullable()->after('ordre');
        });
        $this->addColumnIfMissing('pas_objectifs', 'ordre', function (Blueprint $table): void {
            $table->unsignedInteger('ordre')->default(1)->after('description');
        });
        $this->addColumnIfMissing('pas_objectifs', 'import_ordre', function (Blueprint $table): void {
            $table->unsignedInteger('import_ordre')->nullable()->after('ordre');
        });
        $this->addColumnIfMissing('paos', 'code', function (Blueprint $table): void {
            $table->string('code', 80)->nullable()->after('id')->unique();
        });
        $this->addColumnIfMissing('ptas', 'code', function (Blueprint $table): void {
            $table->string('code', 80)->nullable()->after('id')->unique();
        });
        $this->addColumnIfMissing('objectifs_operationnels', 'code', function (Blueprint $table): void {
            $table->string('code', 120)->nullable()->after('id')->unique();
        });
        $this->addColumnIfMissing('objectifs_operationnels', 'import_ordre', function (Blueprint $table): void {
            $table->unsignedInteger('import_ordre')->nullable()->after('statut');
        });
        $this->addColumnIfMissing('actions', 'code', function (Blueprint $table): void {
            $table->string('code', 120)->nullable()->after('id')->unique();
        });
        $this->addColumnIfMissing('actions', 'ordre_import', function (Blueprint $table): void {
            $table->unsignedInteger('ordre_import')->nullable()->after('objectif_operationnel_id');
        });
        $this->addColumnIfMissing('actions', 'nombre_sous_actions_prevu', function (Blueprint $table): void {
            $table->unsignedInteger('nombre_sous_actions_prevu')->default(0)->after('ordre_import');
        });
        $this->addColumnIfMissing('actions', 'statut_parametrage', function (Blueprint $table): void {
            $table->string('statut_parametrage', 40)->default('parametre')->after('statut_dynamique');
        });

        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('actions') && Schema::hasColumn('actions', 'statut_parametrage')) {
            try {
                DB::statement("ALTER TABLE actions DROP CONSTRAINT IF EXISTS actions_statut_parametrage_check");
                DB::statement("ALTER TABLE actions ADD CONSTRAINT actions_statut_parametrage_check CHECK (statut_parametrage IN ('a_parametrer','parametre'))");
            } catch (Throwable) {
                // Idempotent best-effort constraint for PostgreSQL.
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'actions' => ['statut_parametrage', 'nombre_sous_actions_prevu', 'ordre_import', 'code'],
            'objectifs_operationnels' => ['import_ordre', 'code'],
            'ptas' => ['code'],
            'paos' => ['code'],
            'pas_objectifs' => ['import_ordre'],
            'pas_axes' => ['import_ordre'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));
                if ($existing !== []) {
                    $blueprint->dropColumn($existing);
                }
            });
        }

        Schema::dropIfExists('planning_imports');
    }

    private function addColumnIfMissing(string $table, string $column, Closure $callback): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, $callback);
    }
};
