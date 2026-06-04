<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Security\PasswordPolicyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class AnbgOrganizationSeeder extends Seeder
{
    private const USERS_WORKBOOK = 'docs/base_utilisateurs_pas_anbg_refaite_nouvelle_logique.xlsx';

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $workbookUsers = null;

    /**
     * Credentials temporaires genres pour les nouveaux comptes : on les expose
     * a la fin du run pour que l administrateur puisse les distribuer. La
     * `password_changed_at = null` qu on inscrit en base force le renouvellement
     * au premier login (cf. A08 + PasswordPolicyService::isExpired).
     *
     * @var array<int, array{email:string, name:string, matricule:?string, temporary_password:string}>
     */
    private array $generatedCredentials = [];

    public function run(): void
    {
        $now = now();
        $passwordPolicy = app(PasswordPolicyService::class);

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
                $this->servicePayload($service, $now)
            );
        }

        $serviceIds = DB::table('services')
            ->join('directions', 'directions.id', '=', 'services.direction_id')
            ->whereIn('directions.code', array_keys($directionIds))
            ->get(['directions.code as direction_code', 'services.code', 'services.id'])
            ->mapWithKeys(static fn ($row): array => [
                (string) $row->direction_code.'.'.(string) $row->code => (int) $row->id,
            ])
            ->all();

        foreach ($this->users() as $index => $rawUser) {
            $user = $this->normalizeUserOrganization($rawUser);
            $directionId = $directionIds[$user['direction_code']] ?? null;
            $serviceId = null;

            if ($user['service_code'] !== null) {
                $serviceId = $serviceIds[$user['direction_code'].'.'.$user['service_code']] ?? null;
            }

            $email = $this->normalizeOrganizationEmail((string) $user['email']);
            $matricule = $this->resolveMatricule($user, $index + 1);
            $existing = DB::table('users')->where('email', $email)->first(['id', 'password']);

            // A08 — Pour un nouveau user on genere un mot de passe aleatoire
            // conforme a la policy + password_changed_at NULL (force renouvellement
            // au 1er login). Un user existant garde son mdp et son timestamp.
            //
            // En environnement de tests on conserve le mdp fixture `Pass@12345`
            // avec password_changed_at=now() pour que les tests qui se loggent
            // sur les comptes seedes (PlanningApiTest, etc.) continuent a marcher.
            if ($existing === null) {
                if (app()->environment('testing')) {
                    $temporaryPassword = 'Pass@12345';
                    $passwordChangedAt = $now;
                } else {
                    $temporaryPassword = $passwordPolicy->generateInitialPassword();
                    $passwordChangedAt = null;

                    $this->generatedCredentials[] = [
                        'email' => $email,
                        'name' => (string) $user['name'],
                        'matricule' => $matricule,
                        'temporary_password' => $temporaryPassword,
                    ];
                }

                $hashedPassword = Hash::make($temporaryPassword);
            } else {
                $hashedPassword = (string) $existing->password;
                $passwordChangedAt = $now;
            }

            DB::table('users')->updateOrInsert(
                ['email' => $email],
                [
                    'name' => $user['name'],
                    'password' => $hashedPassword,
                    'role' => $user['role'],
                    'is_agent' => $user['role'] === User::ROLE_AGENT,
                    'agent_matricule' => $matricule,
                    'agent_fonction' => $user['fonction'],
                    'agent_telephone' => null,
                    'direction_id' => $directionId,
                    'service_id' => $serviceId,
                    'email_verified_at' => $now,
                    'password_changed_at' => $passwordChangedAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->deleteLegacyOrganizationEntries($now);

        $this->reportGeneratedCredentials();
    }

    /**
     * Affiche dans la console les mots de passe temporaires des comptes crees
     * pendant ce run. L admin doit imperativement les transmettre (mail, papier
     * scelle) puis les effacer du terminal. Le password_changed_at = NULL en
     * base force le user a changer son mdp au premier login.
     */
    protected function reportGeneratedCredentials(): void
    {
        if ($this->generatedCredentials === []) {
            return;
        }

        $command = $this->command ?? null;
        if ($command === null) {
            return;
        }

        $command->newLine();
        $command->warn('A08 — '.count($this->generatedCredentials).' compte(s) cree(s) avec un mot de passe temporaire :');
        $command->warn('A transmettre par un canal sur, puis a renouveler au 1er login.');
        $command->newLine();

        $command->table(
            ['Email', 'Nom', 'Matricule', 'Mot de passe temporaire'],
            array_map(
                static fn (array $row): array => [
                    $row['email'],
                    $row['name'],
                    $row['matricule'] ?? '-',
                    $row['temporary_password'],
                ],
                $this->generatedCredentials
            )
        );
    }

    /**
     * @return array<int, array{code:string, libelle:string}>
     */
    protected function directions(): array
    {
        return [
            ['code' => 'DG', 'libelle' => 'Cabinet du DG'],
            ['code' => 'DSIC', 'libelle' => 'DSIC'],
            ['code' => 'DAF', 'libelle' => 'DAF'],
            ['code' => 'DS', 'libelle' => 'DS'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function services(): array
    {
        return [
            ['direction_code' => 'DG', 'code' => 'UCAS', 'libelle' => 'UCAS', 'type' => 'cabinet_unit', 'is_operational' => true],
            ['direction_code' => 'DG', 'code' => 'SCIQ', 'libelle' => 'SCIQ', 'type' => 'cabinet_unit', 'has_global_view' => true, 'has_global_write' => true, 'has_dual_interface' => true, 'is_control_unit' => true, 'is_operational' => true],
            ['direction_code' => 'DG', 'code' => 'COLLAB', 'libelle' => 'Collaborateurs', 'type' => 'cabinet_unit', 'has_global_view' => true, 'has_dual_interface' => true, 'is_operational' => true],

            ['direction_code' => 'DSIC', 'code' => 'SIRS', 'libelle' => 'SIRS', 'type' => 'operational_service', 'is_operational' => true],
            ['direction_code' => 'DSIC', 'code' => 'CRP', 'libelle' => 'CRP', 'type' => 'operational_service', 'is_operational' => true],
            ['direction_code' => 'DSIC', 'code' => 'GDS', 'libelle' => 'GDS', 'type' => 'operational_service', 'is_operational' => true],

            ['direction_code' => 'DAF', 'code' => 'AJARH', 'libelle' => 'AJARH', 'type' => 'operational_service', 'is_operational' => true],
            ['direction_code' => 'DAF', 'code' => 'AMG', 'libelle' => 'AMG', 'type' => 'operational_service', 'is_operational' => true],
            ['direction_code' => 'DAF', 'code' => 'SFC', 'libelle' => 'SFC', 'type' => 'operational_service', 'is_operational' => true],

            ['direction_code' => 'DS', 'code' => 'EB', 'libelle' => 'EB', 'type' => 'operational_service', 'is_operational' => true],
            ['direction_code' => 'DS', 'code' => 'ENB', 'libelle' => 'ENB', 'type' => 'operational_service', 'is_operational' => true],
            ['direction_code' => 'DS', 'code' => 'PLANIF', 'libelle' => 'Planification', 'type' => 'operational_service', 'has_global_view' => true, 'has_global_write' => true, 'has_dual_interface' => true, 'is_control_unit' => true, 'is_operational' => true],
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
        return $this->workbookUsers ??= $this->loadUsersFromWorkbook();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadUsersFromWorkbook(): array
    {
        $path = $this->workbookPath();
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException(sprintf('Impossible d ouvrir le classeur utilisateurs: %s', $path));
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $baseImportSheet = $this->sheetPathByName($zip, 'Base_Import', false);
            if ($baseImportSheet !== null) {
                return $this->loadUsersFromBaseImportSheet($zip, $baseImportSheet, $sharedStrings, $path);
            }

            $newUsersSheet = $this->sheetPathByName($zip, 'Nouveaux_Utilisateurs', false);
            if ($newUsersSheet !== null) {
                return $this->loadUsersFromNewUsersSheet($zip, $newUsersSheet, $sharedStrings, $path);
            }

            throw new RuntimeException(sprintf(
                'Aucun onglet utilisateurs compatible trouve dans %s. Onglets attendus: Base_Import ou Nouveaux_Utilisateurs.',
                $path
            ));
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<string, mixed>>
     */
    private function loadUsersFromBaseImportSheet(ZipArchive $zip, string $sheetPath, array $sharedStrings, string $path): array
    {
        $sheetXml = $zip->getFromName($sheetPath);

        if ($sheetXml === false) {
            throw new RuntimeException(sprintf('Feuille Base_Import introuvable dans %s', $path));
        }

        $rows = [];
        $sheet = new SimpleXMLElement($sheetXml);
        $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        foreach ($sheet->xpath('//x:sheetData/x:row') ?: [] as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);
            if ($rowNumber < 5) {
                continue;
            }

            $cells = $this->readRowCells($row, $sharedStrings);
            $email = strtolower($this->cell($cells, 4));

            if (
                $email === ''
                || $this->cell($cells, 19) !== 'Oui'
                || ! $this->isReadyForImport($this->cell($cells, 18))
            ) {
                continue;
            }

            $fonction = $this->cell($cells, 7);
            $directionCode = $this->directionCodeFromWorkbook($this->cell($cells, 9));
            $serviceCode = $this->serviceCodeFromWorkbook($this->cell($cells, 10));

            $rows[] = [
                'matricule' => '',
                'name' => $this->userNameFromWorkbook($this->cell($cells, 3), $email, $fonction),
                'email' => $email,
                'direction_code' => $directionCode,
                'service_code' => $serviceCode,
                'fonction' => $fonction,
                'role' => $this->roleFromWorkbook($this->cell($cells, 14), $serviceCode),
            ];
        }

        if ($rows === []) {
            throw new RuntimeException(sprintf('Aucun utilisateur importable trouve dans %s', $path));
        }

        return $rows;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<string, mixed>>
     */
    private function loadUsersFromNewUsersSheet(ZipArchive $zip, string $sheetPath, array $sharedStrings, string $path): array
    {
        $sheetXml = $zip->getFromName($sheetPath);

        if ($sheetXml === false) {
            throw new RuntimeException(sprintf('Feuille Nouveaux_Utilisateurs introuvable dans %s', $path));
        }

        $rows = [];
        $sheet = new SimpleXMLElement($sheetXml);
        $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        foreach ($sheet->xpath('//x:sheetData/x:row') ?: [] as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);
            if ($rowNumber < 5) {
                continue;
            }

            $cells = $this->readRowCells($row, $sharedStrings);
            $email = strtolower($this->cell($cells, 2));
            if ($email === '') {
                continue;
            }

            $directionLabel = $this->cell($cells, 3);
            $serviceLabel = $this->cell($cells, 4);
            $fonction = $this->cell($cells, 5);
            $name = $this->userNameFromWorkbook($this->cell($cells, 1), $email, $fonction);
            $serviceCode = $this->serviceCodeFromWorkbook($serviceLabel);
            $directionCode = $this->directionCodeFromWorkbook($directionLabel);
            $role = $this->roleFromWorkbook($this->cell($cells, 6), $serviceCode, $directionCode, $fonction, $directionLabel);

            if ($directionCode === '' && ! in_array($role, [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_FONCTIONNEL], true)) {
                continue;
            }

            $rows[] = [
                'matricule' => '',
                'name' => $name,
                'email' => $email,
                'direction_code' => $directionCode,
                'service_code' => $serviceCode,
                'fonction' => $fonction,
                'role' => $role,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException(sprintf('Aucun utilisateur importable trouve dans %s', $path));
        }

        return $rows;
    }

    private function workbookPath(): string
    {
        $override = env('ANBG_USERS_WORKBOOK');
        if (is_string($override) && trim($override) !== '') {
            $override = trim($override);
            $path = preg_match('/^[A-Za-z]:[\\\\\\/]/', $override) === 1 || str_starts_with($override, DIRECTORY_SEPARATOR)
                ? $override
                : (function_exists('base_path') ? base_path($override) : dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$override);
        } else {
            $path = function_exists('base_path')
                ? base_path(self::USERS_WORKBOOK)
                : dirname(__DIR__, 2).DIRECTORY_SEPARATOR.self::USERS_WORKBOOK;
        }

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Classeur utilisateurs introuvable: %s', $path));
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $sharedStrings = [];
        $sharedXml = new SimpleXMLElement($xml);
        $sharedXml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        foreach ($sharedXml->xpath('//x:si') ?: [] as $item) {
            // Le namespace XML doit etre reenregistre sur chaque noeud enfant
            // sinon $item->xpath('.//x:t') leve "Undefined namespace prefix".
            $item->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $text = '';
            foreach ($item->xpath('.//x:t') ?: [] as $part) {
                $text .= (string) $part;
            }
            $sharedStrings[] = $text;
        }

        return $sharedStrings;
    }

    private function sheetPathByName(ZipArchive $zip, string $sheetName, bool $required = true): ?string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Structure XLSX invalide: workbook ou relations introuvables.');
        }

        $workbook = new SimpleXMLElement($workbookXml);
        $workbook->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $relationshipId = null;
        foreach ($workbook->xpath('//x:sheet') ?: [] as $sheet) {
            if ((string) $sheet['name'] === $sheetName) {
                $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relationshipId = (string) $attributes['id'];
                break;
            }
        }

        if ($relationshipId === null) {
            if (! $required) {
                return null;
            }

            throw new RuntimeException(sprintf('Onglet "%s" introuvable dans le classeur utilisateurs.', $sheetName));
        }

        $rels = new SimpleXMLElement($relsXml);
        foreach ($rels->Relationship as $relationship) {
            if ((string) $relationship['Id'] === $relationshipId) {
                $target = ltrim((string) $relationship['Target'], '/');

                return str_starts_with($target, 'xl/')
                    ? $target
                    : 'xl/'.$target;
            }
        }

        throw new RuntimeException(sprintf('Relation XLSX introuvable pour l onglet "%s".', $sheetName));
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, string>
     */
    private function readRowCells(SimpleXMLElement $row, array $sharedStrings): array
    {
        $cells = [];
        $row->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        foreach ($row->xpath('x:c') ?: [] as $cell) {
            $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $reference = (string) $cell['r'];
            $column = $this->columnNumber(preg_replace('/\d+/', '', $reference) ?? '');
            $type = (string) $cell['t'];
            $valueNode = $cell->xpath('x:v')[0] ?? null;

            if ($valueNode === null) {
                $inlineText = '';
                foreach ($cell->xpath('.//x:t') ?: [] as $textPart) {
                    $inlineText .= (string) $textPart;
                }
                $cells[$column] = trim($inlineText);
                continue;
            }

            $rawValue = (string) $valueNode;
            $cells[$column] = trim($type === 's'
                ? ($sharedStrings[(int) $rawValue] ?? '')
                : $rawValue);
        }

        return $cells;
    }

    private function columnNumber(string $column): int
    {
        $number = 0;
        foreach (str_split(strtoupper($column)) as $character) {
            $number = ($number * 26) + (ord($character) - ord('A') + 1);
        }

        return $number;
    }

    /**
     * @param array<int, string> $cells
     */
    private function cell(array $cells, int $column): string
    {
        return trim((string) ($cells[$column] ?? ''));
    }

    private function directionCodeFromWorkbook(string $direction): string
    {
        return match ($this->normalizeWorkbookToken($direction)) {
            'cabinet_du_dg', 'direction_generale', 'dg', 'dga' => 'DG',
            'daf' => 'DAF',
            'ds' => 'DS',
            'dsic' => 'DSIC',
            default => '',
        };
    }

    private function serviceCodeFromWorkbook(string $service): ?string
    {
        return match ($this->normalizeWorkbookToken($service)) {
            '', 'administration', 'direction_generale', 'direction', 'secdga' => null,
            'collaborateurs', 'collaborateur' => 'COLLAB',
            'planification' => 'PLANIF',
            default => strtoupper(trim($service)),
        };
    }

    private function isReadyForImport(string $status): bool
    {
        return $status === 'Prêt pour import'
            || str_starts_with($status, 'Valide');
    }

    private function roleFromWorkbook(
        string $roleSlug,
        ?string $serviceCode,
        ?string $directionCode = null,
        ?string $fonction = null,
        ?string $directionLabel = null
    ): string
    {
        $role = $this->normalizeWorkbookToken($roleSlug);
        $function = $this->normalizeWorkbookToken((string) $fonction);
        $direction = $this->normalizeWorkbookToken((string) $directionLabel);

        return match ($role) {
            'admin' => User::ROLE_ADMIN_FONCTIONNEL,
            'super_admin' => User::ROLE_SUPER_ADMIN,
            'dg' => User::ROLE_DG,
            'directeur', 'direction' => $direction === 'dga' || str_contains($function, 'directeur_general_adjoint')
                ? User::ROLE_DGA_SUPERVISION
                : User::ROLE_DIRECTION,
            'chef_service', 'service' => User::ROLE_SERVICE,
            'chef_planification', 'chef_de_planification' => User::ROLE_CHEF_PLANIFICATION,
            'chef_unite_sciq', 'chef_d_unite_sciq' => User::ROLE_CHEF_UNITE_SCIQ,
            'chef_unite_ucas', 'chef_d_unite_ucas' => User::ROLE_CHEF_UNITE_UCAS,
            'chef_unite', 'chef_d_unite' => $serviceCode === 'UCAS'
                ? User::ROLE_CHEF_UNITE_UCAS
                : ($serviceCode === 'SCIQ' ? User::ROLE_CHEF_UNITE_SCIQ : User::ROLE_CHEF_UNITE_CABINET),
            'collaborateur' => $direction === 'dga' || str_contains($function, 'dga')
                ? User::ROLE_DGA_SUPERVISION
                : (str_contains($function, 'directeur_general') ? User::ROLE_DG : User::ROLE_CABINET),
            'ucas' => User::ROLE_UCAS,
            'sciq' => User::ROLE_SCIQ,
            'planification' => User::ROLE_PLANIFICATION,
            default => User::ROLE_AGENT,
        };
    }

    private function normalizeWorkbookToken(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->lower()
            ->trim()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');
    }

    private function userNameFromWorkbook(string $name, string $email, string $fonction): string
    {
        $candidate = trim($name);
        if ($candidate !== '' && $this->normalizeWorkbookToken($candidate) !== 'a_completer') {
            return $candidate;
        }

        $fallback = trim($fonction);
        if ($fallback !== '') {
            return $fallback;
        }

        $localPart = (string) Str::of($email)
            ->before('@')
            ->replaceMatches('/[^A-Za-z0-9]+/', ' ')
            ->trim()
            ->title();

        return $localPart !== '' ? $localPart : 'Utilisateur ANBG';
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    protected function servicePayload(array $service, mixed $now): array
    {
        $payload = [
            'libelle' => $service['libelle'],
            'actif' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ([
            'type' => 'service',
            'has_global_view' => false,
            'has_global_write' => false,
            'has_dual_interface' => false,
            'is_control_unit' => false,
            'is_operational' => true,
        ] as $column => $default) {
            if (Schema::hasColumn('services', $column)) {
                $payload[$column] = $service[$column] ?? $default;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    protected function normalizeUserOrganization(array $user): array
    {
        if (in_array((string) ($user['direction_code'] ?? ''), ['DAF', 'DS', 'DSIC'], true) && ($user['service_code'] ?? null) === 'DIRECTION') {
            $user['service_code'] = null;
        }

        return $user;
    }

    /**
     * Convention d email de l organisation : {initiale_prenom}.{nom}.anbg@gmail.com.
     *
     * La donnee brute du classeur reste en `prenom.nom@anbg.ga` ; cette convention
     * est appliquee au moment du seed (source unique de la regle). Sont laisses
     * inchanges : les comptes de fonction (`directeur.*`), les emails sans
     * `prenom.nom` (ex : `ingrid`, comptes systeme) et ceux deja en `@gmail.com`.
     *
     * En environnement de tests on conserve l email brut du classeur (`@anbg.ga`)
     * pour ne pas casser les fixtures de connexion (meme logique que le mot de
     * passe `Pass@12345`).
     */
    protected function normalizeOrganizationEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if (app()->environment('testing')) {
            return $email;
        }

        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($domain === 'gmail.com'
            || ! str_contains($local, '.')
            || str_starts_with($local, 'directeur.')) {
            return $email;
        }

        $firstName = Str::before($local, '.');
        $lastName = Str::after($local, '.');

        if ($firstName === '' || $lastName === '') {
            return $email;
        }

        return $firstName[0].'.'.$lastName.'.anbg@gmail.com';
    }

    protected function deleteLegacyOrganizationEntries(mixed $now): void
    {
        $this->deactivateLegacyOrganizationEntries($now);

        return;
    }

    protected function deactivateLegacyOrganizationEntries(mixed $now): void
    {
        $officialDirectionCodes = array_column($this->directions(), 'code');
        $officialServiceCodesByDirection = collect($this->services())
            ->groupBy('direction_code')
            ->map(fn ($services) => collect($services)->pluck('code')->all())
            ->all();

        DB::table('directions')
            ->whereNotIn('code', $officialDirectionCodes)
            ->update(['actif' => false, 'updated_at' => $now]);

        $directions = DB::table('directions')
            ->whereIn('code', $officialDirectionCodes)
            ->pluck('id', 'code')
            ->mapWithKeys(static fn ($id, $code): array => [(string) $code => (int) $id])
            ->all();

        foreach ($officialServiceCodesByDirection as $directionCode => $serviceCodes) {
            $directionId = $directions[$directionCode] ?? null;
            if (! is_int($directionId)) {
                continue;
            }

            DB::table('services')
                ->where('direction_id', $directionId)
                ->whereNotIn('code', $serviceCodes)
                ->update(['actif' => false, 'updated_at' => $now]);
        }

        $activeDirectionIds = array_values($directions);
        if ($activeDirectionIds !== []) {
            DB::table('services')
                ->whereNotIn('direction_id', $activeDirectionIds)
                ->update(['actif' => false, 'updated_at' => $now]);
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
            User::ROLE_ADMIN_FONCTIONNEL => 'ADF',
            User::ROLE_DG => 'DG',
            User::ROLE_PLANIFICATION => 'PLA',
            User::ROLE_DIRECTION => 'DIR',
            User::ROLE_SERVICE => 'SRV',
            User::ROLE_AGENT => 'AGT',
            User::ROLE_AUDITEUR => 'AUD',
            default => 'USR',
        };

        return sprintf('%s-%03d', $prefix, $sequence);
    }
}
