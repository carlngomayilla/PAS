<?php

namespace App\Services\Ai;

class AiPromptService
{
    public function ptaExtractionSystemPrompt(): string
    {
        return implode("\n", [
            "Tu es un agent IA specialise dans l'analyse des fichiers PTA de l'ANBG.",
            'Tu extrais uniquement les donnees presentes dans le document source.',
            'Tu ne dois jamais inventer une donnee : si elle est absente, retourne null.',
            'Tu dois conserver la hierarchie PAS -> Axe strategique -> Objectif strategique -> PAO -> Objectif operationnel -> PTA -> Action.',
            'Ta sortie sert a remplir la feuille Excel officielle IMPORT_GLOBAL : chaque information doit aller dans la colonne exacte attendue.',
            'Les libelles peuvent varier selon les documents : Action PTA, action, activite, description action ou tache designent libelle_action.',
            'Objectif operationnel, PAO, programme ou objectif PAO designent libelle_objectif_operationnel.',
            'Indicateur, livrable, preuve attendue ou justificatif attendu designent justificatif_attendu.',
            'Budget, montant, cout ou financement previsionnel designent montant_financement.',
            'Tu dois normaliser les dates au format YYYY-MM-DD.',
            'Tu dois retourner uniquement du JSON conforme au schema demande.',
            "Tu ne dois jamais importer les donnees directement : Laravel valide et l'utilisateur confirme.",
            '',
            $this->ptaImportGlobalMappingPrompt(),
        ]);
    }

    public function reportSystemPrompt(): string
    {
        return implode("\n", [
            'Tu es un redacteur institutionnel specialise dans le suivi PAS, PAO et PTA de l ANBG.',
            'Tous les chiffres doivent venir du JSON fourni par Laravel.',
            'Tu ne dois jamais inventer un chiffre, une action, un retard ou une recommandation.',
            'Si une donnee manque, indique que la donnee n est pas disponible.',
            'Le style doit etre administratif, clair et professionnel.',
        ]);
    }

    public function ptaActionParameterizationPrompt(): string
    {
        return implode("\n", [
            'Tu es un agent IA specialise dans le parametrage des actions PTA, PAO et PAS de l ANBG.',
            'Tu dois proposer type_action parmi Q, NQ ou M apres lecture croisee de l action, de l objectif operationnel, de l indicateur, de la cible, des dates, des ressources et des risques.',
            'Q = action quantitative mesurable par nombre, taux, volume, quantite ou pourcentage.',
            'NQ = action non quantitative validee par un livrable unique : rapport, note, fiche, PV, strategie, proposition, cahier de charges ou etude.',
            'M = action mixte, composee ou jalonnee avec plusieurs etapes, livrables, validations ou un suivi progressif.',
            'Ne jamais inventer une quantite cible : si elle est inconnue, mettre null et ajouter une alerte de validation humaine.',
            'Creer des sous-actions uniquement si l action est large, longue, risquee ou concerne un developpement, une politique, une maintenance, une numerisation, une formation, un programme de suivi ou une organisation complexe.',
            'Utiliser seuil_mode unique pour les livrables simples et seuil_mode trimestriel pour les actions longues ou jalonnees.',
            'Evaluer le risque en faible, modere, eleve ou critique selon les risques, ressources, donnees, securite, budget et dependances.',
            'Laravel controle la proposition, l utilisateur valide ou corrige, puis l application importe.',
        ]);
    }

    public function ptaImportGlobalMappingPrompt(): string
    {
        $guide = $this->importGlobalColumnGuide();

        return implode("\n", array_merge([
            'Colonnes officielles IMPORT_GLOBAL a produire, sans changer les noms :',
        ], array_map(
            static fn (string $column): string => '- '.$column.' : '.($guide[$column] ?? 'valeur extraite du document source ou null.'),
            $this->importGlobalColumns()
        ), [
            'Regles de rangement :',
            '- Les informations PAS/axes/objectifs doivent rester sur toutes les lignes action concernees.',
            '- Une ligne Excel finale represente une action PTA a importer.',
            '- Si une cellule du document contient plusieurs sous-actions, renseigne sous_actions et nombre_sous_actions.',
            '- Si le document ne donne pas de valeur, mets null au lieu de deviner.',
            '- Respecte les colonnes du modele meme si le document utilise des mots differents.',
        ]));
    }

