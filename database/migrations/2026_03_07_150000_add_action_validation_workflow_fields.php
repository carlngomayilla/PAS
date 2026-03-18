<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->enum('statut_validation', [
                'non_soumise',
                'soumise_chef',
                'rejetee_chef',
                'validee_chef',
                'rejetee_direction',
                'validee_direction',
            ])
                ->default('non_soumise')
                ->after('validation_sans_correction');
            $table->foreignId('soumise_par')
                ->nullable()
                ->after('statut_validation')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('soumise_le')->nullable()->after('soumise_par');
            $table->foreignId('evalue_par')
                ->nullable()
                ->after('soumise_le')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('evalue_le')->nullable()->after('evalue_par');
            $table->decimal('evaluation_note', 5, 2)->nullable()->after('evalue_le');
            $table->text('evaluation_commentaire')->nullable()->after('evaluation_note');
            $table->foreignId('direction_valide_par')
                ->nullable()
                ->after('evaluation_commentaire')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('direction_valide_le')->nullable()->after('direction_valide_par');
            $table->decimal('direction_evaluation_note', 5, 2)->nullable()->after('direction_valide_le');
            $table->text('direction_evaluation_commentaire')->nullable()->after('direction_evaluation_note');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('soumise_par');
            $table->dropConstrainedForeignId('evalue_par');
            $table->dropConstrainedForeignId('direction_valide_par');
            $table->dropColumn([
                'statut_validation',
                'soumise_le',
                'evalue_le',
                'evaluation_note',
                'evaluation_commentaire',
                'direction_valide_le',
                'direction_evaluation_note',
                'direction_evaluation_commentaire',
            ]);
        });
    }
};
