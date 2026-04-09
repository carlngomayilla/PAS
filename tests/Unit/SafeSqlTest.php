<?php

namespace Tests\Unit;

use App\Support\SafeSql;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SafeSqlTest extends TestCase
{
    public function test_identifier_accepts_allowlisted_columns(): void
    {
        $this->assertSame(
            'statut_validation',
            SafeSql::identifier('statut_validation', [
                'statut',
                'statut_dynamique',
                'statut_validation',
            ])
        );
    }

    public function test_identifier_rejects_unexpected_sql_fragments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SafeSql::identifier('statut_validation, DROP TABLE users', [
            'statut',
            'statut_dynamique',
            'statut_validation',
        ]);
    }
}
