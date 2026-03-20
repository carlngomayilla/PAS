-- ARCHIVE LEGACY
-- Ce script conserve un import historique de demonstration base sur l'ancien referentiel
-- `@anbg.test` et un ancien schema de planification.
-- Il n'est pas aligne sur le referentiel ANBG courant seedé par `AnbgOrganizationSeeder`.
-- Ne pas utiliser en production ni sur une base locale recente sans adaptation.
--
-- Import PAS / PAO / PTA / Actions (SQLite compatible)
-- Source metier: PAS DG 2026-2028 / PAO DSIC 2026 / PTA SGDS-SSIRS-SCRP
-- Important: le schema impose UN PAO par (pas_id, direction_id, annee).
-- Donc PAO SGDS/SSIRS/SCRP de la source sont normalises en PTA par service.

BEGIN TRANSACTION;

-- =========================================================
-- 1) Direction + services
-- =========================================================
INSERT INTO directions (code, libelle, actif, created_at, updated_at)
SELECT 'DSIC', 'Direction des Systemes d Information et de la Communication', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (SELECT 1 FROM directions WHERE code = 'DSIC');

INSERT INTO services (direction_id, code, libelle, actif, created_at, updated_at)
SELECT d.id, 'SGDS', 'Service Gestion Documentaire et Statistique', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM directions d
WHERE d.code = 'DSIC'
  AND NOT EXISTS (
      SELECT 1 FROM services s WHERE s.direction_id = d.id AND s.code = 'SGDS'
  );

INSERT INTO services (direction_id, code, libelle, actif, created_at, updated_at)
SELECT d.id, 'SSIRS', 'Service Systemes d Information Reseaux et Securite', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM directions d
WHERE d.code = 'DSIC'
  AND NOT EXISTS (
      SELECT 1 FROM services s WHERE s.direction_id = d.id AND s.code = 'SSIRS'
  );

INSERT INTO services (direction_id, code, libelle, actif, created_at, updated_at)
SELECT d.id, 'SCRP', 'Service Communication et Relations Publiques', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM directions d
WHERE d.code = 'DSIC'
  AND NOT EXISTS (
      SELECT 1 FROM services s WHERE s.direction_id = d.id AND s.code = 'SCRP'
  );

INSERT INTO services (direction_id, code, libelle, actif, created_at, updated_at)
SELECT d.id, 'TRANSV', 'Service Transversal DSIC', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM directions d
WHERE d.code = 'DSIC'
  AND NOT EXISTS (
      SELECT 1 FROM services s WHERE s.direction_id = d.id AND s.code = 'TRANSV'
  );

-- =========================================================
-- 2) Re-alignement optionnel des comptes utilisateurs
-- =========================================================
UPDATE users
SET direction_id = (SELECT id FROM directions WHERE code = 'DSIC'),
    service_id = (SELECT s.id FROM services s JOIN directions d ON d.id = s.direction_id WHERE d.code = 'DSIC' AND s.code = 'SGDS')
WHERE email = 'planif.service@anbg.test';

UPDATE users
SET direction_id = (SELECT id FROM directions WHERE code = 'DSIC'),
    service_id = (SELECT s.id FROM services s JOIN directions d ON d.id = s.direction_id WHERE d.code = 'DSIC' AND s.code = 'SSIRS')
WHERE email = 'infra.service@anbg.test';

UPDATE users
SET direction_id = (SELECT id FROM directions WHERE code = 'DSIC'),
    service_id = (SELECT s.id FROM services s JOIN directions d ON d.id = s.direction_id WHERE d.code = 'DSIC' AND s.code = 'SSIRS')
WHERE email = 'dev.service@anbg.test';

UPDATE users
SET direction_id = (SELECT id FROM directions WHERE code = 'DSIC'),
    service_id = (SELECT s.id FROM services s JOIN directions d ON d.id = s.direction_id WHERE d.code = 'DSIC' AND s.code = 'SCRP')
WHERE email = 'suivi.service@anbg.test';

UPDATE users
SET direction_id = (SELECT id FROM directions WHERE code = 'DSIC'),
    service_id = NULL
WHERE email = 'dsi.direction@anbg.test';

