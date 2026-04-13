<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
                    'agent_matricule' => $this->buildMatricule($user['role'], $index + 1),
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
    }

    /**
     * @return array<int, array{code:string, libelle:string}>
     */
    protected function directions(): array
    {
        return [
            ['code' => 'DG', 'libelle' => 'Direction Generale'],
            ['code' => 'DGA', 'libelle' => 'Direction Generale Adjointe'],
            ['code' => 'SCIQ', 'libelle' => 'Service Controle Interne et Qualite'],
            ['code' => 'UCAS', 'libelle' => 'UCAS'],
            ['code' => 'DS', 'libelle' => 'Direction de la Scolarite'],
            ['code' => 'DSIC', 'libelle' => 'Direction des Systemes d Information et de la Communication'],
            ['code' => 'DAF', 'libelle' => 'Direction Administrative et Financiere'],
        ];
    }

    /**
     * @return array<int, array{direction_code:string, code:string, libelle:string}>
     */
    protected function services(): array
    {
        return [
            ['direction_code' => 'DG', 'code' => 'DIRGEN', 'libelle' => 'Direction Generale'],
            ['direction_code' => 'DG', 'code' => 'CAB', 'libelle' => 'Cabinet'],

            ['direction_code' => 'DGA', 'code' => 'DIRECTION', 'libelle' => 'Direction Generale Adjointe'],
            ['direction_code' => 'DGA', 'code' => 'SECDGA', 'libelle' => 'Secretariat DGA'],

            ['direction_code' => 'SCIQ', 'code' => 'CTRLINT', 'libelle' => 'Controle Interne'],

            ['direction_code' => 'UCAS', 'code' => 'UCAS', 'libelle' => 'UCAS'],
            ['direction_code' => 'UCAS', 'code' => 'ACCUEIL', 'libelle' => 'Accueil'],

            ['direction_code' => 'DS', 'code' => 'DIRECTION', 'libelle' => 'Direction'],
            ['direction_code' => 'DS', 'code' => 'ENB', 'libelle' => 'ENB'],
            ['direction_code' => 'DS', 'code' => 'EB', 'libelle' => 'EB'],
            ['direction_code' => 'DS', 'code' => 'PLANIF', 'libelle' => 'Planification'],

            ['direction_code' => 'DSIC', 'code' => 'DIRECTION', 'libelle' => 'Direction'],
            ['direction_code' => 'DSIC', 'code' => 'SIRS', 'libelle' => 'SIRS'],
            ['direction_code' => 'DSIC', 'code' => 'CRP', 'libelle' => 'CRP'],
            ['direction_code' => 'DSIC', 'code' => 'GDS', 'libelle' => 'GDS'],

            ['direction_code' => 'DAF', 'code' => 'DIRECTION', 'libelle' => 'Direction'],
            ['direction_code' => 'DAF', 'code' => 'AJARH', 'libelle' => 'AJARH'],
            ['direction_code' => 'DAF', 'code' => 'SFC', 'libelle' => 'SFC'],
            ['direction_code' => 'DAF', 'code' => 'AMG', 'libelle' => 'AMG'],
        ];
    }

    /**
     * @return array<int, array{
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
            ['name' => 'Super Administrateur PAS', 'email' => 'superadmin@anbg.ga', 'direction_code' => '', 'service_code' => null, 'fonction' => 'Super administration de la plateforme', 'role' => User::ROLE_SUPER_ADMIN],
            ['name' => 'Administrateur ANBG', 'email' => 'admin@anbg.ga', 'direction_code' => '', 'service_code' => null, 'fonction' => "Administrateur de l'application", 'role' => User::ROLE_ADMIN],
            ['name' => 'Ingrid', 'email' => 'ingrid@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'DIRGEN', 'fonction' => 'Directeur General', 'role' => User::ROLE_DG],
            ['name' => 'ADAN-GBLENOU Loick Eklu Gagnon', 'email' => 'loick.adan@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Conseiller Technique', 'role' => User::ROLE_CABINET],
            ['name' => 'LEYOGHO MAYILA Clif Loic', 'email' => 'clif.leyogho@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => "Charge d'etude", 'role' => User::ROLE_CABINET],
            ['name' => 'MBAZOGO ELLA Urielle', 'email' => 'urielle.mbazogo@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => "Charge d'etude", 'role' => User::ROLE_CABINET],
            ['name' => 'ATSAKOUNA Judicael', 'email' => 'judicael.atsakouna@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => "Charge d'etude", 'role' => User::ROLE_CABINET],
            ['name' => 'MOMBO Stecy Michel', 'email' => 'stecy.mombo@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => "Charge d'etude", 'role' => User::ROLE_CABINET],
            ['name' => 'MABADY BEHOBE Dick-Daniel', 'email' => 'dick.mabady@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => "Charge d'etude", 'role' => User::ROLE_CABINET],
            ['name' => 'NTSAGA LEKELE Maureen', 'email' => 'maureen.ntsaga@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => "Charge d'etude", 'role' => User::ROLE_CABINET],
            ['name' => 'MAYAGUI MANAMY Gilles', 'email' => 'gilles.mayagui@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Attache de cabinet', 'role' => User::ROLE_CABINET],
            ['name' => 'EVOUNA OBAME Serge', 'email' => 'serge.evouna@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Assistant administratif', 'role' => User::ROLE_CABINET],
            ['name' => 'SIBHA NZE NZONG Ornelia', 'email' => 'ornelia.sibha@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Secretaire DG', 'role' => User::ROLE_CABINET],
            ['name' => 'NGUEMA Nadia Pascale', 'email' => 'nadia.nguema@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Secretaire DG', 'role' => User::ROLE_CABINET],
            ['name' => 'MOUSSAVOU Orphe Tresor', 'email' => 'tresor.moussavou@anbg.ga', 'direction_code' => 'DG', 'service_code' => 'CAB', 'fonction' => 'Chauffeur DG', 'role' => User::ROLE_AGENT],

            ['name' => 'A completer', 'email' => 'dga@anbg.ga', 'direction_code' => 'DGA', 'service_code' => 'DIRECTION', 'fonction' => 'Directeur General Adjoint', 'role' => User::ROLE_DIRECTION],
            ['name' => 'KANGA Monique', 'email' => 'monique.kanga@anbg.ga', 'direction_code' => 'DGA', 'service_code' => 'SECDGA', 'fonction' => 'Secretaire DGA', 'role' => User::ROLE_CABINET],

            ['name' => 'NGUEBET MOGOULA Hilaire', 'email' => 'hilaire.nguebet@anbg.ga', 'direction_code' => 'SCIQ', 'service_code' => 'CTRLINT', 'fonction' => 'Chef service', 'role' => User::ROLE_PLANIFICATION],
            ['name' => 'ANGUE ALADE Kassirath', 'email' => 'kassirath.angue@anbg.ga', 'direction_code' => 'SCIQ', 'service_code' => 'CTRLINT', 'fonction' => 'Controleur interne', 'role' => User::ROLE_PLANIFICATION],
            ['name' => 'MOUELLE MASSALA NDONGO Aaron', 'email' => 'aaron.mouelle@anbg.ga', 'direction_code' => 'SCIQ', 'service_code' => 'CTRLINT', 'fonction' => 'Controleur interne', 'role' => User::ROLE_PLANIFICATION],

            ['name' => 'MBAZOGO NGUEMA Suzy', 'email' => 'suzy.mbazogo@anbg.ga', 'direction_code' => 'UCAS', 'service_code' => 'UCAS', 'fonction' => 'Responsable UCAS', 'role' => User::ROLE_DIRECTION],
            ['name' => 'LEKOSSO Ismene', 'email' => 'ismene.lekosso@anbg.ga', 'direction_code' => 'UCAS', 'service_code' => 'ACCUEIL', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['name' => 'MATSOMBI DOGUI Reine', 'email' => 'reine.matsombi@anbg.ga', 'direction_code' => 'UCAS', 'service_code' => 'ACCUEIL', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['name' => 'MOUKAMBOU Guy Vincent', 'email' => 'guy.moukambou@anbg.ga', 'direction_code' => 'UCAS', 'service_code' => 'ACCUEIL', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['name' => 'OKOUMA Miguelle', 'email' => 'miguelle.okouma@anbg.ga', 'direction_code' => 'UCAS', 'service_code' => 'ACCUEIL', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],

            ['name' => 'A completer', 'email' => 'directeur.ds@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'DIRECTION', 'fonction' => 'Directeur de la Scolarite', 'role' => User::ROLE_DIRECTION],
            ['name' => 'SIMBA Marie Louise', 'email' => 'marie.simba@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'ENB', 'fonction' => 'Chef service ENB', 'role' => User::ROLE_SERVICE],
            ['name' => 'MAGNANGANI ERIMI Belinda', 'email' => 'belinda.magnangani@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Chef service EB', 'role' => User::ROLE_SERVICE],
            ['name' => 'NDOUTOUME Jean Servais', 'email' => 'servais.ndoutoume@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'PLANIF', 'fonction' => 'Chef service planification', 'role' => User::ROLE_PLANIFICATION],
            ['name' => 'AZIZET AKEWA Claude', 'email' => 'claude.azizet@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'BISSONGUI MAKITA Raissa', 'email' => 'raissa.bissongui@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire senior', 'role' => User::ROLE_AGENT],
            ['name' => 'LEKA Noeline', 'email' => 'noeline.leka@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire senior', 'role' => User::ROLE_AGENT],
            ['name' => 'LEMBA Sigrid', 'email' => 'sigrid.lemba@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'MAKANGOU Zita', 'email' => 'zita.makangou@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire senior', 'role' => User::ROLE_AGENT],
            ['name' => 'MAVIOGA Leger', 'email' => 'leger.mavioga@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire senior', 'role' => User::ROLE_AGENT],
            ['name' => 'MENDOME Nadine', 'email' => 'nadine.mendome@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire senior', 'role' => User::ROLE_AGENT],
            ['name' => 'MENOUETON Codjo', 'email' => 'codjo.menoueton@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Responsable pole', 'role' => User::ROLE_SERVICE],
            ['name' => 'MOUKAGNI Estelle', 'email' => 'estelle.moukagni@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'NGOUBILI Lidy', 'email' => 'lidy.ngoubili@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'NKOURA Charly', 'email' => 'charly.nkoura@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'NYANGUI Sandrine', 'email' => 'sandrine.nyangui@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire senior', 'role' => User::ROLE_AGENT],
            ['name' => 'SIMBOU Laurencienne', 'email' => 'laurencienne.simbou@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'TOUNGOU Carine', 'email' => 'carine.toungou@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'EB', 'fonction' => 'Responsable pole etranger', 'role' => User::ROLE_SERVICE],
            ['name' => 'LEANDRY MBIRA', 'email' => 'leandry.mbira@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'PLANIF', 'fonction' => 'Charge statistiques', 'role' => User::ROLE_PLANIFICATION],
            ['name' => 'DOGUI Marie Raphaelle', 'email' => 'raphaelle.dogui@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'PLANIF', 'fonction' => 'Suivi-evaluation', 'role' => User::ROLE_PLANIFICATION],
            ['name' => 'NTSITSIGUI Yannis', 'email' => 'yannis.ntsitsigui@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'PLANIF', 'fonction' => 'Charge suivi academique', 'role' => User::ROLE_PLANIFICATION],
            ['name' => 'KOMBA Joelle', 'email' => 'joelle.komba@anbg.ga', 'direction_code' => 'DS', 'service_code' => 'PLANIF', 'fonction' => 'Partenariats', 'role' => User::ROLE_PLANIFICATION],

            ['name' => 'A completer', 'email' => 'directeur.dsic@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'DIRECTION', 'fonction' => "Directeur des Systemes d'Information et de la Communication", 'role' => User::ROLE_DIRECTION],
            ['name' => 'Arnold MINDZELI', 'email' => 'arnold.mindzeli@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Chef service', 'role' => User::ROLE_SERVICE],
            ['name' => 'CAMARA Francois', 'email' => 'francois.camara@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Agent IT', 'role' => User::ROLE_AGENT],
            ['name' => 'EBINDA Roger', 'email' => 'roger.ebinda@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Agent IT', 'role' => User::ROLE_AGENT],
            ['name' => 'NGUEMEDZANG Renaud', 'email' => 'renaud.nguemedzang@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Agent IT', 'role' => User::ROLE_AGENT],
            ['name' => 'OSSA Charles', 'email' => 'charles.ossa@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'SIRS', 'fonction' => 'Agent IT', 'role' => User::ROLE_AGENT],
            ['name' => 'YEMBI Marvin', 'email' => 'marvin.yembi@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Chef service', 'role' => User::ROLE_SERVICE],
            ['name' => 'MBOUMBA Noelle', 'email' => 'noelle.mboumba@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Responsable communication', 'role' => User::ROLE_SERVICE],
            ['name' => 'MBAZOO Christel', 'email' => 'christel.mbazoo@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Charge communication', 'role' => User::ROLE_AGENT],
            ['name' => 'NGOMA Lucrecia', 'email' => 'lucrecia.ngoma@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Charge communication', 'role' => User::ROLE_AGENT],
            ['name' => 'OSSI Hans', 'email' => 'hans.ossi@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'CRP', 'fonction' => 'Charge communication', 'role' => User::ROLE_AGENT],
            ['name' => 'KOMBA Staelle', 'email' => 'staelle.komba@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'GDS', 'fonction' => 'Chef service', 'role' => User::ROLE_SERVICE],
            ['name' => 'LEYIGUI Wilfried', 'email' => 'wilfried.leyigui@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'GDS', 'fonction' => 'Archiviste', 'role' => User::ROLE_AGENT],
            ['name' => 'MAGNET Clovis', 'email' => 'clovis.magnet@anbg.ga', 'direction_code' => 'DSIC', 'service_code' => 'GDS', 'fonction' => 'Archiviste', 'role' => User::ROLE_AGENT],

            ['name' => 'A completer', 'email' => 'directeur.daf@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'DIRECTION', 'fonction' => 'Directeur Administratif et Financier', 'role' => User::ROLE_DIRECTION],
            ['name' => 'MATTEYA Aicha', 'email' => 'aicha.matteya@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AJARH', 'fonction' => 'Chef service', 'role' => User::ROLE_SERVICE],
            ['name' => 'ENGONE Charles', 'email' => 'charles.engone@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AJARH', 'fonction' => 'Responsable RH', 'role' => User::ROLE_SERVICE],
            ['name' => 'INDENGUELA Aldrich', 'email' => 'aldrich.indenguela@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AJARH', 'fonction' => 'Assistant juridique', 'role' => User::ROLE_AGENT],
            ['name' => 'NGOMBI Charlaine', 'email' => 'charlaine.ngombi@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AJARH', 'fonction' => 'Responsable juridique', 'role' => User::ROLE_SERVICE],
            ['name' => 'EKOMI Robert', 'email' => 'robert.ekomi@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Chef service', 'role' => User::ROLE_SERVICE],
            ['name' => 'ABOGO Melissa', 'email' => 'melissa.abogo@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Gestionnaire bourse', 'role' => User::ROLE_AGENT],
            ['name' => 'MADIBA Herbert', 'email' => 'herbert.madiba@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'MAPAGA Yannis', 'email' => 'yannis.mapaga@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'MOUALOUANGO Molan', 'email' => 'molan.moualouango@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'MOUEBI Yannick', 'email' => 'yannick.mouebi@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'MOUKONGO Candy', 'email' => 'candy.moukongo@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'MOULOUNGUI Audrey', 'email' => 'audrey.mouloungui@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Suivi administratif', 'role' => User::ROLE_SERVICE],
            ['name' => 'NDAKISSA Tassiana', 'email' => 'tassiana.ndakissa@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'SFC', 'fonction' => 'Gestionnaire', 'role' => User::ROLE_AGENT],
            ['name' => 'EYEANG Sonia', 'email' => 'sonia.eyeang@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Chef service', 'role' => User::ROLE_SERVICE],
            ['name' => 'NGWELEH Igor', 'email' => 'igor.ngweleh@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Transport', 'role' => User::ROLE_AGENT],
            ['name' => 'MIKODJI Casimir', 'email' => 'casimir.mikodji@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Assistant admin', 'role' => User::ROLE_AGENT],
            ['name' => 'MIKOLO Ulrich', 'email' => 'ulrich.mikolo@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Responsable voyage', 'role' => User::ROLE_SERVICE],
            ['name' => 'NGALIBAMA Grasmy', 'email' => 'grasmy.ngalibama@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Agent', 'role' => User::ROLE_AGENT],
            ['name' => 'BINGOUMA Ines', 'email' => 'ines.bingouma@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Logistique', 'role' => User::ROLE_SERVICE],
            ['name' => 'AFFANE Saturnin', 'email' => 'saturnin.affane@anbg.ga', 'direction_code' => 'DAF', 'service_code' => 'AMG', 'fonction' => 'Parc auto', 'role' => User::ROLE_SERVICE],
        ];
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
