<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $seenPeriods = [];
        $plans = DB::table('meeting_plans')
            ->orderBy('id')
            ->get(['id', 'direction_id', 'service_id', 'meeting_type', 'year', 'month']);

        foreach ($plans as $plan) {
            $type = (string) $plan->meeting_type;
            $serviceId = $plan->service_id !== null ? (int) $plan->service_id : null;

            if (! in_array($type, ['direction', 'service'], true)) {
                throw new RuntimeException("Le plan de réunion #{$plan->id} possède un type invalide.");
            }

            if (($type === 'service' && $serviceId === null)
                || ($type === 'direction' && $serviceId !== null)) {
                throw new RuntimeException("Le plan de réunion #{$plan->id} possède un périmètre incohérent.");
            }

            $scopeKey = $type === 'service'
                ? 'service:'.$serviceId
                : 'direction:'.(int) $plan->direction_id;
            $periodKey = implode('|', [$scopeKey, $type, (int) $plan->year, (int) $plan->month]);

            if (isset($seenPeriods[$periodKey])) {
                throw new RuntimeException(sprintf(
                    'Les plans de réunion #%d et #%d couvrent le même périmètre mensuel.',
                    $seenPeriods[$periodKey],
                    $plan->id
                ));
            }

            $seenPeriods[$periodKey] = (int) $plan->id;
        }

        Schema::table('meeting_plans', function (Blueprint $table) {
            $table->string('scope_key', 64)->nullable()->after('service_id');
        });

        DB::table('meeting_plans')
            ->orderBy('id')
            ->chunkById(200, function ($plans): void {
                foreach ($plans as $plan) {
                    DB::table('meeting_plans')
                        ->where('id', $plan->id)
                        ->update([
                            'scope_key' => (string) $plan->meeting_type === 'service'
                                ? 'service:'.(int) $plan->service_id
                                : 'direction:'.(int) $plan->direction_id,
                        ]);
                }
            });

        Schema::table('meeting_plans', function (Blueprint $table) {
            $table->string('scope_key', 64)->nullable(false)->change();
            $table->unique(
                ['scope_key', 'meeting_type', 'year', 'month'],
                'meeting_plans_scope_period_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_plans', function (Blueprint $table) {
            $table->dropUnique('meeting_plans_scope_period_unique');
            $table->dropColumn('scope_key');
        });
    }
};
