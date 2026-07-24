<?php

namespace App\Enums;

enum StatutValidation: string
{
    case Brouillon = 'brouillon';
    case Soumis = 'soumis';
    case EnAttenteValidationChef = 'en_attente_validation_chef';
    case RejeteParChef = 'rejete_par_chef';
    case ValideParChef = 'valide_par_chef';
    case EnAttenteValidationControleur = 'en_attente_validation_controleur';
    case RejeteParControleur = 'rejete_par_controleur';
    case ValideParControleur = 'valide_par_controleur';
    case Cloture = 'cloture';

    public static function fromLegacy(?string $value): self
    {
        return match (self::normalize($value)) {
            'soumis', 'soumise', 'soumise_chef' => self::Soumis,
            'en_validation_chef', 'en_attente_validation_chef' => self::EnAttenteValidationChef,
            'rejetee_chef', 'rejete_chef', 'rejete_par_chef' => self::RejeteParChef,
            'validee_chef', 'valide_chef', 'valide_par_chef' => self::ValideParChef,
            'soumise_controle', 'en_validation_controleur', 'en_attente_validation_controleur' => self::EnAttenteValidationControleur,
            'correction_controle', 'rejetee_controleur', 'rejete_controleur', 'rejete_par_controleur' => self::RejeteParControleur,
            'validee_controle', 'validee_controleur', 'valide_controleur', 'valide_par_controleur' => self::ValideParControleur,
            'cloturee', 'cloture', 'cloture' => self::Cloture,
            default => self::Brouillon,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis',
            self::EnAttenteValidationChef => 'En attente validation chef',
            self::RejeteParChef => 'Rejete par chef',
            self::ValideParChef => 'Valide par chef',
            self::EnAttenteValidationControleur => 'En attente validation controleur',
            self::RejeteParControleur => 'Rejete par controleur',
            self::ValideParControleur => 'Valide par controleur',
            self::Cloture => 'Cloture',
        };
    }

    private static function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return str_replace([' ', '-'], '_', $value);
    }
}
