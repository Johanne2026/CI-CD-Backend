# Guide d'intégration Frontend React — Fonctionnalité Projets

Ce document décrit tout ce que le frontend React doit implémenter pour la gestion des projets.

> Prérequis : avoir configuré le client Axios et le contexte Auth décrits dans `FRONTEND_INTEGRATION.md`.

---

## Table en base de données — `Projets`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigint | Clé primaire |
| `equipe_id` | bigint FK unique | Référence vers `Equipes.id` — une équipe = un seul projet |
| `cree_par_id` | bigint FK | Référence vers `Utilisateurs.id` |
| `nom` | string | Nom du projet |
| `description` | text nullable | Description du projet |
| `stack_technologique` | JSON | Liste des technologies (ex: `["Laravel", "React", "Docker"]`) |
| `actif` | boolean | `true` = actif, `false` = archivé |
| `duree_projet` | string nullable | Durée estimée (ex: `"6 mois"`, `"3 semaines"`) |
| `url_depot` | string nullable | URL du dépôt GitHub lié au projet |
| `lie_a_un_depot` | boolean (calculé) | `true` si `url_depot` est renseigné, `false` sinon |
| `date_creation` | timestamp | Renseigné automatiquement à la création |
| `date_mise_a_jour` | timestamp | Mis à jour automatiquement à chaque modification |

---

## Règles métier

- Seul l'**administrateur** peut créer, modifier, archiver et supprimer un projet
- Une équipe ne peut avoir qu'**un seul projet** (contrainte unique sur `equipe_id`)
- L'archivage bascule le champ `actif` : `true → false` (archivé) ou `false → true` (réactivé)
- Les utilisateurs `administrateur_cloud_doi` et `securite` voient uniquement les projets des équipes dont ils sont membres

---

## Types TypeScript

Crée un fichier `src/types/projets.ts` :

```ts
export interface Projet {
  id: number;
  equipe_id: number;
  cree_par_id: number;
  nom: string;
  description: string | null;
  stack_technologique: string[];
  actif: boolean;
  duree_projet: string | null;
  url_depot: string | null;        // URL du dépôt GitHub
  lie_a_un_depot: boolean;         // true si url_depot est renseigné
  date_creation: string;
  date_mise_a_jour: string;
  equipe: { id: number; nom: string; };
  cree_par: { id: number; nom: string; prenom: string; };
}

export interface CreateProjetPayload {
  equipe_id: number;
  nom: string;
  description?: string;
  stack_technologique?: string[];
  duree_projet?: string;
}

export interface UpdateProjetPayload {
  nom?: string;
  description?: string;
  stack_technologique?: string[];
  duree_projet?: string;
}
```

---

## Endpoints

