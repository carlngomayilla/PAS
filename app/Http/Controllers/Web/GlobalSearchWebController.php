<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\ExerciceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GlobalSearchWebController extends Controller
{
    use AuthorizesPlanningScope;

    public function index(Request $request, ExerciceContext $exerciceContext): mixed
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $query = trim((string) $request->query('q', ''));
        $groups = [];

        if (mb_strlen($query) >= 2) {
            $groups = [
                $this->users($query, $user),
                $this->actions($query, $exerciceContext, $user),
                $this->pas($query, $exerciceContext, $user),
                $this->paos($query, $exerciceContext, $user),
                $this->ptas($query, $exerciceContext, $user),
                $this->directions($query, $user),
                $this->services($query, $user),
            ];
        }

        $total = collect($groups)->sum(fn (array $group): int => count($group['items'] ?? []));

        if ($request->expectsJson() || $request->query('format') === 'json') {
            return response()->json(['query' => $query, 'total' => $total, 'groups' => $groups]);
        }

        return view('workspace.search.index', [
            'title' => 'Recherche globale',
            'query' => $query,
            'groups' => $groups,
            'total' => $total,
        ]);
    }

    /**
     * @return array{title: string, icon: string, items: list<array{title: string, subtitle: string, meta: string, href: string}>}
     */
    private function actions(string $query, ExerciceContext $exerciceContext, User $user): array
    {
        if (! Schema::hasTable('actions')) {
            return $this->group('Actions', 'actions', []);
        }

        $builder = Action::query()
            ->with(['pta.direction:id,code,libelle', 'pta.service:id,code,libelle', 'responsable:id,name'])
            ->select(['id', 'pta_id', 'responsable_id', 'libelle', 'description', 'resultat_attendu', 'description_financement', 'source_financement', 'statut_dynamique', 'progression_reelle', 'date_fin'])
            ->latest('id');

        $this->scopePlanningActions($builder, $user);
        $exerciceContext->applyToAction($builder);
        $this->applySearch($builder, ['libelle', 'description', 'resultat_attendu', 'description_financement', 'source_financement'], $query);

        $items = $builder->limit(8)->get()->map(function (Action $action): array {
            $direction = $action->pta?->direction?->libelle ?: 'Direction non renseignée';
            $service = $action->pta?->service?->libelle ?: 'Service non renseigné';
            $responsable = $action->responsable?->name ?: 'Responsable non renseigné';

            return [
                'title' => (string) $action->libelle,
                'subtitle' => 'Action - '.Str::of((string) ($action->statut_dynamique ?: 'non renseigné'))->replace('_', ' ')->ucfirst(),
                'meta' => $direction.' / '.$service.' - '.$responsable,
                'href' => route('workspace.actions.suivi', $action),
            ];
        })->values()->all();

        return $this->group('Actions', 'actions', $items);
    }

    /**
     * @return array{title: string, icon: string, items: list<array{title: string, subtitle: string, meta: string, href: string}>}
     */
    private function pas(string $query, ExerciceContext $exerciceContext, User $user): array
    {
        if (! Schema::hasTable('pas')) {
            return $this->group('PAS', 'pas', []);
        }

        $builder = Pas::query()
            ->select(['id', 'titre', 'periode_debut', 'periode_fin', 'statut'])
            ->latest('id');

        $this->scopePasByUser($builder, $user);
        $exerciceContext->applyToPas($builder);
        $this->applySearch($builder, ['titre', 'statut'], $query);

        $items = $builder->limit(6)->get()->map(fn (Pas $pas): array => [
            'title' => (string) $pas->titre,
            'subtitle' => 'Plan d’actions stratégique',
            'meta' => 'Période '.$pas->periode_debut.' - '.$pas->periode_fin.' · '.Str::of((string) $pas->statut)->replace('_', ' ')->ucfirst(),
            'href' => route('workspace.pas.edit', $pas),
        ])->values()->all();

        return $this->group('PAS', 'pas', $items);
    }

    /**
     * @return array{title: string, icon: string, items: list<array{title: string, subtitle: string, meta: string, href: string}>}
     */
    private function paos(string $query, ExerciceContext $exerciceContext, User $user): array
    {
        if (! Schema::hasTable('paos')) {
            return $this->group('PAO', 'pao', []);
        }

        $builder = Pao::query()
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->select(['id', 'direction_id', 'service_id', 'annee', 'titre', 'objectif_operationnel', 'resultats_attendus', 'indicateurs_associes', 'statut'])
            ->latest('id');

        $this->scopeByUserDirection($builder, $user, 'direction_id', 'service_id');
        $exerciceContext->applyToPao($builder);
        $this->applySearch($builder, ['titre', 'objectif_operationnel', 'resultats_attendus', 'indicateurs_associes', 'statut'], $query);

        $items = $builder->limit(8)->get()->map(fn (Pao $pao): array => [
            'title' => (string) $pao->titre,
            'subtitle' => 'Plan d’actions opérationnel',
            'meta' => ($pao->direction?->libelle ?: 'Direction non renseignée').' / '.($pao->service?->libelle ?: 'Service non renseigné').' · Exercice '.($pao->annee ?: '-'),
            'href' => route('workspace.pao.edit', $pao),
        ])->values()->all();

        return $this->group('PAO', 'pao', $items);
    }

    /**
     * @return array{title: string, icon: string, items: list<array{title: string, subtitle: string, meta: string, href: string}>}
     */
    private function ptas(string $query, ExerciceContext $exerciceContext, User $user): array
    {
        if (! Schema::hasTable('ptas')) {
            return $this->group('PTA', 'pta', []);
        }

        $builder = Pta::query()
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->select(['id', 'direction_id', 'service_id', 'titre', 'description', 'statut'])
            ->latest('id');

        $this->scopeByUserDirection($builder, $user, 'direction_id', 'service_id');
        $exerciceContext->applyToPta($builder);
        $this->applySearch($builder, ['titre', 'description', 'statut'], $query);

        $items = $builder->limit(8)->get()->map(fn (Pta $pta): array => [
            'title' => (string) $pta->titre,
            'subtitle' => 'Plan de travail annuel',
            'meta' => ($pta->direction?->libelle ?: 'Direction non renseignée').' / '.($pta->service?->libelle ?: 'Service non renseigné'),
            'href' => route('workspace.pta.edit', $pta),
        ])->values()->all();

        return $this->group('PTA', 'pta', $items);
    }

    /**
     * @return array{title: string, icon: string, items: list<array{title: string, subtitle: string, meta: string, href: string}>}
     */
    private function directions(string $query, User $user): array
    {
        if (! Schema::hasTable('directions')) {
            return $this->group('Directions', 'direction', []);
        }

        $builder = Direction::query()
            ->withCount(['services', 'users'])
            ->select(['id', 'code', 'libelle', 'actif'])
            ->orderBy('code');

        $this->scopeByUserDirection($builder, $user, 'id');
        $this->applySearch($builder, ['code', 'libelle'], $query);

        $items = $builder->limit(8)->get()->map(fn (Direction $direction): array => [
            'title' => trim(($direction->code ? $direction->code.' - ' : '').$direction->libelle),
            'subtitle' => 'Direction',
            'meta' => $direction->services_count.' service(s) · '.$direction->users_count.' utilisateur(s)',
            'href' => route('workspace.referentiel.directions.index', ['direction' => $direction->id]),
        ])->values()->all();

        return $this->group('Directions', 'direction', $items);
    }

    /**
     * @return array{title: string, icon: string, items: list<array{title: string, subtitle: string, meta: string, href: string}>}
     */
    private function services(string $query, User $user): array
    {
        if (! Schema::hasTable('services')) {
            return $this->group('Services', 'service', []);
        }

        $builder = Service::query()
            ->with(['direction:id,code,libelle'])
            ->withCount('users')
            ->select(['id', 'direction_id', 'code', 'libelle', 'actif'])
            ->orderBy('code');

        $this->scopeByUserDirection($builder, $user, 'direction_id', 'id');
        $this->applySearch($builder, ['code', 'libelle'], $query);

        $items = $builder->limit(8)->get()->map(fn (Service $service): array => [
            'title' => trim(($service->code ? $service->code.' - ' : '').$service->libelle),
            'subtitle' => 'Service',
            'meta' => ($service->direction?->libelle ?: 'Direction non renseignée').' · '.$service->users_count.' utilisateur(s)',
            'href' => route('workspace.referentiel.services.index', ['service' => $service->id]),
        ])->values()->all();

        return $this->group('Services', 'service', $items);
    }

    /**
     * @return array{title: string, icon: string, items: list<array{title: string, subtitle: string, meta: string, href: string}>}
     */
    private function users(string $query, User $viewer): array
    {
        if (! Schema::hasTable('users')) {
            return $this->group('Profils utilisateurs', 'users', []);
        }

        $builder = User::query()
            ->with(['direction:id,code,libelle', 'service:id,code,libelle'])
            ->select([
                'id',
                'name',
                'email',
                'role',
                'custom_role_code',
                'agent_matricule',
                'agent_fonction',
                'agent_telephone',
                'direction_id',
                'service_id',
                'is_active',
                'suspended_until',
            ])
            ->orderByDesc('is_active')
            ->orderBy('name');

        $this->scopePlanningUsers($builder, $viewer);
        if (! $viewer->isSuperAdmin()) {
            $builder->where('role', '!=', User::ROLE_SUPER_ADMIN);
        }
        $this->applyUserSearch($builder, $query);

        $items = $builder->limit(20)->get()->map(function (User $user): array {
            $direction = $user->direction
                ? trim(($user->direction->code ? $user->direction->code.' - ' : '').$user->direction->libelle)
                : 'Direction non renseignee';
            $service = $user->service
                ? trim(($user->service->code ? $user->service->code.' - ' : '').$user->service->libelle)
                : 'Service non renseigne';
            $status = $user->isSuspended() ? 'Suspendu' : ((bool) $user->is_active ? 'Actif' : 'Inactif');

            return [
                'title' => (string) $user->name,
                'subtitle' => 'Profil utilisateur - '.$user->roleLabel(),
                'meta' => trim(($user->agent_fonction ?: 'Fonction non renseignee').' - '.$direction.' / '.$service),
                'href' => route('workspace.referentiel.utilisateurs.index', ['q' => $user->email]),
                'badge' => $status,
                'badge_tone' => $status === 'Actif' ? 'success' : ($status === 'Suspendu' ? 'warning' : 'danger'),
                'details' => [
                    ['label' => 'Email', 'value' => (string) $user->email],
                    ['label' => 'Matricule', 'value' => (string) ($user->agent_matricule ?: '-')],
                    ['label' => 'Fonction', 'value' => (string) ($user->agent_fonction ?: '-')],
                    ['label' => 'Telephone', 'value' => (string) ($user->agent_telephone ?: '-')],
                    ['label' => 'Direction', 'value' => $direction],
                    ['label' => 'Service', 'value' => $service],
                ],
            ];
        })->values()->all();

        return $this->group('Profils utilisateurs', 'users', $items);
    }

    /**
     * @param  list<string>  $columns
     */
    private function applySearch(Builder $builder, array $columns, string $query): void
    {
        $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $builder->where(function (Builder $subQuery) use ($columns, $needle, $operator): void {
            foreach ($columns as $column) {
                $subQuery->orWhere($column, $operator, $needle);
            }
        });
    }

    private function applyUserSearch(Builder $builder, string $query): void
    {
        $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $builder->where(function (Builder $subQuery) use ($needle, $operator): void {
            foreach (['name', 'email', 'agent_matricule', 'agent_fonction', 'agent_telephone', 'role', 'custom_role_code'] as $column) {
                $subQuery->orWhere($column, $operator, $needle);
            }

            $subQuery
                ->orWhereHas('direction', function (Builder $directionQuery) use ($needle, $operator): void {
                    $directionQuery
                        ->where('code', $operator, $needle)
                        ->orWhere('libelle', $operator, $needle);
                })
                ->orWhereHas('service', function (Builder $serviceQuery) use ($needle, $operator): void {
                    $serviceQuery
                        ->where('code', $operator, $needle)
                        ->orWhere('libelle', $operator, $needle);
                });
        });
    }

    /**
     * @param  list<array{title: string, subtitle: string, meta: string, href: string}>  $items
     * @return array{title: string, icon: string, items: list<array{title: string, subtitle: string, meta: string, href: string}>}
     */
    private function group(string $title, string $icon, array $items): array
    {
        return [
            'title' => $title,
            'icon' => $icon,
            'items' => $items,
        ];
    }
}
