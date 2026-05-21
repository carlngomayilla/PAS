# CI — Tests sur PostgreSQL (A11)

Ce projet exécute deux jobs CI à chaque push sur `main` ou pull request, depuis
[.github/workflows/tests.yml](../.github/workflows/tests.yml).

## Pourquoi deux jobs ?

| Job                | Base   | Rôle                                                 | Durée       |
|--------------------|--------|------------------------------------------------------|-------------|
| `tests-sqlite`     | SQLite in-memory | Feedback rapide (existant historique)      | ~2 min      |
| `tests-postgres`   | PostgreSQL 16    | Validation comportement production         | ~5 min      |

SQLite ne valide pas les comportements suivants — qui ne se révèlent qu'en
PostgreSQL :

- `CHECK` constraints sur enums (statut, statut_validation, role_scope, etc.)
- Types `DECIMAL` stricts (arrondis, overflow)
- `whereJsonContains` (sémantique différente entre SQLite/Postgres)
- Verrous (`lockForUpdate`, `sharedLock`), race conditions sur recalculs KPI
- Index `gin` / `partial`
- Transactions imbriquées (SAVEPOINT)
- Cascade `ON DELETE` réelle

Faire passer la suite SQLite seule **ne suffit pas pour valider une mise en
production**.

## Lancement en local

### Avec Docker

```bash
# Démarrer un Postgres jetable
docker run --rm -d \
  --name pas-pgsql-ci \
  -e POSTGRES_USER=pas_ci \
  -e POSTGRES_PASSWORD=pas_ci_secret \
  -e POSTGRES_DB=pas_anbg_ci \
  -p 5432:5432 \
  postgres:16-alpine

# Exécuter les tests en pointant dessus
DB_CONNECTION=pgsql \
DB_HOST=127.0.0.1 \
DB_PORT=5432 \
DB_DATABASE=pas_anbg_ci \
DB_USERNAME=pas_ci \
DB_PASSWORD=pas_ci_secret \
ANTIVIRUS_SCAN_ENABLED=false \
php artisan migrate --force \
&& \
DB_CONNECTION=pgsql \
DB_HOST=127.0.0.1 \
DB_PORT=5432 \
DB_DATABASE=pas_anbg_ci \
DB_USERNAME=pas_ci \
DB_PASSWORD=pas_ci_secret \
ANTIVIRUS_SCAN_ENABLED=false \
php artisan test -c phpunit.pgsql.xml

# Nettoyage
docker stop pas-pgsql-ci
```

### Avec une instance PostgreSQL déjà installée

Adapter les variables d'environnement aux credentials locaux, puis :

```bash
php artisan test -c phpunit.pgsql.xml
```

## Différences entre `phpunit.xml` et `phpunit.pgsql.xml`

| Variable             | `phpunit.xml`      | `phpunit.pgsql.xml` |
|----------------------|--------------------|---------------------|
| `DB_CONNECTION`      | `sqlite` (force)   | non défini (env CI) |
| `DB_DATABASE`        | `:memory:` (force) | non défini (env CI) |

Toutes les autres variables (`BCRYPT_ROUNDS=4`, `CACHE_STORE=array`, etc.) sont
identiques.

## Politique de merge recommandée

- **PR** : les deux jobs doivent passer.
- **Branche `main`** : protected, exige `tests-postgres` ✅ avant fusion.
- Toute migration qui ajoute un `CHECK`, un type `DECIMAL` ou un index `gin`
  doit être validée sur le job Postgres (jamais SQLite seul).

## Dépannage

| Symptôme                                          | Cause probable                          | Fix                                                 |
|---------------------------------------------------|-----------------------------------------|-----------------------------------------------------|
| Tests passent en SQLite, fail en Postgres         | `whereJsonContains` mal supporté        | Adapter la requête (`->where('col->key', ...)`)     |
| `SQLSTATE[23514] check constraint`                | CHECK constraint ajoutée en migration   | Adapter les fixtures pour respecter la contrainte   |
| `out of shared memory` (locks)                    | Trop de SAVEPOINT imbriqués             | Réduire la profondeur des `DB::transaction` nested  |
| `permission denied to create extension`           | Extension `pgcrypto`/`uuid-ossp` requise| Ajouter `CREATE EXTENSION IF NOT EXISTS` en migration |
