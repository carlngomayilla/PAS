# Lot 11 - Notifications internes + emails Brevo SMTP

Ce lot est volontairement placé en dernier dans la file d'attente technique. Il
doit être lancé après stabilisation du workflow PAS -> PAO -> PTA -> Actions ->
Sous-actions -> Suivi -> Validations -> KPI -> Rapports -> Audit.

## Prompt technique à exécuter

```text
Tu es un expert Laravel 12 / PHP 8.2+, Blade, Tailwind CSS, PostgreSQL, Laravel Notifications, Queues, Mail SMTP, Brevo, RBAC, audit trail et architecture service.

Contexte :
Je développe une application PAS ANBG de pilotage stratégique et opérationnel.
L'application gère la chaîne métier :

PAS → PAO → PTA → Actions → Sous-actions → Suivi → Validations → KPI → Rapports → Audit

Je veux ajouter une nouvelle fonction de notifications et alertes avec double canal :

1. Notification interne dans l'application PAS
2. Envoi complémentaire par email via Brevo vers la boîte mail de l'utilisateur

Important :
Brevo ne doit pas remplacer les notifications internes.
L'application PAS reste la source principale de notification.
Brevo sert seulement à envoyer une copie par email vers l'adresse enregistrée dans le compte utilisateur, que cette adresse soit Gmail, professionnelle, Outlook, Yahoo ou autre.

Objectif :
Mettre en place un système propre, robuste et maintenable de notifications internes + emails Brevo pour tous les événements métier importants de l'application PAS.

Architecture attendue :

Événement métier PAS
    ↓
Notification Laravel
    ↓
Canal database : notification interne dans l'application
    ↓
Canal mail : email envoyé via Brevo SMTP
    ↓
Affichage dans la cloche / dashboard / Mes tâches
    ↓
Email reçu par l'utilisateur

========================
1. CONFIGURATION BREVO
========================

Configurer Laravel pour utiliser Brevo en SMTP.

Dans .env, prévoir :

MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=IDENTIFIANT_SMTP_BREVO
MAIL_PASSWORD=CLE_SMTP_BREVO
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@domaine.com
MAIL_FROM_NAME="PAS ANBG"

Ne jamais mettre les identifiants Brevo en dur dans le code.

Vérifier config/mail.php pour s'assurer que Laravel utilise bien les valeurs du .env.

Prévoir une documentation dans le projet indiquant :
- utiliser une clé SMTP Brevo et non une clé API pour le SMTP ;
- configurer SPF, DKIM et DMARC côté domaine ;
- tester l'envoi email avec Tinker.

========================
2. TABLE NOTIFICATIONS INTERNES
========================

Vérifier si la table Laravel notifications existe.

Si elle n'existe pas :
- générer la migration avec php artisan make:notifications-table
- exécuter php artisan migrate

La table notifications doit permettre :
- notification interne ;
- lu / non lu ;
- lien vers l'élément concerné ;
- type de notification ;
- niveau : info, avertissement, critique ;
- date de création.

========================
3. MODÈLE USER
========================

Vérifier que App\Models\User utilise bien :

use Illuminate\Notifications\Notifiable;

Et dans la classe :

use Notifiable;

Tous les utilisateurs doivent pouvoir recevoir des notifications Laravel.

========================
4. QUEUES
========================

Les emails Brevo doivent être envoyés en queue pour ne pas ralentir l'application.

Configurer :

QUEUE_CONNECTION=database

Créer les tables si nécessaire :
php artisan queue:table
php artisan queue:failed-table
php artisan migrate

Toutes les notifications qui envoient des emails doivent implémenter ShouldQueue.

Prévoir un worker queue en production avec Supervisor.

========================
5. SERVICE CENTRAL DE NOTIFICATION
========================

Créer un service central :

app/Services/Notifications/NotificationDispatcherService.php

Responsabilités :
- envoyer une notification à un ou plusieurs utilisateurs ;
- centraliser la logique d'envoi ;
- éviter d'envoyer les notifications directement depuis les contrôleurs ;
- permettre plus tard d'ajouter des préférences utilisateur.

Exemple de logique :

NotificationDispatcherService
- notify($users, Notification $notification)
- notifyUser(User $user, Notification $notification)
- notifyRole(string $role, Notification $notification)
- notifyUsersByScope($scope, Notification $notification)

Les contrôleurs ne doivent pas contenir de logique Mail::send directe.

Bonne architecture :

Controller
    ↓
Service métier
    ↓
NotificationDispatcherService
    ↓
Laravel Notification
    ↓
database + mail via Brevo

========================
6. CANAUX DE NOTIFICATION
========================

Chaque notification métier importante doit utiliser au minimum :

return ['database', 'mail'];

Le canal database sert aux notifications internes.
Le canal mail sert aux emails envoyés via Brevo.

Chaque notification doit contenir dans toDatabase :
- type
- title
- message
- level : info, warning, critical
- url vers l'élément concerné
- module concerné
- related_id si applicable
- related_type si applicable
- created_by si applicable

Chaque notification doit contenir dans toMail :
- subject clair
- greeting personnalisé
- résumé de l'événement
- lien d'accès à l'application
- message institutionnel court

========================
7. NOTIFICATIONS MÉTIER À CRÉER
========================

Créer les notifications suivantes :

1. PaoTransmittedNotification
Événement :
PAO validé automatiquement et objectifs opérationnels transmis aux services.

Destinataires :
- chefs de service concernés
- directeur concerné en récapitulatif
- SCIQ / Planification en suivi global

Canaux :
database + mail

2. PtaCreatedNotification
Événement :
PTA créé par un chef de service/unité.

Destinataires :
- directeur de la direction
- SCIQ / Planification selon périmètre

3. ActionAssignedNotification
Événement :
action créée et affectée à un ou plusieurs RMO/agents.

Destinataires :
- agents/RMO responsables

4. SubActionAssignedNotification
Événement :
sous-action affectée à un RMO.

Destinataires :
- RMO concerné

5. ActionSubmittedNotification
Événement :
agent/RMO soumet son action ou sa sous-action.

Destinataires :
- chef de service/unité

Effet :
- créer une tâche dans Mes tâches du chef
- lancer le compte à rebours de 48h

6. ActionValidatedByChiefNotification
Événement :
chef valide une action ou sous-action.

Destinataires :
- agent/RMO concerné
- directeur si action sensible

7. ActionRejectedNotification
Événement :
chef/directeur rejette ou demande correction.

Destinataires :
- agent/RMO ou chef concerné

Le motif doit être visible dans la notification.

8. ActionSentToDirectorNotification
Événement :
action sensible transmise au directeur.

Destinataire :
- directeur concerné

9. FinancingPreSignaledNotification
Événement :
action créée avec besoin de financement = oui.

Destinataire :
- Directeur DAF

Statut financement :
Pré-signalé DAF

10. FinancingSubmittedNotification
Événement :
chef valide une action avec besoin de financement.

Destinataire :
- Directeur DAF

Statut financement :
Soumis DAF

Effet :
- créer une tâche DAF
- lancer délai de 3 jours

11. FinancingDecisionNotification
Événement :
DAF valide, rejette, demande complément ou transmet au DG.

Destinataires :
- chef concerné
- directeur concerné
- DG si transmis DG

12. SciqBlockingNotification
Événement :
SCIQ ou Planification bloque une action, un PAO, un PTA ou un reporting.

Destinataires :
- acteur concerné
- chef concerné
- directeur concerné si nécessaire

Le motif du blocage doit être obligatoire.

13. CriticalAlertNotification
Événement :
alerte critique automatique ou manuelle.

Destinataires :
selon le responsable de l'action attendue.

14. TaskReminderNotification
Événement :
tâche proche échéance ou en retard.

Destinataires :
responsable de la tâche.

========================
8. ÉVÉNEMENTS QUI DOIVENT DÉCLENCHER UNE NOTIFICATION
========================

Brancher les notifications sur les événements suivants :

PAO :
- PAO validé automatiquement
- objectifs opérationnels transmis aux services
- correction demandée par SCIQ / Planification

PTA :
- PTA créé
- PTA clôturé
- anomalies avant clôture

Actions :
- action créée
- action assignée
- sous-action assignée
- suivi enregistré
- action ou sous-action soumise
- action en attente validation chef
- validation chef
- rejet chef
- action sensible transmise directeur
- validation directeur
- rejet directeur
- correction demandée

Financement :
- financement pré-signalé à la DAF
- financement officiellement soumis
- complément demandé
- financement validé DAF
- financement rejeté DAF
- financement transmis DG
- financement validé DG
- financement rejeté DG

SCIQ / Planification :
- anomalie signalée
- blocage créé
- blocage levé
- correction demandée

Alertes :
- action en retard
- action sous cible
- validation chef dépassant 48h
- traitement DAF dépassant 3 jours
- objectif opérationnel sans action
- KPI incohérent
- financement non traité
- arbitrage DG demandé

Rapports :
- rapport consolidé disponible
- rapport anomalies généré
- rapport financement généré

========================
9. INTÉGRATION AVEC MES TÂCHES
========================

Certaines notifications doivent créer une tâche dans le module Mes tâches.

Créer une tâche quand une action est attendue.

Exemples :
- agent reçoit une action à exécuter
- chef reçoit une action à valider
- directeur reçoit une action sensible à arbitrer
- DAF reçoit un financement à traiter
- DG reçoit un financement critique ou arbitrage
- SCIQ / Planification reçoit un contrôle ou blocage à traiter

Chaque tâche doit avoir :
- type
- titre
- description
- responsable_id
- related_type
- related_id
- deadline_at
- status : ouverte, en_cours, traitee, en_retard
- criticite : normale, importante, critique
- source_notification_id si applicable

Délais :
- chef : 48h
- directeur : 48h
- SCIQ / Planification : 48h
- DG : 48h
- DAF : 3 jours

Le retard doit pénaliser le responsable de la tâche, pas l'émetteur.

========================
10. CENTRE DE NOTIFICATIONS
========================

Créer ou améliorer la page :

/notifications

Fonctions attendues :
- liste des notifications
- filtre lu / non lu
- filtre par niveau : info, avertissement, critique
- filtre par module : PAO, PTA, Action, Financement, Contrôle, Rapport
- bouton marquer comme lu
- bouton tout marquer comme lu
- lien vers l'élément concerné

La cloche de notification dans la navbar doit afficher :
- nombre de notifications non lues
- 5 ou 10 dernières notifications
- lien vers le centre de notifications

========================
11. DASHBOARD
========================

Dans chaque dashboard, afficher :
- dernières notifications importantes
- alertes critiques du périmètre
- tâches en attente
- tâches proches échéance
- tâches en retard

Respecter le périmètre :
- agent : ses notifications
- chef : service/unité
- directeur : direction
- DAF : sa direction + financements agence
- SCIQ/Planification : contrôle global
- DG/DGA/Cabinet : global selon droits
- Super Admin : global technique

========================
12. PRÉFÉRENCES UTILISATEUR
========================

Ajouter une table user_notification_preferences.

Champs proposés :
- user_id
- notify_in_app default true
- notify_by_email default true
- email_action_assigned default true
- email_validation_required default true
- email_financing default true
- email_alerts default true
- email_reports default false

Le canal database doit rester activé par défaut.
Le canal email peut être désactivé par préférence utilisateur si la règle métier l'autorise.

Pour les alertes critiques, prévoir une option côté Super Admin :
- email obligatoire même si l'utilisateur a désactivé certains emails.

========================
13. AUDIT DES NOTIFICATIONS SENSIBLES
========================

Auditer les notifications sensibles :
- blocage SCIQ / Planification
- alerte critique
- financement transmis DG
- rejet financement
- rejet action
- demande correction
- notification d'arbitrage DG
- modification préférence notification critique

Chaque audit doit contenir :
- auteur
- destinataire
- type notification
- canal : database, mail
- date/heure
- statut envoi
- related_type
- related_id
- motif si applicable

========================
14. GESTION DES ERREURS
========================

Si l'email Brevo échoue :
- la notification interne doit quand même être créée
- l'échec email doit être logué
- le job doit être réessayé par la queue
- les jobs échoués doivent aller dans failed_jobs

Ne jamais bloquer l'action métier si l'email échoue.
La source principale reste l'application.

========================
15. TESTS À AJOUTER
========================

Ajouter des tests Feature/Unit :

1. test_action_assigned_creates_database_notification
2. test_action_assigned_sends_mail_notification
3. test_action_submission_creates_task_for_chief
4. test_financing_pre_signal_notifies_daf
5. test_financing_submission_creates_daf_task_with_three_days_deadline
6. test_sciq_blocking_notifies_chief_and_director
7. test_critical_alert_creates_notification_and_task
8. test_user_only_sees_own_notifications
9. test_director_only_sees_direction_notifications
10. test_database_notification_created_even_if_mail_fails
11. test_user_notification_preferences_disable_email_but_keep_database
12. test_critical_alert_email_can_be_forced_by_super_admin_setting

Utiliser Mail::fake(), Notification::fake(), Queue::fake() selon les cas.

========================
16. COMMANDES À FOURNIR
========================

À la fin, fournir les commandes à lancer :

php artisan make:notifications-table
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
php artisan optimize:clear
php artisan queue:work
php artisan test

Et pour production avec Supervisor :
- fichier supervisor
- commandes supervisorctl reread/update/start/status

========================
17. LIVRABLE ATTENDU
========================

Tu dois :
1. analyser les fichiers existants liés aux notifications, alertes et mails ;
2. lister les fichiers impactés ;
3. créer les notifications Laravel nécessaires ;
4. créer ou adapter le service NotificationDispatcherService ;
5. configurer les canaux database + mail ;
6. brancher les notifications aux événements métier PAS ;
7. créer/améliorer la page notifications ;
8. intégrer la cloche de notification ;
9. intégrer les notifications dans dashboard et Mes tâches ;
10. ajouter les préférences utilisateur ;
11. ajouter les tests ;
12. fournir un résumé clair des modifications ;
13. fournir les commandes à lancer ;
14. vérifier que l'envoi email Brevo fonctionne sans casser les notifications internes.

Règle finale :
Toute alerte ou notification métier générée par PAS doit être enregistrée dans l'application et affichée à l'utilisateur concerné. En supplément, une copie doit être envoyée par email via Brevo à l'adresse email de l'utilisateur.
```
