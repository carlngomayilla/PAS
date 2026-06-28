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
