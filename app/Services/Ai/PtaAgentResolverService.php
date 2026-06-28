<?php

namespace App\Services\Ai;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class PtaAgentResolverService
{
    /**
     * @var list<array<string,mixed>>|null
     */
    private ?array $agents = null;

    /**
     * @return list<array<string,mixed>>
     */
    public function agents(?string $path = null): array
    {
        if ($this->agents !== null && $path === null) {
            return $this->agents;
        }

        $path = $this->referencePath($path);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('A_UTILISER_IMPORT');

        if (! $sheet instanceof Worksheet) {
            throw new RuntimeException('La feuille A_UTILISER_IMPORT est introuvable dans le referentiel agents.');
        }

        $agents = $this->rowsFromSheet($sheet);
        if ($path === (string) config('ai_training.pta.agent_reference_path')) {
            $this->agents = $agents;
        }

        return $agents;
    }

    /**
     * @return array{valid:list<string>,invalid:list<string>}
     */
    public function verifyCodes(string|array|null $codes): array
    {
        $known = collect($this->agents())->pluck('code_agent')->map(
            static fn (mixed $code): string => strtoupper(trim((string) $code))
        )->filter()->values()->all();

        $valid = [];
        $invalid = [];
        foreach ($this->splitTokens($codes) as $code) {
            $normalized = strtoupper($code);
            if (in_array($normalized, $known, true)) {
                $valid[] = $normalized;
            } else {
                $invalid[] = $code;
            }
        }

        return ['valid' => array_values(array_unique($valid)), 'invalid' => array_values(array_unique($invalid))];
    }

    /**
     * @return array{codes:list<string>,unresolved:list<string>,matches:list<array<string,mixed>>}
     */
    public function resolve(string|array|null $raw, ?string $direction = null, ?string $service = null): array
    {
        $codes = [];
        $unresolved = [];
        $matches = [];

        foreach ($this->splitTokens($raw) as $token) {
            $codeCheck = $this->verifyCodes($token);
            if ($codeCheck['valid'] !== []) {
                $codes = array_merge($codes, $codeCheck['valid']);
                $matches[] = ['token' => $token, 'match_type' => 'code', 'code_agent' => $codeCheck['valid'][0]];

                continue;
            }

            $match = $this->findAgentByToken($token, $direction, $service);
            if ($match === null) {
                $unresolved[] = $token;

                continue;
            }

            $code = strtoupper(trim((string) $match['code_agent']));
            $codes[] = $code;
            $matches[] = [
                'token' => $token,
                'match_type' => 'referentiel',
                'code_agent' => $code,
                'nom_complet' => $match['nom_complet'] ?? null,
                'email' => $match['email'] ?? null,
            ];
        }

        return [
            'codes' => array_values(array_unique(array_filter($codes))),
            'unresolved' => array_values(array_unique($unresolved)),
            'matches' => $matches,
        ];
    }

    public function codesToString(array $codes): string
    {
        return implode(';', array_values(array_unique(array_filter(array_map(
            static fn (mixed $code): string => strtoupper(trim((string) $code)),
            $codes
        )))));
    }

    public function referencePath(?string $path = null): string
    {
        $path ??= (string) config('ai_training.pta.agent_reference_path');

        if (! is_file($path)) {
            throw new RuntimeException('Referentiel agents introuvable : '.$path);
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function splitTokens(string|array|null $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_array($raw)) {
            $parts = Arr::flatten($raw);
        } else {
            $text = str_replace(["\r\n", "\n", '|', ',', '/'], ';', $raw);
            $text = preg_replace('/\s+(?:et|&)\s+/iu', ';', $text) ?? $text;
            $parts = explode(';', $text);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $parts
        )));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findAgentByToken(string $token, ?string $direction, ?string $service): ?array
    {
        $needle = $this->key($token);
        if ($needle === '') {
            return null;
        }

        $candidates = collect($this->agents())->filter(function (array $agent) use ($needle): bool {
            return $this->key((string) ($agent['nom_complet'] ?? '')) === $needle
                || $this->key((string) ($agent['email'] ?? '')) === $needle
                || $this->key((string) ($agent['fonction'] ?? '')) === $needle;
        });

        if ($candidates->isEmpty()) {
            $candidates = collect($this->agents())->filter(function (array $agent) use ($needle): bool {
                $haystack = implode(' ', [
                    $agent['nom_complet'] ?? '',
                    $agent['email'] ?? '',
                    $agent['fonction'] ?? '',
                ]);

                return Str::contains($this->key($haystack), $needle);
            });
        }

        if ($direction !== null || $service !== null) {
            $scoped = $candidates->filter(function (array $agent) use ($direction, $service): bool {
                $directionMatches = $direction === null || $this->key((string) ($agent['direction'] ?? '')) === $this->key($direction);
                $serviceMatches = $service === null || $this->key((string) ($agent['service'] ?? '')) === $this->key($service);

                return $directionMatches || $serviceMatches;
            });

            if ($scoped->isNotEmpty()) {
                $candidates = $scoped;
            }
        }

        $match = $candidates->first();

        return is_array($match) ? $match : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rowsFromSheet(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $headers = array_map(
            static fn (mixed $value): string => trim((string) $value),
            Arr::flatten($sheet->rangeToArray('A1:'.$highestColumn.'1', null, true, false))
        );

        $rows = [];
        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $values = Arr::flatten($sheet->rangeToArray('A'.$rowNumber.':'.$highestColumn.$rowNumber, null, true, false));
            if (collect($values)->every(static fn (mixed $value): bool => trim((string) $value) === '')) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $values[$index] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function key(string $value): string
    {
        $value = strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^a-z0-9@.]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
