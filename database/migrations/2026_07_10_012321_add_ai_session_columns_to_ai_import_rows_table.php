<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_import_rows', function (Blueprint $table) {
            $table->foreignId('ai_import_session_id')->nullable()->constrained('ai_import_sessions')->nullOnDelete();
            $table->unsignedInteger('source_page')->nullable();
            $table->unsignedInteger('source_line')->nullable();
            $table->string('code_pas')->nullable();
            $table->text('axe')->nullable();
            $table->text('objectif_strategique')->nullable();
            $table->text('objectif_operationnel')->nullable();
            $table->string('direction')->nullable();
            $table->string('service')->nullable();
            $table->text('action')->nullable();
            $table->text('sous_action')->nullable();
            $table->string('rmo')->nullable();
            $table->text('cible')->nullable();
            $table->string('type_indicateur', 30)->nullable();
            $table->decimal('quantite_a_realiser', 15, 4)->nullable();
            $table->text('livrable_attendu')->nullable();
            $table->string('unite_mesure', 100)->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->string('statut_import', 40)->default('a_verifier')->index();
            $table->json('errors_json')->nullable();
            $table->json('raw_json')->nullable();

            $table->index(['ai_import_session_id', 'statut_import']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_import_rows', function (Blueprint $table) {
            $table->dropIndex(['ai_import_session_id', 'statut_import']);
            $table->dropConstrainedForeignId('ai_import_session_id');
            $table->dropColumn([
                'source_page',
                'source_line',
                'code_pas',
                'axe',
                'objectif_strategique',
                'objectif_operationnel',
                'direction',
                'service',
                'action',
                'sous_action',
                'rmo',
                'cible',
                'type_indicateur',
                'quantite_a_realiser',
                'livrable_attendu',
                'unite_mesure',
                'date_debut',
                'date_fin',
                'statut_import',
                'errors_json',
                'raw_json',
            ]);
        });
    }
};
