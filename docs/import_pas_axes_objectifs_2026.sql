-- Import PAS + Axes + Objectifs strategiques (SQLite compatible)
-- Cible: PAS DG 2026-2028 (ANBG)
-- Script idempotent: peut etre rejoue sans creer de doublons.

BEGIN TRANSACTION;

-- =========================================================
-- 1) PAS
-- =========================================================
INSERT INTO pas (titre, periode_debut, periode_fin, statut, created_at, updated_at)
SELECT 'PAS DG 2026-2028', 2026, 2028, 'brouillon', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM pas
    WHERE titre = 'PAS DG 2026-2028'
      AND periode_debut = 2026
      AND periode_fin = 2028
);

-- =========================================================
-- 2) AXES STRATEGIQUES
-- =========================================================
INSERT INTO pas_axes (pas_id, code, libelle, description, ordre, created_at, updated_at)
SELECT p.id, 'AXE1', 'Pilotage et gouvernance', 'Renforcer le pilotage institutionnel, le suivi et la qualite de l execution.', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas p
WHERE p.titre = 'PAS DG 2026-2028' AND p.periode_debut = 2026 AND p.periode_fin = 2028
  AND NOT EXISTS (
      SELECT 1 FROM pas_axes a WHERE a.pas_id = p.id AND a.code = 'AXE1'
  );

INSERT INTO pas_axes (pas_id, code, libelle, description, ordre, created_at, updated_at)
SELECT p.id, 'AXE2', 'Transformation numerique et securite SI', 'Moderniser les systemes d information et renforcer la securite des donnees.', 2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas p
WHERE p.titre = 'PAS DG 2026-2028' AND p.periode_debut = 2026 AND p.periode_fin = 2028
  AND NOT EXISTS (
      SELECT 1 FROM pas_axes a WHERE a.pas_id = p.id AND a.code = 'AXE2'
  );

INSERT INTO pas_axes (pas_id, code, libelle, description, ordre, created_at, updated_at)
SELECT p.id, 'AXE3', 'Gestion documentaire et capitalisation', 'Structurer la gestion des archives et la conservation documentaire.', 3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas p
WHERE p.titre = 'PAS DG 2026-2028' AND p.periode_debut = 2026 AND p.periode_fin = 2028
  AND NOT EXISTS (
      SELECT 1 FROM pas_axes a WHERE a.pas_id = p.id AND a.code = 'AXE3'
  );

INSERT INTO pas_axes (pas_id, code, libelle, description, ordre, created_at, updated_at)
SELECT p.id, 'AXE4', 'Communication et performance sociale', 'Optimiser la communication institutionnelle et la conduite du dialogue social.', 4, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas p
WHERE p.titre = 'PAS DG 2026-2028' AND p.periode_debut = 2026 AND p.periode_fin = 2028
  AND NOT EXISTS (
      SELECT 1 FROM pas_axes a WHERE a.pas_id = p.id AND a.code = 'AXE4'
  );

-- =========================================================
-- 3) OBJECTIFS STRATEGIQUES
-- =========================================================
-- AXE1
INSERT INTO pas_objectifs (
    pas_axe_id, code, libelle, description, indicateur_global, valeur_cible, created_at, updated_at
)
SELECT a.id, 'OS11', 'Renforcer le pilotage du PAS/PAO/PTA', 'Consolider la planification et le suivi de l execution par niveau.', 'Taux de plans valides et suivis (%)', '100', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas_axes a
JOIN pas p ON p.id = a.pas_id
WHERE p.titre = 'PAS DG 2026-2028' AND a.code = 'AXE1'
  AND NOT EXISTS (
      SELECT 1 FROM pas_objectifs o WHERE o.pas_axe_id = a.id AND o.code = 'OS11'
  );

INSERT INTO pas_objectifs (
    pas_axe_id, code, libelle, description, indicateur_global, valeur_cible, created_at, updated_at
)
SELECT a.id, 'OS12', 'Ameliorer la qualite et la tracabilite operationnelle', 'Generaliser la justification et la mesure des resultats par action.', 'Taux de completude des suivis hebdomadaires (%)', '90', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas_axes a
JOIN pas p ON p.id = a.pas_id
WHERE p.titre = 'PAS DG 2026-2028' AND a.code = 'AXE1'
  AND NOT EXISTS (
      SELECT 1 FROM pas_objectifs o WHERE o.pas_axe_id = a.id AND o.code = 'OS12'
  );

