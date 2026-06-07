# Slito — Architecture Back-end

Comment utiliser ce document Ce fichier est à la fois ta documentation de référence et le brief pour Claude Code. Place-le à la racine de ton projet (slito-backend/ARCHITECTURE.md). Quand tu lances Claude Code, demande-lui simplement : « Lis ARCHITECTURE.md et construis le squelette du projet décrit ». Il s'appuiera dessus pour générer la structure, les entités et la config.



## 1. Vue d'ensemble

Slito est une plateforme de réservation d'artisans. Le back-end expose une API REST consommée par :



- le site web (React/VueJS),

- la future application mobile (App Store / Play Store).



Le back-end est le cœur de confiance du système : c'est lui qui valide les inscriptions, gère les réservations, applique les règles métier (un RDV doit être accepté par l'artisan), et protège les données.

### Stack technique (issue du cahier des charges)

Couche

Technologie

Pourquoi

Langage

PHP 8.2+

Imposé par le cahier des charges

Framework

Symfony 7.x

Imposé par le cahier des charges

ORM

Doctrine

Standard Symfony pour parler à la base de données

Base de données

PostgreSQL (ou MySQL)

Robuste, gratuit, hébergeable en UE

Authentification

Symfony Security + JWT (LexikJWTAuthenticationBundle)

Fonctionne pour le web ET le mobile via une seule API

Paiement

Stripe

Abonnements artisans, conforme UE, bien documenté

Emails

Symfony Mailer

Notifications, validations

SMS

API tierce (ex: Twilio/OVH)

Rappels de RDV

Stockage fichiers

Système de fichiers local puis stockage objet UE

Images, documents certifiés



Note hébergement UE : le cahier des charges impose que les données soient stockées dans un État membre de l'UE. Tout hébergeur (base, fichiers) devra donc être localisé en UE (OVHcloud, Scaleway, Clever Cloud sont de bons candidats français).



## 2. Initialiser le projet (commandes)

Une fois Claude Code installé, voici les commandes de base. Claude Code peut les exécuter pour toi si tu le lui demandes, mais les voici pour comprendre ce qui se passe :



# 1. Créer le projet Symfony (version web/API)



composer create-project symfony/skeleton:"7.*" slito-backend



cd slito-backend



# 2. Ajouter les composants essentiels



composer require webapp                 # outils web de base



composer require orm                    # Doctrine (base de données)



composer require symfony/security-bundle



composer require lexik/jwt-authentication-bundle  # tokens JWT



composer require symfony/mailer         # emails



composer require nelmio/cors-bundle     # autoriser le front à appeler l'API



composer require --dev symfony/maker-bundle  # génère du code automatiquement



composer require --dev orm-fixtures faker     # données de test



Concept clé pour débuter : Symfony fonctionne par « bundles » (modules). On n'installe que ce dont on a besoin. maker-bundle est ton meilleur ami : il génère des entités, des contrôleurs, etc. via des commandes php bin/console make:....



## 3. Structure des dossiers

slito-backend/



├── config/                 # Configuration (sécurité, services, routes)



│   └── packages/



├── migrations/             # Historique des changements de la base de données



├── public/                 # Point d'entrée web (index.php)



├── src/



│   ├── Controller/         # Reçoit les requêtes HTTP, renvoie les réponses (API)



│   │   ├── Api/



│   │   │   ├── AuthController.php



│   │   │   ├── BusinessController.php



│   │   │   ├── AppointmentController.php



│   │   │   ├── ReviewController.php



│   │   │   ├── MessageController.php



│   │   │   └── AdminController.php



│   ├── Entity/             # Les "tables" de la base, en objets PHP



│   ├── Repository/         # Les requêtes pour lire/chercher dans la base



│   ├── Service/            # La logique métier (le "cerveau")



│   │   ├── AppointmentService.php   # règles de réservation



│   │   ├── NotificationService.php  # envoi email/SMS



│   │   ├── ReviewService.php



│   │   └── SubscriptionService.php  # Stripe



│   ├── Security/           # Authentification, permissions



│   └── EventListener/      # Réactions automatiques (ex: envoyer un mail)



