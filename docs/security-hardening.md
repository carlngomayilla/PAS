# Securite ANBG

## Controle d acces

- Limitation de debit `5 tentatives / 10 minutes` sur `POST /login` et `POST /api/login`.
- Blocage des routes web et API authentifiees tant qu un mot de passe provisoire
  n a pas ete remplace lors de sa premiere connexion.
- Revocation unitaire ou globale des sessions actives depuis le profil utilisateur.

## Politique mot de passe

- Longueur minimale: `8`
- Complexite par defaut: lettres et chiffres requis
- La casse mixte, les symboles et le controle des mots de passe compromis sont
  configurables. Ils sont renforces dans le modele de production.
- Aucun renouvellement periodique n est impose.
- Un mot de passe provisoire fourni apres creation, import ou reinitialisation
  doit etre remplace une seule fois avant l acces aux modules.
- Historique: `5 derniers mots de passe`

## Fichiers justificatifs

- Scan antivirus a l upload via binaire externe configurable (`clamscan` par defaut)
- Chiffrement applicatif des justificatifs avant stockage disque
- Telechargement via dechiffrement controle cote serveur

## Variables d environnement

- `SECURITY_PASSWORD_MIN_LENGTH`
- `SECURITY_PASSWORD_HISTORY_SIZE`
- `SECURITY_PASSWORD_REQUIRE_LETTERS`
- `SECURITY_PASSWORD_REQUIRE_MIXED_CASE`
- `SECURITY_PASSWORD_REQUIRE_NUMBERS`
- `SECURITY_PASSWORD_REQUIRE_SYMBOLS`
- `SECURITY_PASSWORD_CHECK_PWNED`
- `SECURITY_ENCRYPT_JUSTIFICATIFS`
- `ANTIVIRUS_SCAN_ENABLED`
- `ANTIVIRUS_BINARY`
- `ANTIVIRUS_ARGUMENTS`
- `ANTIVIRUS_TIMEOUT`
- `ANTIVIRUS_FAIL_OPEN`
