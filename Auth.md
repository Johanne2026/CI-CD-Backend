# Auth API — Guide d'intégration Frontend

Ce document décrit comment connecter un frontend au backend Laravel via l'API d'authentification Sanctum.

---

## Configuration requise

### Variable d'environnement

Dans le fichier `.env` du backend, l'URL du frontend doit être déclarée :

```env
FRONTEND_URL=http://localhost:5173
CORS_ALLOWED_ORIGINS=http://localhost:5173
```

En production, remplace ces valeurs par l'URL réelle du frontend. Si tu as plusieurs origines :

```env
CORS_ALLOWED_ORIGINS=https://monapp.com,https://staging.monapp.com
```

### URL de base de l'API

Toutes les requêtes pointent vers :

```
http://localhost:8000/api
```

---

## Headers obligatoires

Toutes les requêtes doivent inclure :

```
Accept: application/json
Content-Type: application/json
```

Pour les routes protégées, ajoute également :

```
Authorization: Bearer <token>
```

---

## Endpoints

### POST /api/register

Crée un compte et retourne un token.

**Body :**
```json
{
  "nom": "John Doe",
  "email": "john@example.com",
  "mot_de_passe": "motdepasse123",
  "mot_de_passe_confirmation": "motdepasse123"
}
```

**Réponse 201 :**
```json
{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "nom": "John Doe",
    "email": "john@example.com",
    "created_at": "2026-05-19T10:00:00.000000Z",
    "updated_at": "2026-05-19T10:00:00.000000Z"
  }
}
```

---

### POST /api/login

Authentifie un utilisateur et retourne un token.

**Body :**
```json
{
  "email": "john@example.com",
  "mot_de_passe": "motdepasse123"
}
```

**Réponse 200 :**
```json
{
  "token": "2|xyz456...",
  "user": {
    "id": 1,
    "nom": "John Doe",
    "email": "john@example.com",
    "created_at": "2026-05-19T10:00:00.000000Z",
    "updated_at": "2026-05-19T10:00:00.000000Z"
  }
}
```

**Réponse 422 (credentials invalides) :**
```json
{
  "message": "These credentials do not match our records.",
  "errors": {
    "email": ["These credentials do not match our records."]
  }
}
```

---

### GET /api/user — 🔒 Protégé

Retourne l'utilisateur actuellement authentifié.

**Réponse 200 :**
```json
{
  "id": 1,
  "nom": "John Doe",
  "email": "john@example.com",
  "created_at": "2026-05-19T10:00:00.000000Z",
  "updated_at": "2026-05-19T10:00:00.000000Z"
}
```

---

### POST /api/logout — 🔒 Protégé

Révoque le token actuel.

**Réponse 200 :**
```json
{
  "message": "Déconnexion réussie."
}
```

---

## Gestion du token côté frontend

1. Après login ou register, stocke le token retourné (`localStorage`, `sessionStorage`, ou store mémoire).
2. Injecte-le dans chaque requête protégée via `Authorization: Bearer <token>`.
3. À la déconnexion, appelle `POST /api/logout` puis supprime le token du stockage.

### Exemple avec axios

```js
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000/api',
  headers: { 'Accept': 'application/json' },
});

// Injecter le token automatiquement
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Register
const { data } = await api.post('/register', {
  nom: 'John Doe',
  email: 'john@example.com',
  mot_de_passe: 'motdepasse123',
  mot_de_passe_confirmation: 'motdepasse123',
});
localStorage.setItem('token', data.token);

// Login
const { data } = await api.post('/login', {
  email: 'john@example.com',
  mot_de_passe: 'motdepasse123',
});
localStorage.setItem('token', data.token);

// Logout
await api.post('/logout');
localStorage.removeItem('token');
```

---

## Règles de validation

| Champ                       | Règles                                                  |
|-----------------------------|---------------------------------------------------------|
| `nom`                       | Requis, string, max 255 caractères                      |
| `email`                     | Requis, email valide, unique, en minuscules             |
| `mot_de_passe`              | Requis, min 8 caractères, doit être confirmé            |
| `mot_de_passe_confirmation` | Requis, doit correspondre à `mot_de_passe`              |

Les erreurs de validation retournent un statut **422** :

```json
{
  "message": "The nom field is required.",
  "errors": {
    "nom": ["The nom field is required."]
  }
}
```

---

## Codes de statut HTTP

| Code | Signification                             |
|------|-------------------------------------------|
| 200  | Succès                                    |
| 201  | Ressource créée (register)                |
| 401  | Non authentifié (token manquant/invalide) |
| 422  | Erreur de validation                      |
| 500  | Erreur serveur                            |
