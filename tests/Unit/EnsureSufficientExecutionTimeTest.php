<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureSufficientExecutionTime;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class EnsureSufficientExecutionTimeTest extends TestCase
{
    public function test_it_extends_a_short_web_execution_limit(): void
    {
        $originalLimit = (string) ini_get('max_execution_time');
        config(['app.web_execution_time_limit' => 120]);

        try {
            ini_set('max_execution_time', '30');

            $response = (new EnsureSufficientExecutionTime)->handle(
                Request::create('/pta/suivi'),
                static fn (): Response => new Response('ok')
            );

            $this->assertSame('ok', $response->getContent());
            $this->assertSame(120, (int) ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', $originalLimit);
        }
    }

    public function test_it_does_not_reduce_a_higher_execution_limit(): void
    {
        $originalLimit = (string) ini_get('max_execution_time');
        config(['app.web_execution_time_limit' => 120]);

        try {
            ini_set('max_execution_time', '180');

            (new EnsureSufficientExecutionTime)->handle(
                Request::create('/dashboard'),
                static fn (): Response => new Response('ok')
            );

            $this->assertSame(180, (int) ini_get('max_execution_time'));
        } finally {
            ini_set('max_execution_time', $originalLimit);
        }
    }
}
