<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Les modeles Action/Pas/Pao/Pta/User restreignent volontairement $fillable
        // pour bloquer le mass-assignment des champs workflow (cf. A02). Les fixtures
        // de tests doivent pouvoir setter directement ces champs (role, statut,
        // valide_par, etc.) via factories : on desactive donc le guard cote tests.
        // La protection reste active en runtime applicatif (controleurs / API).
        Model::unguard();
    }

    protected function tearDown(): void
    {
        Model::reguard();

        // A31 — Flush le cache d introspection schema entre tests : sinon les
        // tests qui rejouent les migrations via RefreshDatabase verraient un
        // schema "fige" cote cache et leur scope/has_column serait incorrect.
        \App\Support\SchemaIntrospectionCache::flush();

        parent::tearDown();
    }
}