| Méthode | Endpoint | Auth | Rôle requis | Description |
|---|---|---|---|---|
| `GET` | `/api/projets` | Oui | Tous | Liste les projets (filtrés par rôle) |
| `GET` | `/api/projets/{id}` | Oui | Tous (si membre de l'équipe) | Détail d'un projet |
| `POST` | `/api/projets` | Oui | `administrateur` | Créer un projet |
| `PUT` | `/api/projets/{id}` | Oui | `administrateur` | Modifier un projet |
| `PATCH` | `/api/projets/{id}/archiver` | Oui | `administrateur` | Archiver ou réactiver |
| `DELETE` | `/api/projets/{id}` | Oui | `administrateur` | Supprimer définitivement |
| `POST` | `/api/projets/{id}/connecter-depot` | Oui | Tous | Lier un dépôt GitHub au projet |

---

## GET /api/projets — Lister les projets

**Comportement selon le rôle :**
- `administrateur` → tous les projets
- `administrateur_cloud_doi` / `securite` → uniquement les projets des équipes dont l'utilisateur est membre

**Réponse 200 :**
```json
[
  {
    "id": 1,
    "equipe_id": 1,
    "cree_par_id": 1,
    "nom": "Projet CI/CD",
    "description": "Pipeline automatisé",
    "stack_technologique": ["Laravel", "React", "Docker", "GitHub Actions"],
    "actif": true,
    "duree_projet": "6 mois",
    "date_creation": "2026-05-25T00:58:34.000000Z",
    "date_mise_a_jour": "2026-05-25T00:58:34.000000Z",
    "equipe": { "id": 1, "nom": "Equipe Alpha" },
    "cree_par": { "id": 1, "nom": "Emmy", "prenom": "Admin" }
  }
]
```

---

## GET /api/projets/{id} — Détail d'un projet

**Réponse 200 :** même structure qu'un élément du tableau ci-dessus.

**Réponse 403 si l'utilisateur n'est pas membre de l'équipe du projet :**
```json
{ "message": "Accès refusé." }
```

---

## POST /api/projets — Créer un projet `[admin]`

**Body :**
```json
{
  "equipe_id": 1,
  "nom": "Projet CI/CD",
  "description": "Pipeline automatisé de déploiement",
  "stack_technologique": ["Laravel", "React", "Docker", "GitHub Actions"],
  "duree_projet": "6 mois"
}
```

**Réponse 201 :** le projet créé.

**Réponse 422 si l'équipe a déjà un projet :**
```json
{
  "message": "The equipe id has already been taken.",
  "errors": {
    "equipe_id": ["The equipe id has already been taken."]
  }
}
```

**Règles de validation :**

| Champ | Règles |
|---|---|
| `equipe_id` | Requis, entier, doit exister dans `Equipes`, unique (une équipe = un projet) |
| `nom` | Requis, string, max 255 |
| `description` | Optionnel, string |
| `stack_technologique` | Optionnel, tableau de strings |
| `duree_projet` | Optionnel, string, max 255 |

---

## PUT /api/projets/{id} — Modifier un projet `[admin]`

**Body (tous les champs sont optionnels) :**
```json
{
  "nom": "Nouveau nom",
  "description": "Nouvelle description",
  "stack_technologique": ["Laravel", "Vue.js", "Kubernetes"],
  "duree_projet": "8 mois"
}
```

**Réponse 200 :** le projet mis à jour.

---

## PATCH /api/projets/{id}/archiver — Archiver / Réactiver `[admin]`

Bascule le champ `actif` : si le projet est actif il est archivé, s'il est archivé il est réactivé.

**Réponse 200 — archivage :**
```json
{
  "message": "Projet archivé.",
  "projet": { "...": "projet complet avec actif: false" }
}
```

**Réponse 200 — réactivation :**
```json
{
  "message": "Projet réactivé.",
  "projet": { "...": "projet complet avec actif: true" }
}
```

---

## DELETE /api/projets/{id} — Supprimer un projet `[admin]`

**Réponse 200 :**
```json
{ "message": "Projet supprimé." }
```

---

## Réponse 403 — Accès refusé

```json
{ "message": "Accès refusé. Cette action est réservée aux administrateurs." }
```

---

## Exemple d'implémentation React

### Hook `useProjets`

Crée `src/hooks/useProjets.ts` :

```ts
import { useState, useEffect } from 'react';
import api from '@/lib/api';
import { Projet, CreateProjetPayload, UpdateProjetPayload } from '@/types/projets';

export function useProjets() {
  const [projets, setProjets]     = useState<Projet[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError]         = useState<string | null>(null);

  const fetchProjets = async () => {
    setIsLoading(true);
    try {
      const { data } = await api.get<Projet[]>('/projets');
      setProjets(data);
    } catch {
      setError('Impossible de charger les projets.');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => { fetchProjets(); }, []);

  const creerProjet = async (payload: CreateProjetPayload): Promise<Projet> => {
    const { data } = await api.post<Projet>('/projets', payload);
    setProjets((prev) => [...prev, data]);
    return data;
  };

  const modifierProjet = async (id: number, payload: UpdateProjetPayload): Promise<Projet> => {
    const { data } = await api.put<Projet>(`/projets/${id}`, payload);
    setProjets((prev) => prev.map((p) => (p.id === id ? data : p)));
    return data;
  };

  const archiverProjet = async (id: number): Promise<Projet> => {
    const { data } = await api.patch<{ message: string; projet: Projet }>(`/projets/${id}/archiver`);
    setProjets((prev) => prev.map((p) => (p.id === id ? data.projet : p)));
    return data.projet;
  };

  const supprimerProjet = async (id: number): Promise<void> => {
    await api.delete(`/projets/${id}`);
    setProjets((prev) => prev.filter((p) => p.id !== id));
  };

  return {
    projets,
    isLoading,
    error,
    creerProjet,
    modifierProjet,
    archiverProjet,
    supprimerProjet,
    refresh: fetchProjets,
  };
}
```

---

### Logique d'affichage selon le rôle

```tsx
import { useAuth } from '@/context/AuthContext';
import { useProjets } from '@/hooks/useProjets';

export default function ProjetsPage() {
  const { user } = useAuth();
  const { projets, isLoading, archiverProjet } = useProjets();
  const isAdmin = user?.role === 'administrateur';

  if (isLoading) return <div>Chargement...</div>;

  return (
    <div>
      {isAdmin && (
        <button onClick={() => { /* ouvrir modal création */ }}>
          Créer un projet
        </button>
      )}

      {projets.map((projet) => (
        <div key={projet.id} style={{ opacity: projet.actif ? 1 : 0.5 }}>
          <h2>{projet.nom} {!projet.actif && <span>(Archivé)</span>}</h2>
          <p>{projet.description}</p>
          <p>Équipe : {projet.equipe.nom}</p>
          <p>Durée : {projet.duree_projet ?? 'Non définie'}</p>
          <p>Technologies : {projet.stack_technologique.join(', ')}</p>
          <p>Créé par : {projet.cree_par.nom} {projet.cree_par.prenom}</p>

          {isAdmin && (
            <>
              <button onClick={() => { /* modifier */ }}>Modifier</button>
              <button onClick={() => archiverProjet(projet.id)}>
                {projet.actif ? 'Archiver' : 'Réactiver'}
              </button>
              <button onClick={() => { /* supprimer */ }}>Supprimer</button>
            </>
          )}
        </div>
      ))}
    </div>
  );
}
```

---

## Codes HTTP

| Code | Signification |
|---|---|
| `200` | Succès |
| `201` | Projet créé |
| `403` | Accès refusé (rôle insuffisant ou non membre de l'équipe) |
| `404` | Projet introuvable |
| `422` | Erreur de validation (ex: équipe déjà associée à un projet) |
| `500` | Erreur serveur |

---

## Connexion GitHub — POST /api/projets/{id}/connecter-depot

Appelé lorsque l'utilisateur soumet le formulaire "Connecter GitHub" depuis un projet.
Enregistre l'URL du dépôt sur le projet **et** les identifiants GitHub sur l'utilisateur connecté en une seule requête.

**Body :**
```json
{
  "url_depot":           "https://github.com/organisation/mon-repo",
  "username_outil_cicd": "mon-username-github",
  "token_outil_cicd":    "ghp_xxxxxxxxxxxxxxxxxxxx"
}
```

**Réponse 200 :**
```json
{
  "message": "Projet lié au dépôt GitHub avec succès.",
  "projet": {
    "id": 1,
    "nom": "Projet CI/CD",
    "url_depot": "https://github.com/organisation/mon-repo",
    "lie_a_un_depot": true,
    "...": "autres champs du projet"
  }
}
```

**Règles de validation :**

| Champ | Règles |
|---|---|
| `url_depot` | Requis, URL valide, max 500 caractères |
| `username_outil_cicd` | Requis, string, max 255 |
| `token_outil_cicd` | Requis, string, max 255 |

---

## Indicateur "Lié à un dépôt"

Chaque projet retourné par l'API inclut le champ calculé `lie_a_un_depot` :
- `true` → le projet a une `url_depot` renseignée → afficher **"Lié à un dépôt"**
- `false` → pas de dépôt lié → afficher le bouton **"Connecter GitHub"**

```tsx
{projet.lie_a_un_depot ? (
  <span className="text-green-600 font-medium">✓ Lié à un dépôt</span>
) : (
  <button onClick={() => navigate(`/projects/${projet.id}/github`)}>
    Connecter GitHub
  </button>
)}
```

---

Lorsqu'un utilisateur clique sur **"Connecter GitHub"** dans un projet, le frontend envoie ses identifiants GitHub. Le backend met à jour les colonnes `username_outil_cicd` et `token_outil_cicd` dans la table `Utilisateurs`.

**Méthode :** `PUT /api/user` — protégée par `auth:api`

**Body :**
```json
{
  "username_outil_cicd": "mon-username-github",
  "token_outil_cicd": "ghp_xxxxxxxxxxxxxxxxxxxx"
}
```

**Réponse 200 — utilisateur mis à jour (`token_outil_cicd` masqué) :**
```json
{
  "id": 1,
  "nom": "Emmy",
  "prenom": "Admin",
  "email": "emmy@gmail.com",
  "username_outil_cicd": "mon-username-github",
  "role": "administrateur",
  "date_inscription": "2026-05-25T00:00:00.000000Z",
  "created_at": "2026-05-25T00:00:00.000000Z",
  "updated_at": "2026-05-25T10:30:00.000000Z"
}
```

> `token_outil_cicd` est masqué via `$hidden` et n'apparaît jamais dans les réponses.

**Règles de validation :**

| Champ | Règles |
|---|---|
| `username_outil_cicd` | Optionnel, string, max 255, peut être `null` |
| `token_outil_cicd` | Optionnel, string, max 255, peut être `null` |

> Les champs absents de la requête ne sont pas modifiés (`sometimes`).

**Exemple React :**

```ts
// src/hooks/useGithubConnect.ts
import api from '@/lib/api';

export async function connecterGithub(
  username: string,
  token: string
): Promise<void> {
  await api.put('/user', {
    username_outil_cicd: username,
    token_outil_cicd:    token,
  });
}
```

```tsx
// Dans le composant "Connecter GitHub"
import { useState } from 'react';
import { connecterGithub } from '@/hooks/useGithubConnect';

export default function ConnecterGithub() {
  const [username, setUsername] = useState('');
  const [token, setToken]       = useState('');
  const [success, setSuccess]   = useState(false);
  const [error, setError]       = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    try {
      await connecterGithub(username, token);
      setSuccess(true);
    } catch (err: any) {
      setError(err.response?.data?.message ?? 'Erreur de connexion GitHub.');
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <div>
        <label>Nom d'utilisateur GitHub</label>
        <input
          type="text"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
          placeholder="mon-username-github"
        />
      </div>
      <div>
        <label>Token GitHub</label>
        <input
          type="password"
          value={token}
          onChange={(e) => setToken(e.target.value)}
          placeholder="ghp_xxxxxxxxxxxxxxxxxxxx"
        />
      </div>
      <button type="submit">Connecter GitHub</button>
      {success && <p>GitHub connecté avec succès.</p>}
      {error   && <p style={{ color: 'red' }}>{error}</p>}
    </form>
  );
}
```

---

## Autres documentations

- **Auth** → voir `FRONTEND_INTEGRATION.md`
- **Équipes** → voir `EQUIPES_INTEGRATION.md`
