<?php

namespace App\Services\Imports;

use App\Models\Direction;
use App\Models\Service;
use Illuminate\Support\Str;

class PlanningImportCodeGenerator
{
    public function pas(int $start, int $end): string
    {
        return "PAS-{$start}-{$end}";
    }

    public function axe(string $pasCode, int $order): string
    {
        return $pasCode.'-AXE-'.str_pad((string) $order, 2, '0', STR_PAD_LEFT);
    }

    public function strategicObjective(string $axeCode, int $order): string
    {
        return $axeCode.'-OS-'.str_pad((string) $order, 2, '0', STR_PAD_LEFT);
    }

    public function pao(Direction $direction, int $year): string
    {
        return 'PAO-'.$this->token((string) ($direction->code ?: $direction->libelle)).'-'.$year;
    }

    public function operationalObjective(Direction $direction, int $year, Service $service, int $order): string
    {
        return $this->pao($direction, $year)
            .'-'.$this->token((string) ($service->code ?: $service->libelle))
            .'-OO-'.str_pad((string) $order, 3, '0', STR_PAD_LEFT);
    }

    public function pta(Service $service, int $year): string
    {
        return 'PTA-'.$this->token((string) ($service->code ?: $service->libelle)).'-'.$year;
    }

    public function action(Service $service, int $year, int $objectiveOrder, int $order): string
    {
        return 'ACT-'.$this->token((string) ($service->code ?: $service->libelle))
            .'-'.$year
            .'-'.str_pad((string) $objectiveOrder, 3, '0', STR_PAD_LEFT)
            .'-'.str_pad((string) $order, 3, '0', STR_PAD_LEFT);
    }

    private function token(string $value): string
    {
        $token = Str::of($value)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->toString();

        return $token !== '' ? $token : 'NA';
    }
}
