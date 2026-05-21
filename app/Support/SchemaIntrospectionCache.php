<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * A31 — Cache memoise pour les appels Schema::hasColumn / hasTable.
 *
 * Le schema d une instance ne change pas pendant la duree de vie d un
 * process PHP (les migrations sont jouees hors-runtime). Les services
 * critiques (UserScopeService, ActionTrackingService, ActionWebController...)
 * appelaient Schema::hasColumn() a CHAQUE requete, ce qui implique une
 * interrogation du schema info via le driver SQL a chaque fois.
 *
 * Ce helper memorise les resultats par process (`static $cache`) pour
 * eliminer la surcharge SQL. La methode `flush()` est exposee pour les tests
 * qui rejouent les migrations entre cas (RefreshDatabase).
 */
class SchemaIntrospectionCache
{
    /**
     * @var array<string, bool>
     */
    private static array $columnCache = [];

    /**
     * @var array<string, bool>
     */
    private static array $tableCache = [];

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table.'::'.$column;

        return self::$columnCache[$key] ??= Schema::hasColumn($table, $column);
    }

    public static function hasTable(string $table): bool
    {
        return self::$tableCache[$table] ??= Schema::hasTable($table);
    }

    public static function flush(): void
    {
        self::$columnCache = [];
        self::$tableCache = [];
    }
}
