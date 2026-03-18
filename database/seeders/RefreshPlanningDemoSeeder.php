<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RefreshPlanningDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            DB::table('alert_reads')->delete();
            DB::table('notifications')->delete();
            DB::table('journal_audit')->delete();
            DB::table('action_logs')->delete();
            DB::table('action_weeks')->delete();
            DB::table('action_kpis')->delete();
            DB::table('kpi_mesures')->delete();
            DB::table('kpis')->delete();
            DB::table('justificatifs')->delete();
            DB::table('actions')->delete();
            DB::table('ptas')->delete();
            DB::table('pao_objectifs_operationnels')->delete();
            DB::table('pao_objectifs_strategiques')->delete();
            DB::table('pao_axes')->delete();
            DB::table('paos')->delete();
            DB::table('pas_objectifs')->delete();
            DB::table('pas_axes')->delete();
            DB::table('pas_directions')->delete();
            DB::table('pas')->delete();
        });

        $this->call([
            DemoPlanningSeeder::class,
            AssignOneActionPerAgentSeeder::class,
        ]);
    }
}
