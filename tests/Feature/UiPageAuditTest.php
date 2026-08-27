<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Audit d'interface : rend chaque page principale pour chaque profil et
 * detecte les anomalies visuelles courantes (cles techniques exposees,
 * dates au format technique, erreurs de rendu).
 *
 * Le test bloque les erreurs serveur et les anomalies d'affichage détectées.
 */
class UiPageAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function auditedRoutes(): array
    {
        $skip = ['login.form', 'password.request', 'logout'];

        return collect(Route::getRoutes())
            ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->reject(fn (string $name): bool => in_array($name, $skip, true))
            ->reject(fn (string $name): bool => str_starts_with($name, 'horizon.'))
            ->reject(fn (string $name): bool => (bool) preg_match('/(export|download|spec|dropdown|ajax|^v1\.|api)/', $name))
            ->filter(function (string $name): bool {
                $route = Route::getRoutes()->getByName($name);

                return $route !== null && ! str_contains($route->uri(), '{');
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function profiles(): array
    {
        return [
            'Agent / RMO' => User::ROLE_AGENT,
            'Chef de service' => User::ROLE_SERVICE,
            'Direction' => User::ROLE_DIRECTION,
            'SCIQ' => User::ROLE_SCIQ,
            'Chef unite SCIQ' => User::ROLE_CHEF_UNITE_SCIQ,
            'Planification' => User::ROLE_PLANIFICATION,
            'Chef planification' => User::ROLE_CHEF_PLANIFICATION,
            'DG' => User::ROLE_DG,
            'Administrateur' => User::ROLE_ADMIN,
            'Super administrateur' => User::ROLE_SUPER_ADMIN,
        ];
    }

    /**
     * @return list<string>
     */
    private function detectAnomalies(string $html, string $routeName): array
    {
        $found = [];

        // 1. Cles techniques exposees a l'utilisateur.
        $technicalKeys = [
            'progression_sous_seuil', 'alerte_combinee_critique', 'chef_unite_sciq',
            'sciq_suivi_global', 'chef_planification', 'admin_fonctionnel',
            'statut_validation', 'type_evenement', 'non_soumise', 'soumise_chef',
            'validee_controle', 'correction_demandee',
        ];
        foreach ($technicalKeys as $key) {
            // On ignore les occurrences dans les attributs (value=, name=, data-...).
            if (preg_match('/>[^<]{0,80}\b'.preg_quote($key, '/').'\b/', $html)) {
                $found[] = 'cle technique affichee : '.$key;
            }
        }

        // 2. Dates au format technique visibles (2026-12-31 ou avec heure).
        if ($routeName !== 'workspace.analyses.model'
            && preg_match('/>[^<]{0,40}\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}(:\d{2})?)?[^<]{0,40}</', $html)) {
            $found[] = 'date au format technique (AAAA-MM-JJ)';
        }

        // 3. Trace d'erreur ou variable non resolue.
        foreach (['Undefined variable', 'Undefined array key', 'htmlspecialchars(): ', '{{ $'] as $marker) {
            if (str_contains($html, $marker)) {
                $found[] = 'erreur de rendu : '.$marker;
            }
        }

        return array_values(array_unique($found));
    }

    public function test_toutes_les_pages_sont_rendues_sans_erreur_pour_chaque_profil(): void
    {
        $routes = $this->auditedRoutes();
        $this->assertNotEmpty($routes, 'Aucune route auditable trouvee.');

        $serverErrors = [];
        $report = [];

        foreach ($this->profiles() as $label => $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);

            foreach ($routes as $name) {
                try {
                    $response = $this->actingAs($user)->get(route($name));
                    $status = $response->getStatusCode();
                } catch (\Throwable $e) {
                    $serverErrors[] = sprintf('%s | %s | EXCEPTION %s', $label, $name, substr($e->getMessage(), 0, 90));

                    continue;
                }

                if ($status >= 500) {
                    $serverErrors[] = sprintf('%s | %s | HTTP %d', $label, $name, $status);

                    continue;
                }

                if ($status !== 200) {
                    continue; // 403/302 : hors perimetre du profil, comportement normal.
                }

                $anomalies = $this->detectAnomalies($response->getContent(), $name);
                foreach ($anomalies as $anomaly) {
                    $report[] = sprintf('%s | %s | %s', $label, $name, $anomaly);
                }
            }
        }

        if ($report !== []) {
            fwrite(STDERR, PHP_EOL.'=== ANOMALIES VISUELLES DETECTEES ('.count($report).') ==='.PHP_EOL);
            foreach (array_slice($report, 0, 120) as $line) {
                fwrite(STDERR, '  - '.$line.PHP_EOL);
            }
        }

        $this->assertSame([], $serverErrors, "Pages en erreur serveur :\n".implode("\n", $serverErrors));
        $this->assertSame([], $report, "Anomalies visuelles :\n".implode("\n", $report));
    }
}
