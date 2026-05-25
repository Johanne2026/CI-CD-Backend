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
  stack_technologique: string[];  // tableau de technologies
  actif: boolean;                 // true = actif, false = archivé
  duree_projet: string | null;
  date_creation: string;          // ISO 8601
  date_mise_a_jour: string;       // ISO 8601
  equipe: {
    id: number;
    nom: string;
  };
  cree_par: {
    id: number;
    nom: string;
    prenom: string;
  };
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

## Autres documentations

- **Auth** → voir `FRONTEND_INTEGRATION.md`
- **Équipes** → voir `EQUIPES_INTEGRATION.md`
