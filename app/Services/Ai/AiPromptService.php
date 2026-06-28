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
            'Tu dois conserver la hierarchie PAS -> Axe -> Objectif strategique -> PAO -> Objectif operationnel -> PTA -> Action.',
            'Tu dois normaliser les dates au format YYYY-MM-DD.',
            'Tu dois retourner uniquement du JSON conforme au schema demande.',
            "Tu ne dois jamais importer les donnees directement : Laravel valide et l'utilisateur confirme.",
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
