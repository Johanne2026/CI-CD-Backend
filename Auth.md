# Auth API — Documentation Backend

Ce document décrit les endpoints d'authentification exposés par ce backend Laravel.

---

## Table en base de données

**Nom :** `Utilisateurs`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigint | Clé primaire auto-incrémentée |
| `nom` | string | Nom de l'utilisateur |
| `prenom` | string | Prénom de l'utilisateur |
| `email` | string unique | Adresse email (identifiant de connexion) |
| `username_outil_cicd` | string unique nullable | Nom d'utilisateur GitHub (optionnel) |
| `mot_de_passe` | string | Mot de passe hashé (bcrypt) |
| `api_token` | string(64) nullable | Token d'authentification API (haché SHA-256) |
| `token_outil_cicd` | string nullable | Token GitHub fourni à la connexion |
| `date_inscription` | timestamp | Date d'inscription sur la plateforme |
| `role` | enum | `administrateur`, `administrateur_cloud_doi`, `securite` |
| `created_at` / `updated_at` | timestamp | Géré automatiquement par Laravel |

---

## URL de base

```
http://localhost:8000/api
```

## Headers obligatoires

```
Accept: application/json
Content-Type: application/json
```

Routes protégées :

```
Authorization: Bearer <token>
```

---

## Endpoints

### POST /api/register — Inscription

**Body :**
```json
{
  "nom": "Doe",
  "prenom": "John",
  "email": "john@example.com",
  "username_outil_cicd": "johndoe-github",
  "mot_de_passe": "motdepasse123",
  "mot_de_passe_confirmation": "motdepasse123",
  "role": "administrateur"
}
```

> `username_outil_cicd` est optionnel — peut être omis ou `null`.

**Réponse 201 :**
```json
{
  "token": "abc123...",
  "redirect_to": "/dashboard/admin",
  "user": {
    "id": 1,
    "nom": "Doe",
    "prenom": "John",
    "email": "john@example.com",
    "username_outil_cicd": "johndoe-github",
    "role": "administrateur",
    "date_inscription": "2026-05-24T14:00:00.000000Z",
    "created_at": "2026-05-24T14:00:00.000000Z",
    "updated_at": "2026-05-24T14:00:00.000000Z"
  }
}
```

---

### POST /api/login — Connexion

La connexion se fait avec l'`email` et le mot de passe.
Le `token_outil_cicd` (token GitHub) peut être fourni optionnellement.

**Body :**
```json
{
  "email": "john@example.com",
  "mot_de_passe": "motdepasse123",
  "token_outil_cicd": "ghp_xxxxxxxxxxxx"
}
```

**Réponse 200 :**
```json
{
  "token": "xyz456...",
  "redirect_to": "/dashboard/admin",
  "user": {
    "id": 1,
    "nom": "Doe",
    "prenom": "John",
    "email": "john@example.com",
    "username_outil_cicd": "johndoe-github",
    "role": "administrateur",
    "date_inscription": "2026-05-24T14:00:00.000000Z",
    "created_at": "2026-05-24T14:00:00.000000Z",
    "updated_at": "2026-05-24T14:00:00.000000Z"
  }
}
```

> `api_token`, `token_outil_cicd` et `mot_de_passe` sont exclus de toutes les réponses.

**Réponse 422 — Credentials invalides :**
```json
{
  "message": "These credentials do not match our records.",
  "errors": {
    "username_outil_cicd": ["These credentials do not match our records."]
  }
}
```

---

### GET /api/user — Utilisateur connecté 🔒

**Réponse 200 :**
```json
{
  "id": 1,
  "nom": "Doe",
  "prenom": "John",
  "username_outil_cicd": "johndoe-github",
  "role": "administrateur",
  "date_inscription": "2026-05-24T14:00:00.000000Z",
  "created_at": "2026-05-24T14:00:00.000000Z",
  "updated_at": "2026-05-24T14:00:00.000000Z"
}
```

---

### POST /api/logout — Déconnexion 🔒

Révoque le `api_token` et efface le `token_outil_cicd`.

**Réponse 200 :**
```json
{
  "message": "Déconnexion réussie."
}
```

---

## Redirection par rôle

| Rôle | `redirect_to` |
|---|---|
| `administrateur` | `/dashboard/admin` |
| `administrateur_cloud_doi` | `/dashboard/cloud-doi` |
| `securite` | `/dashboard/securite` |

---

## Règles de validation — register

| Champ | Règles |
|---|---|
| `nom` | Requis, string, max 255 |
| `prenom` | Requis, string, max 255 |
| `email` | Requis, email valide, unique, en minuscules |
| `username_outil_cicd` | Optionnel, string, max 255, unique si fourni |
| `mot_de_passe` | Requis, min 8 caractères, confirmé |
| `mot_de_passe_confirmation` | Requis, identique à `mot_de_passe` |
| `role` | Requis : `administrateur`, `administrateur_cloud_doi` ou `securite` |

## Règles de validation — login

| Champ | Règles |
|---|---|
| `email` | Requis, string, email valide |
| `mot_de_passe` | Requis, string |
| `token_outil_cicd` | Optionnel, string |

---

## Codes HTTP

| Code | Signification |
|---|---|
| `200` | Succès |
| `201` | Ressource créée (register) |
| `401` | Non authentifié — token absent ou invalide |
| `422` | Erreur de validation |
| `500` | Erreur serveur |
