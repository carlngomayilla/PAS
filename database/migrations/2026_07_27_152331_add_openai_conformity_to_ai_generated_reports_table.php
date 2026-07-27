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
        Schema::table('ai_generated_reports', function (Blueprint $table) {
            $table->string('ai_provider', 30)->nullable()->after('metrics_snapshot');
            $table->string('ai_model')->nullable()->after('ai_provider');
            $table->string('template_code', 80)->nullable()->after('ai_model');
            $table->string('template_version', 40)->nullable()->after('template_code');
            $table->string('template_fingerprint', 64)->nullable()->after('template_version');
            $table->string('conformity_status', 30)->default('pending')->index()->after('template_fingerprint');
            $table->unsignedTinyInteger('conformity_score')->default(0)->after('conformity_status');
            $table->json('conformity_issues')->nullable()->after('conformity_score');
            $table->timestamp('conformity_checked_at')->nullable()->after('conformity_issues');
            $table->unsignedInteger('input_tokens')->default(0)->after('conformity_checked_at');
            $table->unsignedInteger('output_tokens')->default(0)->after('input_tokens');
            $table->decimal('total_cost_usd', 12, 6)->default(0)->after('output_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_generated_reports', function (Blueprint $table) {
            $table->dropIndex(['conformity_status']);
            $table->dropColumn([
                'ai_provider',
                'ai_model',
                'template_code',
                'template_version',
                'template_fingerprint',
                'conformity_status',
                'conformity_score',
                'conformity_issues',
                'conformity_checked_at',
                'input_tokens',
                'output_tokens',
                'total_cost_usd',
            ]);
        });
    }
};