-- =========================================================
-- 3) PAS + PAO
-- =========================================================
INSERT INTO pas (titre, periode_debut, periode_fin, statut, created_at, updated_at)
SELECT 'PAS DG 2026-2028', 2026, 2028, 'brouillon', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1 FROM pas
    WHERE titre = 'PAS DG 2026-2028' AND periode_debut = 2026 AND periode_fin = 2028
);

INSERT INTO paos (
    pas_id, direction_id, annee, titre,
    objectif_operationnel, resultats_attendus, indicateurs_associes,
    statut, created_at, updated_at
)
SELECT
    p.id,
    d.id,
    2026,
    'PAO DSIC 2026',
    'Decliner le PAS en execution operationnelle par services DSIC',
    'Plan annuel unifie par service, suivi hebdomadaire actif',
    'Taux d execution des actions, taux de completude hebdomadaire',
    'brouillon',
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM pas p
JOIN directions d ON d.code = 'DSIC'
WHERE p.titre = 'PAS DG 2026-2028'
  AND p.periode_debut = 2026
  AND p.periode_fin = 2028
  AND NOT EXISTS (
      SELECT 1
      FROM paos x
      WHERE x.pas_id = p.id AND x.direction_id = d.id AND x.annee = 2026
  );

-- =========================================================
-- 4) PTA
-- =========================================================
INSERT INTO ptas (pao_id, direction_id, service_id, titre, description, statut, created_at, updated_at)
SELECT pao.id, d.id, s.id, 'PTA SGDS 2026', 'Plan de travail annuel du service SGDS', 'brouillon', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM paos pao
JOIN directions d ON d.id = pao.direction_id
JOIN services s ON s.direction_id = d.id AND s.code = 'SGDS'
WHERE pao.titre = 'PAO DSIC 2026' AND pao.annee = 2026
  AND NOT EXISTS (SELECT 1 FROM ptas t WHERE t.pao_id = pao.id AND t.service_id = s.id);

INSERT INTO ptas (pao_id, direction_id, service_id, titre, description, statut, created_at, updated_at)
SELECT pao.id, d.id, s.id, 'PTA SSIRS 2026', 'Plan de travail annuel du service SSIRS', 'brouillon', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM paos pao
JOIN directions d ON d.id = pao.direction_id
JOIN services s ON s.direction_id = d.id AND s.code = 'SSIRS'
WHERE pao.titre = 'PAO DSIC 2026' AND pao.annee = 2026
  AND NOT EXISTS (SELECT 1 FROM ptas t WHERE t.pao_id = pao.id AND t.service_id = s.id);

INSERT INTO ptas (pao_id, direction_id, service_id, titre, description, statut, created_at, updated_at)
SELECT pao.id, d.id, s.id, 'PTA SCRP 2026', 'Plan de travail annuel du service SCRP', 'brouillon', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM paos pao
JOIN directions d ON d.id = pao.direction_id
JOIN services s ON s.direction_id = d.id AND s.code = 'SCRP'
WHERE pao.titre = 'PAO DSIC 2026' AND pao.annee = 2026
  AND NOT EXISTS (SELECT 1 FROM ptas t WHERE t.pao_id = pao.id AND t.service_id = s.id);

INSERT INTO ptas (pao_id, direction_id, service_id, titre, description, statut, created_at, updated_at)
SELECT pao.id, d.id, s.id, 'PTA DSIC Transversal 2026', 'Actions transversales DSIC', 'brouillon', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM paos pao
JOIN directions d ON d.id = pao.direction_id
JOIN services s ON s.direction_id = d.id AND s.code = 'TRANSV'
WHERE pao.titre = 'PAO DSIC 2026' AND pao.annee = 2026
  AND NOT EXISTS (SELECT 1 FROM ptas t WHERE t.pao_id = pao.id AND t.service_id = s.id);

