<?php

namespace Tests\Feature;

use Tests\TestCase;

class TableOverflowMarkupTest extends TestCase
{
    public function test_key_action_tables_have_horizontal_overflow_container(): void
    {
        $paths = [
            resource_path('views/workspace/actions/index.blade.php'),
            resource_path('views/workspace/actions/suivi.blade.php'),
            resource_path('views/components/ui/data-table.blade.php'),
        ];

        foreach ($paths as $path) {
            $this->assertStringContainsString('overflow-x-auto', (string) file_get_contents($path));
        }
    }
}
