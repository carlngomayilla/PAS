<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AnbgOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $password = Hash::make('Pass@12345');

        foreach ($this->directions() as $direction) {
            DB::table('directions')->updateOrInsert(
                ['code' => $direction['code']],
                [
                    'libelle' => $direction['libelle'],
                    'actif' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $directionIds = DB::table('directions')
            ->whereIn('code', array_column($this->directions(), 'code'))
            ->pluck('id', 'code')
            ->mapWithKeys(static fn ($id, $code): array => [(string) $code => (int) $id])
            ->all();

        foreach ($this->services() as $service) {
            $directionId = $directionIds[$service['direction_code']] ?? null;
            if (! is_int($directionId) || $directionId <= 0) {
                continue;
            }

            DB::table('services')->updateOrInsert(
                [
                    'direction_id' => $directionId,
                    'code' => $service['code'],
                ],
                [
                    'libelle' => $service['libelle'],
                    'actif' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $serviceIds = DB::table('services')
            ->join('directions', 'directions.id', '=', 'services.direction_id')
            ->whereIn('directions.code', array_keys($directionIds))
            ->get(['directions.code as direction_code', 'services.code', 'services.id'])
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->direction_code . '.' . (string) $row->code => (int) $row->id,
            ])
            ->all();

        foreach ($this->users() as $index => $user) {
            $directionId = $directionIds[$user['direction_code']] ?? null;
            $serviceId = null;

            if ($user['service_code'] !== null) {
                $serviceId = $serviceIds[$user['direction_code'] . '.' . $user['service_code']] ?? null;
            }

            DB::table('users')->updateOrInsert(
                ['email' => strtolower((string) $user['email'])],
                [
                    'name' => $user['name'],
                    'password' => $password,
                    'role' => $user['role'],
                    'is_agent' => $user['role'] === User::ROLE_AGENT,
                    'agent_matricule' => $this->resolveMatricule($user, $index + 1),
                    'agent_fonction' => $user['fonction'],
                    'agent_telephone' => null,
                    'direction_id' => $directionId,
                    'service_id' => $serviceId,
                    'email_verified_at' => $now,
                    'password_changed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->deleteLegacyOrganizationEntries($now);
    }

    /**
     * @return array<int, array{code:string, libelle:string}>
     */
    protected function directions(): array
    {
        return [
            ['code' => 'DG', 'libelle' => 'Direction Générale'],
            ['code' => 'DIR021', 'libelle' => 'Direction à identifier 1'],
            ['code' => 'DS', 'libelle' => 'Direction DS'],
            ['code' => 'DSIC', 'libelle' => 'Direction des Systèmes d’Information et de la Communication'],
            ['code' => 'DAF', 'libelle' => 'Direction Administrative et Financière'],
        ];
    }

    /**
     * @return array<int, array{direction_code:string, code:string, libelle:string}>
     */
    protected function services(): array
    {
        return [
            ['direction_code' => 'DG', 'code' => 'DIRGEN', 'libelle' => 'Direction Générale'],
            ['direction_code' => 'DG', 'code' => 'CAB', 'libelle' => 'Cabinet de la Direction Générale'],
            ['direction_code' => 'DG', 'code' => 'SCIQ', 'libelle' => 'Service Contrôle Interne et Qualité / Planification'],
            ['direction_code' => 'DG', 'code' => 'DGA', 'libelle' => 'Direction Générale Adjointe'],
            ['direction_code' => 'DG', 'code' => 'UCAS', 'libelle' => 'UCAS'],

            ['direction_code' => 'DIR021', 'code' => 'DIRECTION', 'libelle' => 'Direction DIR-021'],
            ['direction_code' => 'DIR021', 'code' => 'OPS', 'libelle' => 'Équipe opérationnelle DIR-021'],

            ['direction_code' => 'DS', 'code' => 'DIRECTION', 'libelle' => 'Direction'],
            ['direction_code' => 'DS', 'code' => 'ENB', 'libelle' => 'Service ENB'],
            ['direction_code' => 'DS', 'code' => 'EB', 'libelle' => 'Service EB'],
            ['direction_code' => 'DS', 'code' => 'PLANIF', 'libelle' => 'Planification DS'],

            ['direction_code' => 'DSIC', 'code' => 'DIRECTION', 'libelle' => 'Direction'],
            ['direction_code' => 'DSIC', 'code' => 'SIRS', 'libelle' => 'SIRS'],
            ['direction_code' => 'DSIC', 'code' => 'CRP', 'libelle' => 'CRP'],
            ['direction_code' => 'DSIC', 'code' => 'GDS', 'libelle' => 'Gestion Documentaire et Statistique'],

            ['direction_code' => 'DAF', 'code' => 'DIRECTION', 'libelle' => 'Direction'],
            ['direction_code' => 'DAF', 'code' => 'AJARH', 'libelle' => 'Affaires Juridiques, Administration et Ressources Humaines'],
            ['direction_code' => 'DAF', 'code' => 'SFC', 'libelle' => 'Service Financier et Comptable'],
            ['direction_code' => 'DAF', 'code' => 'AMG', 'libelle' => 'Administration des Moyens Généraux'],
        ];
    }

    /**
     * @return array<int, array{
     *     matricule:string,
     *     name:string,
     *     email:string,
     *     direction_code:string,
     *     service_code:?string,
     *     fonction:string,
     *     role:string
     * }>
     */
    protected function users(): array
    {
        return [
            ['matricule' => 'SAD-001', 'name' => 'Super Administrateur PAS', 'email' => 'superadmin@anbg.ga', 'direction_code' => '', 'service_code' => null, 'fonction' => 'Super administration de la plateforme', 'role' => User::ROLE_SUPER_ADMIN],
            ['matricule' => 'ADM-002', 'name' => 'Administrateur ANBG', 'email' => 'admin@anbg.ga', 'direction_code' => '', 'service_code' => null, 'fonction' => 'Administration fonctionnelle de l’application', 'role' => User::ROLE_ADMIN],
            ['matricule' => 'DG-003', 'name' => 'Ingrid', 'email' => 'ingrid@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'DIRGEN', 'fonction' => 'Directeur Général', 'role' => User::ROLE_DG],

            ['matricule' => 'CAB-004', 'name' => 'ADAN-GBLENOU Loick Eklu Gagnon', 'email' => 'loick.adan@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-005', 'name' => 'LEYOGHO MAYILA Clif Loic', 'email' => 'clif.leyogho@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-006', 'name' => 'MBAZOGO ELLA Urielle', 'email' => 'urielle.mbazogo@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-007', 'name' => 'ATSAKOUNA Judicael', 'email' => 'judicael.atsakouna@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-008', 'name' => 'MOMBO Stecy Michel', 'email' => 'stecy.mombo@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-009', 'name' => 'MABADY BEHOBE Dick-Daniel', 'email' => 'dick.mabady@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-010', 'name' => 'NTSAGA LEKELE Maureen', 'email' => 'maureen.ntsaga@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-011', 'name' => 'MAYAGUI MANAMY Gilles', 'email' => 'gilles.mayagui@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-012', 'name' => 'EVOUNA OBAME Serge', 'email' => 'serge.evouna@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-013', 'name' => 'SIBHA NZE NZONG Ornelia', 'email' => 'ornelia.sibha@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'CAB-014', 'name' => 'NGUEMA Nadia Pascale', 'email' => 'nadia.nguema@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],
            ['matricule' => 'AGT-015', 'name' => 'MOUSSAVOU Orphe Tresor', 'email' => 'tresor.moussavou@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Agent Cabinet', 'role' => User::ROLE_AGENT],

            ['matricule' => 'DIR-016', 'name' => 'À compléter', 'email' => 'dga@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'DGA', 'fonction' => 'Directeur Général Adjoint', 'role' => User::ROLE_DIRECTION],
            ['matricule' => 'CAB-017', 'name' => 'KANGA Monique', 'email' => 'monique.kanga@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Membre Cabinet', 'role' => User::ROLE_CABINET],

            ['matricule' => 'PLA-018', 'name' => 'NGUEBET MOGOULA Hilaire', 'email' => 'hilaire.nguebet@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'SCIQ', 'fonction' => 'Planification / SCIQ', 'role' => User::ROLE_PLANIFICATION],
            ['matricule' => 'PLA-019', 'name' => 'ANGUE ALADE Kassirath', 'email' => 'kassirath.angue@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'SCIQ', 'fonction' => 'Planification / SCIQ', 'role' => User::ROLE_PLANIFICATION],
            ['matricule' => 'PLA-020', 'name' => 'MOUELLE MASSALA NDONGO Aaron', 'email' => 'aaron.mouelle@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'SCIQ', 'fonction' => 'Planification / SCIQ', 'role' => User::ROLE_PLANIFICATION],

            ['matricule' => 'DIR-021', 'name' => 'MBAZOGO NGUEMA Suzy', 'email' => 'suzy.mbazogo@anbg.ga', 'direction_code' => 'DIR021', 'service_code' => 'DIRECTION', 'fonction' => 'Directrice / Responsable de direction', 'role' => User::ROLE_DIRECTION],
            ['matricule' => 'AGT-022', 'name' => 'LEKOSSO Ismene', 'email' => 'ismene.lekosso@anbg.ga', 'direction_code' => 'DIR021', 'service_code' => 'OPS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-023', 'name' => 'MATSOMBI DOGUI Reine', 'email' => 'reine.matsombi@anbg.ga', 'direction_code' => 'DIR021', 'service_code' => 'OPS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-024', 'name' => 'MOUKAMBOU Guy Vincent', 'email' => 'guy.moukambou@anbg.ga', 'direction_code' => 'DIR021', 'service_code' => 'OPS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-025', 'name' => 'OKOUMA Miguelle', 'email' => 'miguelle.okouma@anbg.ga', 'direction_code' => 'DIR021', 'service_code' => 'OPS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],

            ['matricule' => 'DIR-026', 'name' => 'À compléter', 'email' => 'directeur.ds@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'DIRECTION', 'fonction' => 'Directeur DS', 'role' => User::ROLE_DIRECTION],
            ['matricule' => 'SRV-027', 'name' => 'SIMBA Marie Louise', 'email' => 'marie.simba@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'ENB', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'SRV-028', 'name' => 'MAGNANGANI ERIMI Belinda', 'email' => 'belinda.magnangani@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'PLA-029', 'name' => 'NDOUTOUME Jean Servais', 'email' => 'servais.ndoutoume@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'SCIQ', 'fonction' => 'Planification / SCIQ', 'role' => User::ROLE_PLANIFICATION],
            ['matricule' => 'AGT-030', 'name' => 'AZIZET AKEWA Claude', 'email' => 'claude.azizet@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-031', 'name' => 'BISSONGUI MAKITA Raissa', 'email' => 'raissa.bissongui@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-032', 'name' => 'LEKA Noeline', 'email' => 'noeline.leka@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-033', 'name' => 'LEMBA Sigrid', 'email' => 'sigrid.lemba@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-034', 'name' => 'MAKANGOU Zita', 'email' => 'zita.makangou@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-035', 'name' => 'MAVIOGA Leger', 'email' => 'leger.mavioga@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-036', 'name' => 'MENDOME Nadine', 'email' => 'nadine.mendome@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-037', 'name' => 'MENOUETON Codjo', 'email' => 'codjo.menoueton@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-038', 'name' => 'MOUKAGNI Estelle', 'email' => 'estelle.moukagni@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-039', 'name' => 'NGOUBILI Lidy', 'email' => 'lidy.ngoubili@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-040', 'name' => 'NKOURA Charly', 'email' => 'charly.nkoura@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-041', 'name' => 'NYANGUI Sandrine', 'email' => 'sandrine.nyangui@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-042', 'name' => 'SIMBOU Laurencienne', 'email' => 'laurencienne.simbou@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-043', 'name' => 'TOUNGOU Carine', 'email' => 'carine.toungou@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],

            ['matricule' => 'PLA-044', 'name' => 'LEANDRY MBIRA', 'email' => 'leandry.mbira@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'SCIQ', 'fonction' => 'Planification / SCIQ', 'role' => User::ROLE_PLANIFICATION],
            ['matricule' => 'PLA-045', 'name' => 'DOGUI Marie Raphaelle', 'email' => 'raphaelle.dogui@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'SCIQ', 'fonction' => 'Planification / SCIQ', 'role' => User::ROLE_PLANIFICATION],
            ['matricule' => 'PLA-046', 'name' => 'NTSITSIGUI Yannis', 'email' => 'yannis.ntsitsigui@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'SCIQ', 'fonction' => 'Planification / SCIQ', 'role' => User::ROLE_PLANIFICATION],
            ['matricule' => 'PLA-047', 'name' => 'KOMBA Joelle', 'email' => 'joelle.komba@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'SCIQ', 'fonction' => 'Planification / SCIQ', 'role' => User::ROLE_PLANIFICATION],

            ['matricule' => 'DIR-048', 'name' => 'À compléter', 'email' => 'directeur.dsic@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'DIRECTION', 'fonction' => 'Directeur DSIC', 'role' => User::ROLE_DIRECTION],
            ['matricule' => 'SRV-049', 'name' => 'Arnold MINDZELI', 'email' => 'arnold.mindzeli@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-050', 'name' => 'CAMARA Francois', 'email' => 'francois.camara@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-051', 'name' => 'EBINDA Roger', 'email' => 'roger.ebinda@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-052', 'name' => 'NGUEMEDZANG Renaud', 'email' => 'renaud.nguemedzang@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-053', 'name' => 'OSSA Charles', 'email' => 'charles.ossa@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-054', 'name' => 'YEMBI Marvin', 'email' => 'marvin.yembi@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'SRV-055', 'name' => 'MBOUMBA Noelle', 'email' => 'noelle.mboumba@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-056', 'name' => 'MBAZOO Christel', 'email' => 'christel.mbazoo@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-057', 'name' => 'NGOMA Lucrecia', 'email' => 'lucrecia.ngoma@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-058', 'name' => 'OSSI Hans', 'email' => 'hans.ossi@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-059', 'name' => 'KOMBA Staelle', 'email' => 'staelle.komba@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'GDS', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-060', 'name' => 'LEYIGUI Wilfried', 'email' => 'wilfried.leyigui@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'GDS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-061', 'name' => 'MAGNET Clovis', 'email' => 'clovis.magnet@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'GDS', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],

            ['matricule' => 'DIR-062', 'name' => 'À compléter', 'email' => 'directeur.daf@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'DIRECTION', 'fonction' => 'Directeur DAF', 'role' => User::ROLE_DIRECTION],
            ['matricule' => 'SRV-063', 'name' => 'MATTEYA Aicha', 'email' => 'aicha.matteya@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AJARH', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'SRV-064', 'name' => 'ENGONE Charles', 'email' => 'charles.engone@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AJARH', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-065', 'name' => 'INDENGUELA Aldrich', 'email' => 'aldrich.indenguela@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AJARH', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-066', 'name' => 'NGOMBI Charlaine', 'email' => 'charlaine.ngombi@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AJARH', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'SRV-067', 'name' => 'EKOMI Robert', 'email' => 'robert.ekomi@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-068', 'name' => 'ABOGO Melissa', 'email' => 'melissa.abogo@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-069', 'name' => 'MADIBA Herbert', 'email' => 'herbert.madiba@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-070', 'name' => 'MAPAGA Yannis', 'email' => 'yannis.mapaga@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-071', 'name' => 'MOUALOUANGO Molan', 'email' => 'molan.moualouango@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-072', 'name' => 'MOUEBI Yannick', 'email' => 'yannick.mouebi@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-073', 'name' => 'MOUKONGO Candy', 'email' => 'candy.moukongo@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-074', 'name' => 'MOULOUNGUI Audrey', 'email' => 'audrey.mouloungui@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-075', 'name' => 'NDAKISSA Tassiana', 'email' => 'tassiana.ndakissa@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-076', 'name' => 'EYEANG Sonia', 'email' => 'sonia.eyeang@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-077', 'name' => 'NGWELEH Igor', 'email' => 'igor.ngweleh@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'AGT-078', 'name' => 'MIKODJI Casimir', 'email' => 'casimir.mikodji@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-079', 'name' => 'MIKOLO Ulrich', 'email' => 'ulrich.mikolo@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'AGT-080', 'name' => 'NGALIBAMA Grasmy', 'email' => 'grasmy.ngalibama@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['matricule' => 'SRV-081', 'name' => 'BINGOUMA Ines', 'email' => 'ines.bingouma@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'SRV-082', 'name' => 'AFFANE Saturnin', 'email' => 'saturnin.affane@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Chef de service', 'role' => User::ROLE_SERVICE],

            ['matricule' => 'UCAS-001', 'name' => 'À compléter', 'email' => 'ucas@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'UCAS', 'fonction' => 'Responsable UCAS', 'role' => User::ROLE_SERVICE],
            ['matricule' => 'UCAS-002', 'name' => 'À compléter', 'email' => 'agent.ucas@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'UCAS', 'fonction' => 'Agent UCAS', 'role' => User::ROLE_AGENT],
        ];
    }

    protected function deleteLegacyOrganizationEntries(mixed $now): void
    {
        $legacyDirectionIds = DB::table('directions')
            ->whereIn('code', ['DGA', 'SCIQ', 'UCAS'])
            ->pluck('id');

        if ($legacyDirectionIds->isEmpty()) {
            return;
        }

        $this->deleteLegacyPlanningScope($legacyDirectionIds->all());
        $this->clearLegacyOptionalScopes($legacyDirectionIds->all(), $now);

        DB::table('services')
            ->whereIn('direction_id', $legacyDirectionIds)
            ->delete();

        DB::table('directions')
            ->whereIn('id', $legacyDirectionIds)
            ->delete();
    }

    /**
     * @param array<int, int> $legacyDirectionIds
     */
    protected function deleteLegacyPlanningScope(array $legacyDirectionIds): void
    {
        if ($legacyDirectionIds === []) {
            return;
        }

        if (Schema::hasTable('ptas') && Schema::hasColumn('ptas', 'direction_id')) {
            DB::table('ptas')
                ->whereIn('direction_id', $legacyDirectionIds)
                ->delete();
        }

        if (Schema::hasTable('paos') && Schema::hasColumn('paos', 'direction_id')) {
            DB::table('paos')
                ->whereIn('direction_id', $legacyDirectionIds)
                ->delete();
        }

        if (Schema::hasTable('pas_directions') && Schema::hasColumn('pas_directions', 'direction_id')) {
            DB::table('pas_directions')
                ->whereIn('direction_id', $legacyDirectionIds)
                ->delete();
        }

        if (Schema::hasTable('pas_axes') && Schema::hasColumn('pas_axes', 'direction_id')) {
            DB::table('pas_axes')
                ->whereIn('direction_id', $legacyDirectionIds)
                ->update(['direction_id' => null]);
        }
    }

    /**
     * @param array<int, int> $legacyDirectionIds
     */
    protected function clearLegacyOptionalScopes(array $legacyDirectionIds, mixed $now): void
    {
        if ($legacyDirectionIds === []) {
            return;
        }

        foreach (['users', 'delegations', 'export_template_assignments'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'direction_id')) {
                continue;
            }

            $payload = ['direction_id' => null];

            if (Schema::hasColumn($table, 'service_id')) {
                $payload['service_id'] = null;
            }

            if (Schema::hasColumn($table, 'updated_at')) {
                $payload['updated_at'] = $now;
            }

            DB::table($table)
                ->whereIn('direction_id', $legacyDirectionIds)
                ->update($payload);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function resolveMatricule(array $user, int $sequence): string
    {
        $matricule = trim((string) ($user['matricule'] ?? ''));

        return $matricule !== ''
            ? $matricule
            : $this->buildMatricule((string) $user['role'], $sequence);
    }

    protected function buildMatricule(string $role, int $sequence): string
    {
        $prefix = match ($role) {
            User::ROLE_SUPER_ADMIN => 'SAD',
            User::ROLE_ADMIN => 'ADM',
            User::ROLE_DG => 'DG',
            User::ROLE_CABINET => 'CAB',
            User::ROLE_PLANIFICATION => 'PLA',
            User::ROLE_DIRECTION => 'DIR',
            User::ROLE_SERVICE => 'SRV',
            User::ROLE_AGENT => 'AGT',
            default => 'USR',
        };

        return sprintf('%s-%03d', $prefix, $sequence);
    }
}