-- =========================================================
-- 5) Actions (SGDS)
-- =========================================================
INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, resultat_attendu,
    date_debut, date_fin, date_echeance, responsable_id, statut, statut_dynamique,
    progression_reelle, progression_theorique, seuil_alerte_progression,
    risques, mesures_preventives, financement_requis,
    ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Actualiser le tableau de conservation des documents',
    'Mise a jour du referentiel de conservation',
    'qualitative', NULL, NULL, 'Tableau valide',
    '2026-01-15', '2026-03-31', '2026-03-31',
    COALESCE((SELECT id FROM users WHERE email = 'planif.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    NULL, NULL, 0, 1, 0, 0, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SGDS 2026' AND s.code = 'SGDS'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Actualiser le tableau de conservation des documents');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Optimiser la numerisation des archives',
    'Numerisation stock + courant',
    'quantitative', 'documents', 5000,
    '2026-02-01', '2026-12-31', '2026-12-31',
    COALESCE((SELECT id FROM users WHERE email = 'planif.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 1, 0, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SGDS 2026' AND s.code = 'SGDS'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Optimiser la numerisation des archives');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Rediger les bordereaux d elimination',
    'Preparation des bordereaux avant destruction',
    'quantitative', 'bordereaux', 12,
    '2026-04-01', '2026-07-31', '2026-07-31',
    COALESCE((SELECT id FROM users WHERE email = 'planif.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 0, 0, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SGDS 2026' AND s.code = 'SGDS'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Rediger les bordereaux d elimination');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, resultat_attendu,
    date_debut, date_fin, date_echeance, responsable_id, statut, statut_dynamique,
    progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Detruire les archives perimees et produire un rapport',
    'Execution de la destruction conforme',
    'qualitative', 'Rapport de destruction valide',
    '2026-08-01', '2026-09-30', '2026-09-30',
    COALESCE((SELECT id FROM users WHERE email = 'planif.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 0, 0, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SGDS 2026' AND s.code = 'SGDS'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Detruire les archives perimees et produire un rapport');

-- =========================================================
-- 6) Actions (SSIRS)
-- =========================================================
INSERT INTO actions (
    pta_id, libelle, description, type_cible, resultat_attendu, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, description_financement, source_financement, montant_estime,
    ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Mettre en place une application de gestion du controle interne',
    'Solution de suivi controle interne',
    'qualitative', 'Application deployee',
    '2026-01-15', '2026-08-31', '2026-08-31',
    COALESCE((SELECT id FROM users WHERE email = 'infra.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    1, 'Developpement et deploiement applicatif', 'Budget interne DSIC', 15000000,
    1, 1, 0, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SSIRS 2026' AND s.code = 'SSIRS'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Mettre en place une application de gestion du controle interne');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, resultat_attendu, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Mettre en place une politique de sauvegarde des donnees',
    'Cadre complet de sauvegarde et restauration',
    'qualitative', 'Politique approuvee',
    '2026-01-10', '2026-04-30', '2026-04-30',
    COALESCE((SELECT id FROM users WHERE email = 'infra.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 1, 0, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SSIRS 2026' AND s.code = 'SSIRS'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Mettre en place une politique de sauvegarde des donnees');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, resultat_attendu, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Mettre en place GLPI pour optimiser les ressources IT',
    'Mise en place de la plateforme GLPI',
    'qualitative', 'GLPI operationnel',
    '2026-02-01', '2026-06-30', '2026-06-30',
    COALESCE((SELECT id FROM users WHERE email = 'dev.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 1, 0, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SSIRS 2026' AND s.code = 'SSIRS'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Mettre en place GLPI pour optimiser les ressources IT');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Evaluer l etat du parc informatique',
    'Inventaire et diagnostic du parc',
    'quantitative', 'equipements', 300,
    '2026-03-01', '2026-05-31', '2026-05-31',
    COALESCE((SELECT id FROM users WHERE email = 'dev.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 1, 0, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SSIRS 2026' AND s.code = 'SSIRS'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Evaluer l etat du parc informatique');

-- =========================================================
-- 7) Actions (SCRP)
-- =========================================================
INSERT INTO actions (
    pta_id, libelle, description, type_cible, resultat_attendu, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Optimiser la strategie de communication globale de l agence',
    'Refonte de la strategie de communication institutionnelle',
    'qualitative', 'Plan de communication valide',
    '2026-01-15', '2026-09-30', '2026-09-30',
    COALESCE((SELECT id FROM users WHERE email = 'suivi.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 0, 1, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SCRP 2026' AND s.code = 'SCRP'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Optimiser la strategie de communication globale de l agence');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Elaborer une strategie de communication conjointe avec les partenaires',
    'Communication conjointe multi-acteurs',
    'quantitative', 'partenariats', 5,
    '2026-02-01', '2026-07-31', '2026-07-31',
    COALESCE((SELECT id FROM users WHERE email = 'suivi.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 0, 1, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SCRP 2026' AND s.code = 'SCRP'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Elaborer une strategie de communication conjointe avec les partenaires');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, resultat_attendu, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Inscrire au dialogue social la rationalisation de la depense de bourses',
    'Preparation, plaidoyer et tenue de session',
    'qualitative', 'Session tenue avec proces verbal',
    '2026-03-01', '2026-06-30', '2026-06-30',
    COALESCE((SELECT id FROM users WHERE email = 'suivi.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 0, 1, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SCRP 2026' AND s.code = 'SCRP'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Inscrire au dialogue social la rationalisation de la depense de bourses');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Rationaliser la demande de bourse',
    'Ameliorer la qualite des dossiers soumis',
    'quantitative', 'pourcentage', 90,
    '2026-01-15', '2026-12-31', '2026-12-31',
    COALESCE((SELECT id FROM users WHERE email = 'suivi.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 0, 1, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA SCRP 2026' AND s.code = 'SCRP'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Rationaliser la demande de bourse');

-- =========================================================
-- 8) Actions (TRANSVERSAL DSIC)
-- =========================================================
INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Mettre en place des programmes de formation cibles',
    'Programmes de formation selon besoins prioritaires',
    'quantitative', 'programmes', 4,
    '2026-02-01', '2026-12-31', '2026-12-31',
    COALESCE((SELECT id FROM users WHERE email = 'dev.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 0, 1, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA DSIC Transversal 2026' AND s.code = 'TRANSV'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Mettre en place des programmes de formation cibles');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Nouer des partenariats pour l insertion professionnelle des etudiants boursiers',
    'Partenariats academe et employabilite',
    'quantitative', 'partenariats', 8,
    '2026-02-01', '2026-12-31', '2026-12-31',
    COALESCE((SELECT id FROM users WHERE email = 'dev.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 0, 1, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA DSIC Transversal 2026' AND s.code = 'TRANSV'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Nouer des partenariats pour l insertion professionnelle des etudiants boursiers');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, resultat_attendu, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Mettre en place un monitoring des boursiers',
    'Dispositif continu de suivi',
    'qualitative', 'Dispositif de monitoring actif',
    '2026-03-01', '2026-09-30', '2026-09-30',
    COALESCE((SELECT id FROM users WHERE email = 'dev.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 1, 1, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA DSIC Transversal 2026' AND s.code = 'TRANSV'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Mettre en place un monitoring des boursiers');

INSERT INTO actions (
    pta_id, libelle, description, type_cible, unite_cible, quantite_cible, date_debut, date_fin, date_echeance,
    responsable_id, statut, statut_dynamique, progression_reelle, progression_theorique, seuil_alerte_progression,
    financement_requis, ressource_main_oeuvre, ressource_equipement, ressource_partenariat, ressource_autres,
    created_at, updated_at
)
SELECT
    t.id,
    'Rationaliser la depense de bourses',
    'Pilotage de l efficience des depenses',
    'quantitative', 'pourcentage', 15,
    '2026-01-15', '2026-12-31', '2026-12-31',
    COALESCE((SELECT id FROM users WHERE email = 'dev.service@anbg.test' LIMIT 1), (SELECT id FROM users WHERE role = 'agent' ORDER BY id LIMIT 1)),
    'non_demarre', 'non_demarre', 0, 0, 10,
    0, 1, 1, 1, 0,
    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM ptas t
JOIN services s ON s.id = t.service_id
WHERE t.titre = 'PTA DSIC Transversal 2026' AND s.code = 'TRANSV'
  AND NOT EXISTS (SELECT 1 FROM actions a WHERE a.pta_id = t.id AND a.libelle = 'Rationaliser la depense de bourses');

COMMIT;
