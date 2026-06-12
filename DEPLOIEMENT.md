# Déploiement — démo en ligne de Slito

Ce document explique comment mettre en ligne une **démo gratuite** de Slito :

- **Frontend** (Next.js) → hébergé sur [Vercel](https://vercel.com)
- **Backend** (Symfony API) + **base de données PostgreSQL** → hébergés sur [Render](https://render.com)

Les deux plateformes proposent un plan gratuit sans carte bancaire.

> Ce guide est écrit pour être suivi pas à pas, dans l'ordre. Les noms exacts
> des boutons dans les interfaces Vercel/Render peuvent varier légèrement
> selon les évolutions de ces sites — cherche l'équivalent le plus proche si
> un libellé a changé.

---

## ⚠️ Limites de cette démo (à garder en tête)

Cette configuration est pensée pour **montrer rapidement le projet en ligne**,
pas pour un vrai lancement commercial :

- **Le backend "s'endort"** après 15 min sans trafic (plan gratuit Render) :
  la première requête après une pause peut prendre ~1 minute.
- **La base de données gratuite expire après 30 jours** (+ 14 jours de
  grâce), puis est supprimée. Il faudra la recréer ou passer sur un plan payant
  pour une démo durable.
- **Les emails ne sont pas réellement envoyés** (`MAILER_DSN=null://null`) :
  vérification de compte, réinitialisation de mot de passe, notifications...
  partent "dans le vide". Pour les tester, il faudra configurer un vrai
  service d'envoi (ex. Brevo, Mailjet, SMTP...).
- **Stripe est en valeurs factices** (`sk_test_changeme`...) : la page
  d'abonnement artisan ne fonctionnera pas tant qu'un vrai compte Stripe en
  mode test n'est pas branché (voir étape 6, optionnelle).
- **Les clés JWT sont régénérées à chaque redémarrage du conteneur**
  (disque non persistant sur le plan gratuit) : après un redéploiement ou un
  réveil du service, les utilisateurs déjà connectés devront se reconnecter.
- **SMS** : non implémenté (hors périmètre, comme noté dans le code).

Rien de tout cela n'empêche une démo de fonctionner pour naviguer, créer un
compte, rechercher des artisans, réserver, laisser un avis, etc.

---

## Étape 1 — Créer les dépôts GitHub et y pousser le code

### 1.1 Créer un compte GitHub (si pas déjà fait)

Va sur [github.com/signup](https://github.com/signup) — gratuit, pas de carte
bancaire requise.

### 1.2 Créer un token d'accès personnel (nécessaire pour pousser le code)

GitHub n'accepte plus les mots de passe pour `git push`. Il faut un "token" :

1. Connecte-toi sur github.com, puis va sur
   [github.com/settings/tokens](https://github.com/settings/tokens)
2. **Generate new token** → **Generate new token (classic)**
3. Donne-lui un nom (ex. "slito-deploy"), une expiration (ex. 30 jours), et
   cocoche la case **repo** (accès complet aux dépôts)
4. Clique **Generate token** et **copie-le immédiatement** (il ne sera plus
   affiché ensuite). Garde-le à portée pour les commandes ci-dessous.

### 1.3 Créer les deux dépôts (vides)

Sur GitHub, clique **New repository** (ou [github.com/new](https://github.com/new)) :

- Nom : `slito-backend` — visibilité Public ou Private (au choix) —
  **ne pas** cocher "Add a README", "Add .gitignore" ou "Choose a license"
  (le dépôt doit rester vide)
- Répète pour `slito-frontend`

### 1.4 Pousser le code existant

Dans le terminal, remplace `TON-NOM-UTILISATEUR` par ton nom d'utilisateur
GitHub :

```bash
cd "/Users/marinerossio/Architecture Slito Claude/slito-backend"
git remote add origin https://github.com/TON-NOM-UTILISATEUR/slito-backend.git
git branch -M main
git push -u origin main
```

```bash
cd "/Users/marinerossio/Architecture Slito Claude/slito-frontend"
git remote add origin https://github.com/TON-NOM-UTILISATEUR/slito-frontend.git
git branch -M main
git push -u origin main
```

À la première commande `git push`, le terminal demande un nom d'utilisateur
et un mot de passe :

- **Username** : ton nom d'utilisateur GitHub
- **Password** : colle le **token** créé à l'étape 1.2 (pas ton mot de passe
  GitHub)

macOS retient généralement ce token dans le Trousseau d'accès après la
première utilisation — la deuxième commande ne devrait pas redemander.

---

## Étape 2 — Déployer le backend sur Render

### 2.1 Créer un compte Render

Va sur [render.com](https://render.com) → **Get Started** → connecte-toi avec
GitHub (recommandé : autorise Render à accéder à tes dépôts).

### 2.2 Créer le "Blueprint"

Le dépôt `slito-backend` contient un fichier `render.yaml` qui décrit
automatiquement les deux services nécessaires (API + base de données).

1. Sur le tableau de bord Render, clique **New +** → **Blueprint**
2. Sélectionne le dépôt `slito-backend`
3. Render détecte `render.yaml` et propose de créer :
   - un service web `slito-backend` (Docker)
   - une base de données `slito-db` (PostgreSQL, plan gratuit)
4. Vérifie que le plan affiché est bien **Free** pour les deux, puis clique
   **Apply** / **Create**

### 2.3 Attendre le premier déploiement

Le premier build peut prendre **5 à 10 minutes** (installation des extensions
PHP, dépendances Composer...). Tu peux suivre la progression dans l'onglet
**Logs** du service `slito-backend`.

Si le build échoue, copie le message d'erreur affiché dans les logs — il sera
utile pour corriger.

### 2.4 Récupérer l'URL du backend

Une fois déployé, Render attribue une URL du type :

```
https://slito-backend-XXXX.onrender.com
```

**Note cette URL**, elle sera utilisée à l'étape 3.

> Astuce vérification : ouvre `https://slito-backend-XXXX.onrender.com/api/categories`
> dans le navigateur. Tu dois voir une réponse JSON (probablement une liste
> vide `[]` si aucune donnée n'a encore été créée) — pas une page d'erreur.

---

## Étape 3 — Déployer le frontend sur Vercel

### 3.1 Créer un compte Vercel

Va sur [vercel.com](https://vercel.com) → **Sign Up** → connecte-toi avec
GitHub.

### 3.2 Importer le projet

1. **Add New** → **Project**
2. Sélectionne le dépôt `slito-frontend` → **Import**
3. Vercel détecte automatiquement Next.js (aucune configuration de build à
   changer)

### 3.3 Ajouter la variable d'environnement

Avant de cliquer sur "Deploy", dans la section **Environment Variables** :

| Name | Value |
|---|---|
| `NEXT_PUBLIC_API_URL` | `https://slito-backend-XXXX.onrender.com` (l'URL notée à l'étape 2.4, **sans `/` final**) |

Clique **Deploy**.

### 3.4 Récupérer l'URL du frontend

Une fois déployé, Vercel attribue une URL du type :

```
https://slito-frontend-XXXX.vercel.app
```

**Note cette URL**, elle est utilisée à l'étape suivante.

---

## Étape 4 — Reboucler : autoriser le frontend à appeler le backend

Le backend doit savoir que le frontend a le droit de l'appeler (CORS), et
connaître son adresse pour générer des liens (réinitialisation de mot de
passe, retour Stripe).

1. Retourne sur le tableau de bord **Render** → service `slito-backend` →
   onglet **Environment**
2. Modifie les 4 variables suivantes en remplaçant `CHANGE-MOI` par le nom de
   ton projet Vercel (la partie avant `.vercel.app` dans l'URL notée à
   l'étape 3.4) :

| Variable | Nouvelle valeur (exemple) |
|---|---|
| `CORS_ALLOW_ORIGIN` | `^https://slito-frontend(-[a-zA-Z0-9-]+)*\.vercel\.app$` |
| `FRONTEND_RESET_PASSWORD_URL` | `https://slito-frontend-XXXX.vercel.app/mot-de-passe-oublie` |
| `STRIPE_CHECKOUT_SUCCESS_URL` | `https://slito-frontend-XXXX.vercel.app/artisan/abonnement?paiement=succes` |
| `STRIPE_CHECKOUT_CANCEL_URL` | `https://slito-frontend-XXXX.vercel.app/artisan/abonnement?paiement=annule` |

> Le motif `CORS_ALLOW_ORIGIN` est une expression régulière : la partie
> `(-[a-zA-Z0-9-]+)*` autorise aussi bien l'URL de production
> (`slito-frontend.vercel.app`) que les URLs de prévisualisation Vercel
> (`slito-frontend-git-main-tonpseudo.vercel.app`).

3. Clique **Save Changes** → Render redéploie automatiquement (plus rapide
   que le premier build, l'image Docker est réutilisée).

---

## Étape 5 — Vérifier la démo de bout en bout

Ouvre `https://slito-frontend-XXXX.vercel.app` et teste :

- [ ] La page d'accueil s'affiche (palette terracotta/forêt/crème)
- [ ] La recherche d'artisans fonctionne (même si la liste est vide au
      début — c'est normal, la base est neuve)
- [ ] Créer un compte client (`/inscription`)
- [ ] Se connecter (`/connexion`)
- [ ] Si la première requête est lente (~1 min) : c'est le réveil du
      backend après inactivité, normal sur le plan gratuit

Si la recherche reste vide en permanence et que `/api/categories` (étape 2.4)
renvoie bien des données, vérifie dans les logs Vercel/Render que
`NEXT_PUBLIC_API_URL` et `CORS_ALLOW_ORIGIN` sont corrects (erreurs CORS
visibles dans la console du navigateur, F12 → Network/Console).

---

## Étape 6 (optionnelle) — Brancher un vrai Stripe en mode test

Pour que la page d'abonnement artisan fonctionne :

1. Crée un compte sur [stripe.com](https://stripe.com) (gratuit, mode test
   disponible sans carte bancaire)
2. Dans le Dashboard Stripe (mode **Test**), récupère la clé secrète
   (`sk_test_...`) et crée deux **Prices** (abonnement mensuel / annuel) pour
   récupérer leurs identifiants (`price_...`)
3. Sur Render → `slito-backend` → Environment, remplace :
   - `STRIPE_SECRET_KEY` → ta clé `sk_test_...`
   - `STRIPE_PRICE_MONTHLY` / `STRIPE_PRICE_YEARLY` → tes `price_...`
4. Pour le webhook (`STRIPE_WEBHOOK_SECRET`) : dans Stripe Dashboard →
   Developers → Webhooks → ajoute un endpoint
   `https://slito-backend-XXXX.onrender.com/api/subscriptions/webhook`,
   sélectionne les événements d'abonnement, puis copie le "Signing secret"
   (`whsec_...`) dans la variable `STRIPE_WEBHOOK_SECRET`

---

## Pour aller plus loin (au-delà de la démo)

Cette configuration "démo" diffère d'un vrai lancement sur les points
suivants (voir aussi `ARCHITECTURE.md`, qui impose un hébergement UE) :

- Hébergement payant (pas de mise en veille, base de données persistante,
  sauvegardes)
- Vrai fournisseur d'emails (SMTP/API) pour les notifications et la
  vérification de compte
- Stripe en mode production (clés `sk_live_...`)
- Pages légales (mentions légales, CGU, politique de confidentialité,
  cookies) — actuellement absentes du frontend
- CI/CD (tests automatiques avant déploiement)
- Stockage de fichiers pour les justificatifs artisans (`Document`), non
  implémenté actuellement