├── tests/                  # Tests automatisés



└── ARCHITECTURE.md         # ce fichier



Le flux d'une requête, en une phrase : une requête arrive → le Controller la reçoit → il appelle un Service (la logique) → le Service utilise un Repository pour lire/écrire des Entities en base → la réponse repart en JSON.



## 4. Modèle de données

C'est le cœur du back-end. Voici toutes les entités, leurs champs principaux et leurs relations.

### User (utilisateur de base)

Tous les utilisateurs partagent ces champs. On distingue ensuite clients et artisans par un rôle.



- id, email (unique), password (haché), firstName, lastName

- phone (pour les rappels de RDV), roles (ROLE_CUSTOMER, ROLE_ARTISAN, ROLE_ADMIN)

- isVerified (bool), createdAt

### Customer (profil client)

- relation OneToOne avec User

- homeAddress

- relations : favorites (liste de Business), appointments, documents

### Artisan (profil artisan)

- relation OneToOne avec User

- siret, officeAddress, isApproved (bool — validé par un admin avant activation)

- ownershipDocument (justificatif de propriété de l'entreprise)

- relation OneToOne avec Business

- relation avec Subscription (abonnement payant)



Règle métier importante (cahier des charges) : un artisan ne peut s'inscrire qu'avec un justificatif certifié, validé par un admin avant la création effective du compte. Le champ isApproved gère ça.

### Business (fiche entreprise)

- name, headline (accroche), description, coverImage

- website, paymentMethods, contactNumber, officeAddress

- workingHours, replyDelay

- relation ManyToOne avec ArtisanCategory

- relations : services, reviews, appointments

### Service (prestation)

- name, description, duration (minutes), price, location (domicile/atelier)

- faq (liste question/réponse)

- relation ManyToOne avec Business

### Appointment (rendez-vous)

- dateTime, status (PENDING, CONFIRMED, CANCELLED, COMPLETED)

- location (domicile client ou atelier), customerNote

- relations : customer, service, business

- relation OneToOne optionnelle avec Invoice



Règle métier : un RDV est créé en PENDING. Il ne devient CONFIRMED que si l'artisan l'accepte. Tout changement déclenche une notification (email/SMS) au client.

### Review (avis — bidirectionnel)

- rating (global), punctualityRating, qualityRating, comment, createdAt

- authorType (CUSTOMER ou ARTISAN) — car le client note l'artisan ET l'inverse

- relations : appointment, author, target



Règle métier : un avis n'est possible qu'après une prestation terminée (COMPLETED).

### CalendarEvent (événement d'agenda)

- title, description, startDate, endDate, type (PERSONAL ou APPOINTMENT)

- isAvailability (bool — marque une indisponibilité)

- relation ManyToOne avec Artisan

### Conversation + Message (messagerie)

- Conversation : relations customer ↔ business

- Message : content, sentAt, isRead, attachment (image), relation sender

- possibilité de bloquer un utilisateur (isBlocked)

### Invoice (facture)

- number, amount, issuedAt, pdfPath

- relations : appointment, customer

### Subscription (abonnement artisan — Stripe)

- stripeSubscriptionId, status, currentPeriodEnd, plan

- relation OneToOne avec Artisan

### ArtisanCategory (catégorie de métier)

- name, icon, slug

- gérable par l'admin (création de nouvelles catégories)

### Notification

- type, content, isRead, createdAt, relation user



## 5. Authentification & sécurité (recommandation)

Choix : Symfony Security + JWT.



- À la connexion, l'utilisateur envoie email + mot de passe → le serveur renvoie un token JWT.

- Le front (web ou mobile) renvoie ce token à chaque requête (dans l'en-tête Authorization).

- Avantage : une seule API sert le site et l'app mobile, sans gérer de sessions serveur.



Règles de permissions à implémenter :



- ROLE_CUSTOMER : réserver, gérer ses RDV, laisser des avis, messagerie.

- ROLE_ARTISAN : tout le dashboard (agenda, clients, présentation) — réservé après isApproved.

- ROLE_ADMIN : panel admin (KPIs, gestion users/catégories, bannissement).



Mots de passe : toujours hachés (Symfony le fait automatiquement avec le PasswordHasher). Réinitialisation par email : composant symfonycasts/reset-password-bundle.



Limiter les faux comptes (cahier des charges) : vérification d'email obligatoire pour les clients (isVerified), et validation manuelle des justificatifs pour les artisans (isApproved).



## 6. Paiement (recommandation)

Choix : Stripe, pour l'abonnement payant des artisans.



- Création d'un « Customer » Stripe à l'inscription artisan.

- Abonnement récurrent (mensuel/annuel) qui débloque l'accès au dashboard.

- Webhooks Stripe : Stripe prévient ton serveur quand un paiement réussit/échoue, et le SubscriptionService met à jour le statut.



Important (règle de sécurité) : aucune donnée bancaire ne transite par ton serveur. Stripe gère tout via ses propres formulaires sécurisés. Tu ne stockes que des identifiants Stripe.



## 7. Endpoints API principaux

Voici les routes à exposer (format REST). Claude Code peut générer les contrôleurs correspondants.



# Auth



POST   /api/register/customer       Inscription client



POST   /api/register/artisan        Inscription artisan (en attente de validation)



POST   /api/login                   Connexion → renvoie un JWT



POST   /api/password/reset          Réinitialisation mot de passe



# Recherche & fiches



GET    /api/search                  Recherche artisans (filtres: métier, ville, prix, note...)



GET    /api/businesses/{id}         Fiche d'un artisan



GET    /api/categories              Liste des catégories



# Réservation



POST   /api/appointments            Créer une demande de RDV (status PENDING)



PATCH  /api/appointments/{id}       Accepter / modifier / annuler



GET    /api/appointments            Mes RDV (client ou artisan)



# Avis



POST   /api/reviews                 Laisser un avis (après prestation COMPLETED)



# Messagerie



GET    /api/conversations           Mes conversations



POST   /api/messages                Envoyer un message



# Dashboard artisan



GET    /api/artisan/dashboard       KPIs (CA, RDV, vues...)



GET    /api/artisan/calendar        Agenda



PUT    /api/artisan/business        Modifier la présentation



GET    /api/artisan/clients         Base clients



# Admin



GET    /api/admin/stats             KPIs plateforme



POST   /api/admin/categories        Créer une catégorie



PATCH  /api/admin/users/{id}        Bannir / gérer



## 8. Feuille de route (par étapes)

Construis dans cet ordre — chaque étape s'appuie sur la précédente :



- Squelette + config : projet Symfony, base de données connectée, CORS configuré.

- Modèle de données : toutes les entités + premières migrations.

- Authentification : inscription, connexion JWT, rôles.

- Fiches & recherche : entités Business/Service/Category + endpoints de recherche.

- Réservation : création de RDV, validation par l'artisan, notifications.

- Avis + messagerie.

- Dashboard artisan + panel admin.

- Stripe (abonnements).

- Tests + données de démonstration (fixtures).



## 9. Conseils pour travailler avec Claude Code (débutante)

- Avance étape par étape. Ne demande pas tout d'un coup. Commence par : « Initialise le squelette Symfony et connecte une base PostgreSQL », vérifie que ça marche, puis continue.

- Demande des explications. Claude Code peut commenter le code et t'expliquer chaque concept. N'hésite pas à dire : « explique-moi ce fichier ligne par ligne ».

- Teste souvent. Après chaque étape, lance symfony server:start et vérifie.

- Utilise Git. Demande à Claude Code d'initialiser un dépôt Git dès le début et de committer après chaque étape réussie — comme ça tu peux toujours revenir en arrière.

- Garde ce fichier à jour. À chaque décision d'architecture, note-la ici. C'est ta mémoire projet et celle de Claude Code.



## 10. Livrables attendus (rappel du cahier des charges)

À conserver tout au long du projet : planning prévisionnel, arborescence finale, code source, base de données, éléments graphiques, et un document technique (hébergement, fonctionnement, schémas). Tu es propriétaire du code, de la base et de tout élément graphique créé.

