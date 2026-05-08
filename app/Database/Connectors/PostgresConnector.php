<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\PostgresConnector as BasePostgresConnector;

class PostgresConnector extends BasePostgresConnector
{
    /**
     * Add a bounded connection timeout so unavailable VM databases do not block
     * guest pages until PHP reaches max_execution_time.
     */
    protected function getDsn(array $config)
    {
        $dsn = parent::getDsn($config);
        $timeout = (int) ($config['connect_timeout'] ?? 0);

        return $timeout > 0 ? $dsn.';connect_timeout='.$timeout : $dsn;
    }
}
