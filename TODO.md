# TODO – Corrections analyse application

## Session 1 — nettoyage initial
- [x] Analyse complète de l'application (domaine, architecture, sécurité, tests)
- [x] Vérification que `routes/web.php` et `README.md` ne sont PAS corrompus (faux positif)
- [x] Suppression de la méthode morte `scopeOfficialActions()` dans `DashboardController.php`
- [x] Validation `php -l` du controller → OK
- [x] `.gitignore` complété (`tmp_*`, `diff.txt`)
- [x] Suppression `tmp_ppt_extract_20260227/` et `diff.txt`

## Session 2 — analyse globale + correction mojibake
- [x] Livraison du rapport `docs/analyse-globale-application.md` (9 sections, P1/P2/P3)
- [x] Inventaire CSS/JS (5 fichiers CSS sources, 14 JS, 3 layouts Blade)
- [x] Détection du mojibake UTF-8 → Latin-1 dans 3 fichiers :
  - [x] `resources/views/components/admin/sidebar.blade.php` (Délégations, Rétention)
  - [x] `resources/views/workspace/monitoring/pilotage.blade.php` (~54 occurrences)
  - [x] `app/Http/Controllers/Web/MonitoringWebController.php` (~22 occurrences)
- [x] Vidage du cache Blade compilé (`php artisan view:clear`)
- [x] Suppression des backups `.bak` et du script de migration `scripts/fix_mojibake.php`

## Optionnels (P2 / P3 du rapport)
- [ ] Lancer `php artisan test` pour valider la non-régression globale
- [ ] Lancer `npm run build` pour valider la compilation CSS/JS
- [ ] Refactoring `DashboardController` (~1800 lignes) en 6 builders + 1 aggregator
- [ ] Ajouter middleware `SecurityHeaders` (CSP, HSTS, X-Content-Type-Options)
- [ ] Exposer `/health` HTTP consommable par LB
- [ ] Unifier les 3 sources de tokens couleur (`@theme` + CSS vars + `cssVariablesInline`)
- [ ] Extraire ~650 lignes inline JS/CSS de `layouts/admin.blade.php`
- [ ] Supprimer `tailwind.config.js` (inactif en Tailwind v4)
- [ ] Éliminer `design-light.css` (refactor via `.light:*` Tailwind)
- [ ] Auto-héberger les polices Google (RGPD + performance)
- [ ] Ajouter un test Feature sur le dashboard pour les 6 profils de rôle