    /**
     * @return array<string, string>
     */
    public function importGlobalColumnGuide(): array
    {
        return [
            'annee_debut_pas' => 'annee de debut du PAS ou de l exercice.',
            'annee_fin_pas' => 'annee de fin du PAS ; utiliser annee_debut_pas si periode absente.',
            'ordre_axe' => 'numero ou rang de l axe strategique.',
            'libelle_axe' => 'libelle de l axe strategique PAS.',
            'ordre_objectif_strategique' => 'numero ou rang de l objectif strategique.',
            'libelle_objectif_strategique' => 'libelle de l objectif strategique rattache a l axe.',
            'date_echeance_objectif_strategique' => 'echeance de l objectif strategique si elle existe.',
            'direction' => 'direction responsable ou concernee.',
            'service_unite' => 'service, unite ou entite responsable.',
            'ordre_objectif_operationnel' => 'numero ou rang de l objectif operationnel PAO.',
            'libelle_objectif_operationnel' => 'libelle objectif operationnel, programme, PAO ou resultat operationnel.',
            'date_echeance_objectif_operationnel' => 'echeance de l objectif operationnel.',
            'ordre_action' => 'numero ou rang de l action PTA.',
            'libelle_action' => 'action PTA, activite, tache ou description d action.',
            'date_debut_action' => 'date de debut de l action au format YYYY-MM-DD.',
            'date_fin_action' => 'date de fin ou echeance de l action au format YYYY-MM-DD.',
            'codes_agents_rmo' => 'codes agents responsables separes par ; si connus.',
            'cible_minimum_execution' => 'cible minimale en pourcentage, sans symbole %.',
            'justificatif_attendu' => 'indicateur, livrable, preuve ou justificatif attendu.',
            'type_action' => 'Q pour quantitatif, NQ pour livrable unique, M pour action composee.',
            'quantite_cible' => 'quantite numerique attendue pour une action Q.',
            'unite_cible' => 'unite de mesure de quantite_cible.',
            'seuil_mode' => 'unique ou trimestriel.',
            'seuil_t1' => 'seuil du trimestre 1 si mode trimestriel.',
            'seuil_t2' => 'seuil du trimestre 2 si mode trimestriel.',
            'seuil_t3' => 'seuil du trimestre 3 si mode trimestriel.',
            'seuil_t4' => 'seuil du trimestre 4 si mode trimestriel.',
            'nombre_sous_actions' => 'nombre de sous-actions detectees.',
            'sous_actions' => 'liste des sous-actions separees par ;.',
            'niveau_risque' => 'faible, modere, eleve ou critique.',
            'risque' => 'risques potentiels cites dans le document.',
            'mesures_preventives' => 'mesures de prevention ou mitigation.',
            'ressources_materielles' => 'ressources materielles, techniques ou equipements.',
            'main_oeuvre' => 'besoin en ressources humaines ou main-d oeuvre.',
            'autres_ressources' => 'autres ressources ou partenaires.',
            'financement' => '1 si financement requis, sinon 0.',
            'nature_financement' => 'source ou nature du financement.',
            'montant_financement' => 'budget, cout ou montant previsionnel.',
            'commentaire_obligatoire' => '1 si commentaire obligatoire pendant le suivi, sinon 0.',
            'champ_difficulte' => '1 si le suivi doit permettre de signaler une difficulte, sinon 0.',
        ];
    }

    /**
     * @return list<string>
     */
    public function importGlobalColumns(): array
    {
        return [
            'annee_debut_pas',
            'annee_fin_pas',
            'ordre_axe',
            'libelle_axe',
            'ordre_objectif_strategique',
            'libelle_objectif_strategique',
            'date_echeance_objectif_strategique',
            'direction',
            'service_unite',
            'ordre_objectif_operationnel',
            'libelle_objectif_operationnel',
            'date_echeance_objectif_operationnel',
            'ordre_action',
            'libelle_action',
            'date_debut_action',
            'date_fin_action',
            'codes_agents_rmo',
            'cible_minimum_execution',
            'justificatif_attendu',
            'type_action',
            'quantite_cible',
            'unite_cible',
            'seuil_mode',
            'seuil_t1',
            'seuil_t2',
            'seuil_t3',
            'seuil_t4',
            'nombre_sous_actions',
            'sous_actions',
            'niveau_risque',
            'risque',
            'mesures_preventives',
            'ressources_materielles',
            'main_oeuvre',
            'autres_ressources',
            'financement',
            'nature_financement',
            'montant_financement',
            'commentaire_obligatoire',
            'champ_difficulte',
        ];
    }
}
