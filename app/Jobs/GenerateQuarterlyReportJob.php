<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AiReporting\QuarterlyReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateQuarterlyReportJob implements ShouldQueue
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
        public int $quarter,
        public array $filters = []
    ) {}

    public function handle(QuarterlyReportService $reports): void
    {
        $reports->generate($this->userId !== null ? User::query()->find($this->userId) : null, $this->year, $this->quarter, $this->filters);
    }
}
