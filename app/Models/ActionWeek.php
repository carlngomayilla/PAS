<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

/**
 * @deprecated Le suivi hebdomadaire des actions a ete supprime.
 *
 * Stub conserve pour ne pas casser l'autoload PSR-4 des anciens appels.
 * Toute requete sur ce modele renvoie une collection vide sans toucher la DB.
 */
final class ActionWeek
{
    public static function query(): self
    {
        return new self();
    }

    public function __call(string $name, array $arguments): self
    {
        // Toutes les methodes chainables retournent l'instance pour permettre
        // les chaines fluides (->where(...)->orderBy(...)->get()).
        return $this;
    }

    public static function __callStatic(string $name, array $arguments): self
    {
        return new self();
    }

    public function get(): Collection
    {
        return new Collection();
    }

    public function count(): int
    {
        return 0;
    }

    public function first()
    {
        return null;
    }

    public function exists(): bool
    {
        return false;
    }

    public function pluck($column)
    {
        return new Collection();
    }
}
