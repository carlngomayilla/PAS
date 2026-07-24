<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AiReporting\AnnualReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAnnualReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    /**
     * @param  array<string,mixed>  $filters
     */
    public function __construct(
        public ?int $userId,
        public int $year,
        public array $filters = []
    ) {}

    public function handle(AnnualReportService $reports): void
    {
        $reports->generate($this->userId !== null ? User::query()->find($this->userId) : null, $this->year, $this->filters);
    }
}
