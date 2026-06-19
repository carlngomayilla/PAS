# Securite ANBG

## Controle d acces

- Limitation de debit `5 tentatives / 10 minutes` sur `POST /login` et `POST /api/login`.
- Blocage des routes web et API authentifiees si le mot de passe est expire.
- Revocation unitaire ou globale des sessions actives depuis le profil utilisateur.

## Politique mot de passe

- Longueur minimale: `8`
- Complexite moyenne: lettres et chiffres requis
- Majuscules et symboles acceptes, mais non obligatoires
- Verification contre mots de passe compromis
- Expiration: `90 jours`
- Historique: `5 derniers mots de passe`

## Fichiers justificatifs

- Scan antivirus a l upload via binaire externe configurable (`clamscan` par defaut)
- Chiffrement applicatif des justificatifs avant stockage disque
- Telechargement via dechiffrement controle cote serveur

## Variables d environnement

- `SECURITY_PASSWORD_MIN_LENGTH`
- `SECURITY_PASSWORD_EXPIRE_DAYS`
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
