<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
            $sheetPath = $this->sheetPathByName($zip, 'Base_Import');
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

                $directionCode = $this->directionCodeFromWorkbook($this->cell($cells, 9));
                $serviceCode = $this->serviceCodeFromWorkbook($this->cell($cells, 10));

                $rows[] = [
                    'matricule' => '',
                    'name' => $this->cell($cells, 3),
                    'email' => $email,
                    'direction_code' => $directionCode,
                    'service_code' => $serviceCode,
                    'fonction' => $this->cell($cells, 7),
                    'role' => $this->roleFromWorkbook($this->cell($cells, 14), $serviceCode),
                ];
            }

            if ($rows === []) {
                throw new RuntimeException(sprintf('Aucun utilisateur importable trouve dans %s', $path));
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function workbookPath(): string
    {
        $path = function_exists('base_path')
            ? base_path(self::USERS_WORKBOOK)
            : dirname(__DIR__, 2).DIRECTORY_SEPARATOR.self::USERS_WORKBOOK;

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
            $text = '';
            foreach ($item->xpath('.//x:t') ?: [] as $part) {
                $text .= (string) $part;
            }
            $sharedStrings[] = $text;
        }

        return $sharedStrings;
    }

    private function sheetPathByName(ZipArchive $zip, string $sheetName): string
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
        return match ($direction) {
            'Cabinet du DG' => 'DG',
            'DAF', 'DS', 'DSIC' => $direction,
            default => '',
        };
    }

    private function serviceCodeFromWorkbook(string $service): ?string
    {
        return match ($service) {
            '', 'Administration', 'Direction Générale', 'DIRECTION' => null,
            'Collaborateurs' => 'COLLAB',
            'Planification' => 'PLANIF',
            default => $service,
        };
    }

    private function isReadyForImport(string $status): bool
    {
        return $status === 'Prêt pour import'
            || str_starts_with($status, 'Valide');
    }

    private function roleFromWorkbook(string $roleSlug, ?string $serviceCode): string
    {
        return match ($roleSlug) {
            'admin' => User::ROLE_ADMIN,
            'super_admin' => User::ROLE_SUPER_ADMIN,
            'dg' => User::ROLE_DG,
            'directeur' => User::ROLE_DIRECTION,
            'chef_service' => User::ROLE_SERVICE,
            'chef_unite' => match ($serviceCode) {
                'UCAS' => User::ROLE_CHEF_UNITE_UCAS,
                'SCIQ' => User::ROLE_CHEF_UNITE_SCIQ,
                default => User::ROLE_CHEF_UNITE,
            },
            'collaborateur' => User::ROLE_COLLABORATEUR,
            'sciq' => User::ROLE_SCIQ,
            'ucas' => User::ROLE_UCAS,
            'planification' => User::ROLE_PLANIFICATION,
            default => User::ROLE_AGENT,
        };
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
            User::ROLE_ADMIN => 'ADM',
            User::ROLE_DG => 'DG',
            User::ROLE_CABINET => 'CAB',
            User::ROLE_COLLABORATEUR => 'COL',
            User::ROLE_SCIQ => 'SCIQ',
            User::ROLE_UCAS => 'UCAS',
            User::ROLE_PLANIFICATION => 'PLA',
            User::ROLE_CHEF_UNITE => 'CHU',
            User::ROLE_CHEF_UNITE_SCIQ => 'SCIQ',
            User::ROLE_CHEF_UNITE_UCAS => 'UCAS',
            User::ROLE_DIRECTION => 'DIR',
            User::ROLE_SERVICE => 'SRV',
            User::ROLE_AGENT => 'AGT',
            default => 'USR',
        };

        return sprintf('%s-%03d', $prefix, $sequence);
    }
}