-- AXE2
INSERT INTO pas_objectifs (
    pas_axe_id, code, libelle, description, indicateur_global, valeur_cible, created_at, updated_at
)
SELECT a.id, 'OS21', 'Digitaliser les processus critiques', 'Mettre en place les applications prioritaires de pilotage et de gestion IT.', 'Nombre d applications critiques deployees', '3', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas_axes a
JOIN pas p ON p.id = a.pas_id
WHERE p.titre = 'PAS DG 2026-2028' AND a.code = 'AXE2'
  AND NOT EXISTS (
      SELECT 1 FROM pas_objectifs o WHERE o.pas_axe_id = a.id AND o.code = 'OS21'
  );

INSERT INTO pas_objectifs (
    pas_axe_id, code, libelle, description, indicateur_global, valeur_cible, created_at, updated_at
)
SELECT a.id, 'OS22', 'Securiser les donnees et les infrastructures', 'Formaliser la politique de sauvegarde, acces et continuite.', 'Taux de sauvegardes reussies (%)', '98', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas_axes a
JOIN pas p ON p.id = a.pas_id
WHERE p.titre = 'PAS DG 2026-2028' AND a.code = 'AXE2'
  AND NOT EXISTS (
      SELECT 1 FROM pas_objectifs o WHERE o.pas_axe_id = a.id AND o.code = 'OS22'
  );

-- AXE3
INSERT INTO pas_objectifs (
    pas_axe_id, code, libelle, description, indicateur_global, valeur_cible, created_at, updated_at
)
SELECT a.id, 'OS31', 'Moderniser la gestion documentaire', 'Actualiser le referentiel documentaire et optimiser les cycles archives.', 'Taux de conformite archivistique (%)', '90', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas_axes a
JOIN pas p ON p.id = a.pas_id
WHERE p.titre = 'PAS DG 2026-2028' AND a.code = 'AXE3'
  AND NOT EXISTS (
      SELECT 1 FROM pas_objectifs o WHERE o.pas_axe_id = a.id AND o.code = 'OS31'
  );

INSERT INTO pas_objectifs (
    pas_axe_id, code, libelle, description, indicateur_global, valeur_cible, created_at, updated_at
)
SELECT a.id, 'OS32', 'Accelerer la numerisation et la capitalisation', 'Augmenter la numerisation et la disponibilite des fonds documentaires.', 'Nombre de documents numerises', '5000', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas_axes a
JOIN pas p ON p.id = a.pas_id
WHERE p.titre = 'PAS DG 2026-2028' AND a.code = 'AXE3'
  AND NOT EXISTS (
      SELECT 1 FROM pas_objectifs o WHERE o.pas_axe_id = a.id AND o.code = 'OS32'
  );

-- AXE4
INSERT INTO pas_objectifs (
    pas_axe_id, code, libelle, description, indicateur_global, valeur_cible, created_at, updated_at
)
SELECT a.id, 'OS41', 'Optimiser la communication institutionnelle', 'Structurer la communication interne et externe de l agence.', 'Taux d execution du plan de communication (%)', '85', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas_axes a
JOIN pas p ON p.id = a.pas_id
WHERE p.titre = 'PAS DG 2026-2028' AND a.code = 'AXE4'
  AND NOT EXISTS (
      SELECT 1 FROM pas_objectifs o WHERE o.pas_axe_id = a.id AND o.code = 'OS41'
  );

INSERT INTO pas_objectifs (
    pas_axe_id, code, libelle, description, indicateur_global, valeur_cible, created_at, updated_at
)
SELECT a.id, 'OS42', 'Soutenir la rationalisation de la depense de bourses', 'Accompagner les decisions de performance via communication et dialogue social.', 'Taux de dossiers conformes (%)', '90', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM pas_axes a
JOIN pas p ON p.id = a.pas_id
WHERE p.titre = 'PAS DG 2026-2028' AND a.code = 'AXE4'
  AND NOT EXISTS (
      SELECT 1 FROM pas_objectifs o WHERE o.pas_axe_id = a.id AND o.code = 'OS42'
  );

COMMIT;

