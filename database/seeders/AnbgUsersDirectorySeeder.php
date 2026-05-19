<?php

namespace Database\Seeders;

use App\Models\Direction;
use App\Models\Service;
use App\Models\UniteDg;
use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Annuaire ANBG : tous les utilisateurs avec leur rôle applicatif,
 * leur rattachement organisationnel (direction / service / unité DG)
 * et leur fonction (poste).
 *
 * Idempotent : peut être relancé sans dupliquer (updateOrCreate sur email).
 * Les mots de passe existants ne sont pas écrasés si le compte existe déjà.
 *
 * À lancer manuellement quand on veut ré-injecter le directory :
 *   php artisan db:seed --class=AnbgUsersDirectorySeeder
 */
class AnbgUsersDirectorySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Mot de passe initial appliqué uniquement à la création.
     * Les utilisateurs sont invités à le changer au 1er login (politique 90 j).
     */
    private const DEFAULT_PASSWORD = 'Anbg-Init@2026';

    public function run(): void
    {
        $directions = Direction::query()->pluck('id', 'code');
        $services = Service::query()->get(['id', 'code', 'direction_id'])
            ->keyBy(fn (Service $s): string => $s->direction_id.':'.$s->code);
        $unites = UniteDg::query()->pluck('id', 'code');

        $resolveDir = fn (?string $code): ?int => $code ? ($directions[$code] ?? null) : null;
        $resolveSrv = function (?string $dirCode, ?string $srvCode) use ($directions, $services): ?int {
            if (! $dirCode || ! $srvCode) return null;
            $dirId = $directions[$dirCode] ?? null;
            if (! $dirId) return null;
            return $services[$dirId.':'.$srvCode]?->id;
        };
        $resolveUnite = fn (?string $code): ?int => $code ? ($unites[$code] ?? null) : null;

        foreach ($this->directory() as $rawRow) {
            $row = $this->normalizeDirectoryRow($rawRow);
            $directionId = $resolveDir($row['dir'] ?? null);
            $serviceId = $resolveSrv($row['dir'] ?? null, $row['service'] ?? null);
            $uniteDgId = $resolveUnite($row['unite'] ?? null);

            $isAgent = ($row['role'] === User::ROLE_AGENT);

            $existing = User::query()->where('email', $row['email'])->first();

            $payload = [
                'name' => $row['name'],
                'role' => $row['role'],
                'direction_id' => $directionId,
                'service_id' => $serviceId,
                'unite_dg_id' => $uniteDgId,
                'agent_fonction' => $row['poste'],
                'agent_matricule' => $row['matricule'] ?? null,
                'agent_telephone' => $row['telephone'] ?? null,
                'is_agent' => $isAgent,
                'is_active' => true,
            ];

            if ($existing) {
                // Compte existant : on met à jour le rattachement et le poste, on ne touche pas au mot de passe.
                $existing->forceFill($payload)->save();
            } else {
                User::query()->create(array_merge($payload, [
                    'email' => $row['email'],
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
                    'password_changed_at' => now(),
                ]));
            }
        }
    }

    /**
     * @return list<array{
     *   name:string, email:string, role:string, dir:?string,
     *   service:?string, unite:?string, poste:string,
     *   matricule?:string|null, telephone?:string|null
     * }>
     */
    private function directory(): array
    {
        return [
            // ── Plateforme / Administration ────────────────────────────────────
            ['name' => 'Super Administrateur PAS', 'email' => 'superadmin@anbg.ga', 'role' => User::ROLE_SUPER_ADMIN,
             'dir' => null, 'service' => null, 'unite' => null,
             'poste' => 'Super administration de la plateforme'],

            ['name' => 'Administrateur ANBG', 'email' => 'admin@anbg.ga', 'role' => User::ROLE_ADMIN,
             'dir' => null, 'service' => null, 'unite' => null,
             'poste' => 'Administration fonctionnelle de l’application'],

            // ── Direction Générale ─────────────────────────────────────────────
            ['name' => 'Ingrid', 'email' => 'ingrid@anbg.ga', 'role' => User::ROLE_DG,
             'dir' => 'DG', 'service' => null, 'unite' => null,
             'poste' => 'Directeur Général'],

            // ── Cabinet (unité CABINET) ────────────────────────────────────────
            ['name' => 'ADAN-GBLENOU Loick Eklu Gagnon', 'email' => 'loick.adan@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'ATSAKOUNA Judicael', 'email' => 'judicael.atsakouna@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'EVOUNA OBAME Serge', 'email' => 'serge.evouna@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'KANGA Monique', 'email' => 'monique.kanga@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'LEYOGHO MAYILA Clif Loic', 'email' => 'clif.leyogho@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'MABADY BEHOBE Dick-Daniel', 'email' => 'dick.mabady@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'MAYAGUI MANAMY Gilles', 'email' => 'gilles.mayagui@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'MBAZOGO ELLA Urielle', 'email' => 'urielle.mbazogo@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'MOMBO Stecy Michel', 'email' => 'stecy.mombo@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'NGUEMA Nadia Pascale', 'email' => 'nadia.nguema@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'NTSAGA LEKELE Maureen', 'email' => 'maureen.ntsaga@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],
            ['name' => 'SIBHA NZE NZONG Ornelia', 'email' => 'ornelia.sibha@anbg.ga', 'role' => User::ROLE_CABINET,
             'dir' => 'DG', 'service' => null, 'unite' => 'CABINET',
             'poste' => 'Membre Cabinet'],

            // ── Planification / SCIQ (unité SCIQ) ──────────────────────────────
            ['name' => 'ANGUE ALADE Kassirath', 'email' => 'kassirath.angue@anbg.ga', 'role' => User::ROLE_PLANIFICATION,
             'dir' => 'DG', 'service' => null, 'unite' => 'SCIQ',
             'poste' => 'Planification / SCIQ'],
            ['name' => 'DOGUI Marie Raphaelle', 'email' => 'marie.dogui@anbg.ga', 'role' => User::ROLE_PLANIFICATION,
             'dir' => 'DG', 'service' => null, 'unite' => 'SCIQ',
             'poste' => 'Planification / SCIQ'],
            ['name' => 'KOMBA Joelle', 'email' => 'joelle.komba@anbg.ga', 'role' => User::ROLE_PLANIFICATION,
             'dir' => 'DG', 'service' => null, 'unite' => 'SCIQ',
             'poste' => 'Planification / SCIQ'],
            ['name' => 'LEANDRY MBIRA', 'email' => 'leandry.mbira@anbg.ga', 'role' => User::ROLE_PLANIFICATION,
             'dir' => 'DG', 'service' => null, 'unite' => 'SCIQ',
             'poste' => 'Planification / SCIQ'],
            ['name' => 'MOUELLE MASSALA NDONGO Aaron', 'email' => 'aaron.mouelle@anbg.ga', 'role' => User::ROLE_PLANIFICATION,
             'dir' => 'DG', 'service' => null, 'unite' => 'SCIQ',
             'poste' => 'Planification / SCIQ'],
            ['name' => 'NDOUTOUME Jean Servais', 'email' => 'jean.ndoutoume@anbg.ga', 'role' => User::ROLE_PLANIFICATION,
             'dir' => 'DG', 'service' => null, 'unite' => 'SCIQ',
             'poste' => 'Planification / SCIQ'],
            ['name' => 'NGUEBET MOGOULA Hilaire', 'email' => 'hilaire.nguebet@anbg.ga', 'role' => User::ROLE_PLANIFICATION,
             'dir' => 'DG', 'service' => null, 'unite' => 'SCIQ',
             'poste' => 'Planification / SCIQ'],
            ['name' => 'NTSITSIGUI Yannis', 'email' => 'yannis.ntsitsigui@anbg.ga', 'role' => User::ROLE_PLANIFICATION,
             'dir' => 'DG', 'service' => null, 'unite' => 'SCIQ',
             'poste' => 'Planification / SCIQ'],

            // ── Direction DAF (Administrative et Financière) ───────────────────
            ['name' => 'Directeur DAF', 'email' => 'directeur.daf@anbg.ga', 'role' => User::ROLE_DIRECTION,
             'dir' => 'DAF', 'service' => null, 'unite' => null,
             'poste' => 'Directeur DAF'],

            // Chefs de service DAF
            ['name' => 'AFFANE Saturnin', 'email' => 'saturnin.affane@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'AMG', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'BINGOUMA Ines', 'email' => 'ines.bingouma@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'AMG', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'EKOMI Robert', 'email' => 'robert.ekomi@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'ENGONE Charles', 'email' => 'charles.engone@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'AJARH', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'EYEANG Sonia', 'email' => 'sonia.eyeang@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'AMG', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'MATTEYA Aicha', 'email' => 'aicha.matteya@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'AJARH', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'MIKOLO Ulrich', 'email' => 'ulrich.mikolo@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'AMG', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'MOULOUNGUI Audrey', 'email' => 'audrey.mouloungui@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'NGOMBI Charlaine', 'email' => 'charlaine.ngombi@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DAF', 'service' => 'AJARH', 'unite' => null, 'poste' => 'Chef de service'],

            // Agents DAF
            ['name' => 'ABOGO Melissa', 'email' => 'melissa.abogo@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'INDENGUELA Aldrich', 'email' => 'aldrich.indenguela@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'AJARH', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MADIBA Herbert', 'email' => 'herbert.madiba@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MAPAGA Yannis', 'email' => 'yannis.mapaga@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MIKODJI Casimir', 'email' => 'casimir.mikodji@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'AMG', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MOUALOUANGO Molan', 'email' => 'molan.moualouango@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MOUEBI Yannick', 'email' => 'yannick.mouebi@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MOUKONGO Candy', 'email' => 'candy.moukongo@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'NDAKISSA Tassiana', 'email' => 'tassiana.ndakissa@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'SFC', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'NGALIBAMA Grasmy', 'email' => 'grasmy.ngalibama@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'AMG', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'NGWELEH Igor', 'email' => 'igor.ngweleh@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DAF', 'service' => 'AMG', 'unite' => null, 'poste' => 'Agent'],

            // ── Direction DS (Etudes Bourses) ──────────────────────────────────
            ['name' => 'Directeur DS', 'email' => 'directeur.ds@anbg.ga', 'role' => User::ROLE_DIRECTION,
             'dir' => 'DS', 'service' => null, 'unite' => null, 'poste' => 'Directeur DS'],

            // Chefs de service DS
            ['name' => 'MAGNANGANI ERIMI Belinda', 'email' => 'belinda.magnangani@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'MENOUETON Codjo', 'email' => 'codjo.menoueton@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'SIMBA Marie Louise', 'email' => 'marie.simba@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DS', 'service' => 'ENB', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'TOUNGOU Carine', 'email' => 'carine.toungou@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Chef de service'],

            // Agents DS
            ['name' => 'AZIZET AKEWA Claude', 'email' => 'claude.azizet@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'BISSONGUI MAKITA Raissa', 'email' => 'raissa.bissongui@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'LEKA Noeline', 'email' => 'noeline.leka@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'LEMBA Sigrid', 'email' => 'sigrid.lemba@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MAKANGOU Zita', 'email' => 'zita.makangou@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MAVIOGA Leger', 'email' => 'leger.mavioga@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MENDOME Nadine', 'email' => 'nadine.mendome@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MOUKAGNI Estelle', 'email' => 'estelle.moukagni@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'NGOUBILI Lidy', 'email' => 'lidy.ngoubili@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'NKOURA Charly', 'email' => 'charly.nkoura@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'NYANGUI Sandrine', 'email' => 'sandrine.nyangui@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'SIMBOU Laurencienne', 'email' => 'laurencienne.simbou@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DS', 'service' => 'EB', 'unite' => null, 'poste' => 'Agent'],

            // ── Direction DSIC (Systèmes d'Information et Communication) ──────
            ['name' => 'Directeur DSIC', 'email' => 'directeur.dsic@anbg.ga', 'role' => User::ROLE_DIRECTION,
             'dir' => 'DSIC', 'service' => null, 'unite' => null, 'poste' => 'Directeur DSIC'],

            // Chefs de service DSIC
            ['name' => 'Arnold MINDZELI', 'email' => 'arnold.mindzeli@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DSIC', 'service' => 'SIRS', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'KOMBA Staelle', 'email' => 'staelle.komba@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DSIC', 'service' => 'GDS', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'MBOUMBA Noelle', 'email' => 'noelle.mboumba@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DSIC', 'service' => 'CRP', 'unite' => null, 'poste' => 'Chef de service'],
            ['name' => 'YEMBI Marvin', 'email' => 'marvin.yembi@anbg.ga', 'role' => User::ROLE_SERVICE,
             'dir' => 'DSIC', 'service' => 'CRP', 'unite' => null, 'poste' => 'Chef de service'],

            // Agents DSIC
            ['name' => 'CAMARA Francois', 'email' => 'francois.camara@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'SIRS', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'EBINDA Roger', 'email' => 'roger.ebinda@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'SIRS', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'LEYIGUI Wilfried', 'email' => 'wilfried.leyigui@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'GDS', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MAGNET Clovis', 'email' => 'clovis.magnet@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'GDS', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'MBAZOO Christel', 'email' => 'christel.mbazoo@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'CRP', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'NGOMA Lucrecia', 'email' => 'lucrecia.ngoma@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'CRP', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'NGUEMEDZANG Renaud', 'email' => 'renaud.nguemedzang@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'SIRS', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'OSSA Charles', 'email' => 'charles.ossa@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'SIRS', 'unite' => null, 'poste' => 'Agent'],
            ['name' => 'OSSI Hans', 'email' => 'hans.ossi@anbg.ga', 'role' => User::ROLE_AGENT,
             'dir' => 'DSIC', 'service' => 'CRP', 'unite' => null, 'poste' => 'Agent'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDirectoryRow(array $row): array
    {
        if (($row['role'] ?? null) === User::ROLE_CABINET) {
            $row['role'] = User::ROLE_COLLABORATEUR;
            $row['dir'] = 'DG';
            $row['service'] = 'COLLAB';
            $row['unite'] = UniteDg::CODE_CABINET;
        }

        if (($row['role'] ?? null) === User::ROLE_PLANIFICATION) {
            $row['dir'] = 'DS';
            $row['service'] = 'PLANIF';
            $row['unite'] = null;
            $row['poste'] = 'Planification';
        }

        if (($row['dir'] ?? null) === 'DS' && ($row['service'] ?? null) === 'EB') {
            $row['service'] = 'EN';
        }

        return $row;
    }
}
