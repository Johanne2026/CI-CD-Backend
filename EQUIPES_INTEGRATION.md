# Guide d'intégration Frontend React — Fonctionnalité Équipes

Ce document décrit tout ce que le frontend React doit implémenter pour la gestion des équipes.

> Prérequis : avoir configuré le client Axios et le contexte Auth décrits dans `FRONTEND_INTEGRATION.md`.

---

## Tables en base de données

### `Equipes`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigint | Clé primaire |
| `proprietaire_id` | bigint FK | Référence vers `Utilisateurs.id` |
| `nom` | string | Nom de l'équipe |
| `description` | text nullable | Description de l'équipe |
| `date_creation` | timestamp | Renseigné automatiquement à la création |
| `date_mise_a_jour` | timestamp | Mis à jour automatiquement à chaque modification |

### `membre_equipe`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigint | Clé primaire |
| `utilisateur_id` | bigint FK | Référence vers `Utilisateurs.id` |
| `equipe_id` | bigint FK | Référence vers `Equipes.id` |
| `role` | enum | `proprietaire` ou `membre` |
| `date_adhesion` | timestamp | Date d'intégration à l'équipe |

> Un utilisateur ne peut appartenir qu'une seule fois à une même équipe (contrainte unique sur `utilisateur_id + equipe_id`).

---

## Règles métier

- Seul l'**administrateur** peut créer, modifier et supprimer des équipes
- Seul l'**administrateur** peut ajouter ou retirer des membres
- Le **propriétaire** d'une équipe ne peut pas être retiré
- À la création d'une équipe, le propriétaire est automatiquement ajouté dans `membre_equipe` avec le rôle `proprietaire`
- Les utilisateurs avec le rôle `administrateur_cloud_doi` ou `securite` voient **uniquement** les équipes dont ils sont membres

---

## Types TypeScript

Crée un fichier `src/types/equipes.ts` :

```ts
import { User } from './auth';

export type RoleMembreEquipe = 'proprietaire' | 'membre';

export interface MembrePivot {
  equipe_id: number;
  utilisateur_id: number;
  role: RoleMembreEquipe;
  date_adhesion: string; // ISO 8601
}

export interface MembreEquipe extends User {
  pivot: MembrePivot;
}

export interface Equipe {
  id: number;
  proprietaire_id: number;
  nom: string;
  description: string | null;
  date_creation: string;   // ISO 8601
  date_mise_a_jour: string; // ISO 8601
  proprietaire: Pick<User, 'id' | 'nom' | 'prenom' | 'email'>;
  membres: MembreEquipe[];
}

export interface CreateEquipePayload {
  proprietaire_id: number;
  nom: string;
  description?: string;
}

export interface UpdateEquipePayload {
  nom?: string;
  description?: string;
}
```

---

## Endpoints

| Méthode | Endpoint | Auth | Rôle requis | Description |
|---|---|---|---|---|
| `GET` | `/api/equipes` | Oui | Tous | Liste les équipes (filtrées par rôle) |
| `GET` | `/api/equipes/{id}` | Oui | Tous (si membre) | Détail d'une équipe |
| `POST` | `/api/equipes` | Oui | `administrateur` | Créer une équipe |
| `PUT` | `/api/equipes/{id}` | Oui | `administrateur` | Modifier nom/description |
| `DELETE` | `/api/equipes/{id}` | Oui | `administrateur` | Supprimer une équipe |
| `GET` | `/api/equipes/{id}/utilisateurs-disponibles` | Oui | `administrateur` | Utilisateurs non encore membres |
| `POST` | `/api/equipes/{id}/membres` | Oui | `administrateur` | Ajouter un membre |
| `DELETE` | `/api/equipes/{id}/membres/{userId}` | Oui | `administrateur` | Retirer un membre |

---

## GET /api/equipes — Lister les équipes

**Comportement selon le rôle :**
- `administrateur` → toutes les équipes de la plateforme
- `administrateur_cloud_doi` / `securite` → uniquement les équipes dont l'utilisateur est membre

**Réponse 200 :**
```json
[
  {
    "id": 1,
    "proprietaire_id": 1,
    "nom": "Equipe Alpha",
    "description": "Equipe de test",
    "date_creation": "2026-05-24T18:00:00.000000Z",
    "date_mise_a_jour": "2026-05-24T18:00:00.000000Z",
    "proprietaire": {
      "id": 1,
      "nom": "Emmy",
      "prenom": "Admin",
      "email": "emmy@gmail.com"
    },
    "membres": [
      {
        "id": 1,
        "nom": "Emmy",
        "prenom": "Admin",
        "email": "emmy@gmail.com",
        "pivot": {
          "equipe_id": 1,
          "utilisateur_id": 1,
          "role": "proprietaire",
          "date_adhesion": "2026-05-24T18:00:00"
        }
      }
    ]
  }
]
```

---

## GET /api/equipes/{id} — Détail d'une équipe

**Réponse 200 :** même structure qu'un élément du tableau ci-dessus.

**Réponse 403 si l'utilisateur n'est pas membre :**
```json
{ "message": "Accès refusé." }
```

---

## POST /api/equipes — Créer une équipe `[admin]`

**Body :**
```json
{
  "proprietaire_id": 1,
  "nom": "Equipe Alpha",
  "description": "Description optionnelle"
}
```

**Réponse 201 :** l'équipe créée avec son propriétaire et ses membres.

> Le propriétaire est automatiquement enregistré dans `membre_equipe` avec le rôle `proprietaire`.

**Règles de validation :**

| Champ | Règles |
|---|---|
| `proprietaire_id` | Requis, entier, doit exister dans `Utilisateurs` |
| `nom` | Requis, string, max 255 |
| `description` | Optionnel, string |

---

## PUT /api/equipes/{id} — Modifier une équipe `[admin]`

**Body (tous les champs sont optionnels) :**
```json
{
  "nom": "Nouveau nom",
  "description": "Nouvelle description"
}
```

**Réponse 200 :** l'équipe mise à jour.

---

## DELETE /api/equipes/{id} — Supprimer une équipe `[admin]`

**Réponse 200 :**
```json
{ "message": "Équipe supprimée." }
```

> La suppression efface également toutes les entrées `membre_equipe` associées (cascade).

---

## GET /api/equipes/{id}/utilisateurs-disponibles `[admin]`

Retourne la liste des utilisateurs qui **ne sont pas encore membres** de l'équipe.
À utiliser pour peupler le menu déroulant d'ajout de membre.

**Réponse 200 :**
```json
[
  {
    "id": 2,
    "nom": "EmmySecu",
    "prenom": "Securite",
    "role": "securite"
  },
  {
    "id": 3,
    "nom": "EmmyAdmin",
    "prenom": "CloudDOI",
    "role": "administrateur_cloud_doi"
  }
]
```

> La liste est triée par `nom` puis `prenom`. Les utilisateurs déjà membres (y compris le propriétaire) sont exclus.

---

## POST /api/equipes/{id}/membres — Ajouter un membre `[admin]`

**Body :**
```json
{
  "utilisateur_id": 2
}
```

**Réponse 201 :** l'équipe complète avec la liste des membres mise à jour.

**Réponse 422 si déjà membre :**
```json
{ "message": "Cet utilisateur est déjà membre de cette équipe." }
```

---

## DELETE /api/equipes/{id}/membres/{userId} — Retirer un membre `[admin]`

**Réponse 200 :**
```json
{ "message": "Membre retiré de l'équipe." }
```

**Réponse 422 si tentative de retirer le propriétaire :**
```json
{ "message": "Le propriétaire ne peut pas être retiré de son équipe." }
```

**Réponse 404 si l'utilisateur n'est pas membre :**
```json
{ "message": "Membre introuvable dans cette équipe." }
```

---

## Réponse 403 — Accès refusé

Retourné sur toutes les routes `[admin]` si l'utilisateur n'a pas le rôle `administrateur` :

```json
{ "message": "Accès refusé. Cette action est réservée aux administrateurs." }
```

---

## Exemple d'implémentation React

### Menu déroulant — Sélection d'un utilisateur à ajouter

Crée `src/components/SelectUtilisateur.tsx` :

```tsx
import { useEffect, useState } from 'react';
import api from '@/lib/api';

interface UtilisateurDispo {
  id: number;
  nom: string;
  prenom: string;
  role: string;
}

const ROLE_LABEL: Record<string, string> = {
  administrateur:            'Administrateur',
  administrateur_cloud_doi:  'Administrateur Cloud DOI',
  securite:                  'Sécurité',
};

interface Props {
  equipeId: number;
  onAjouter: (utilisateurId: number) => Promise<void>;
}

export default function SelectUtilisateur({ equipeId, onAjouter }: Props) {
  const [utilisateurs, setUtilisateurs] = useState<UtilisateurDispo[]>([]);
  const [selected, setSelected]         = useState<number | ''>('');
  const [isLoading, setIsLoading]       = useState(false);
  const [error, setError]               = useState<string | null>(null);

  // Charge la liste dès que l'équipe change
  useEffect(() => {
    api.get<UtilisateurDispo[]>(`/equipes/${equipeId}/utilisateurs-disponibles`)
      .then(({ data }) => setUtilisateurs(data))
      .catch(() => setError('Impossible de charger les utilisateurs.'));
  }, [equipeId]);

  const handleAjouter = async () => {
    if (!selected) return;
    setIsLoading(true);
    setError(null);
    try {
      await onAjouter(Number(selected));
      setSelected(''); // reset après ajout
    } catch (err: any) {
      setError(err.response?.data?.message ?? 'Erreur lors de l\'ajout.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div>
      <select
        value={selected}
        onChange={(e) => setSelected(e.target.value === '' ? '' : Number(e.target.value))}
        disabled={isLoading || utilisateurs.length === 0}
      >
        <option value="">-- Sélectionner un utilisateur --</option>
        {utilisateurs.map((u) => (
          <option key={u.id} value={u.id}>
            {u.nom} {u.prenom} — {ROLE_LABEL[u.role] ?? u.role}
          </option>
        ))}
      </select>

      <button onClick={handleAjouter} disabled={!selected || isLoading}>
        {isLoading ? 'Ajout...' : 'Ajouter'}
      </button>

      {utilisateurs.length === 0 && (
        <p>Tous les utilisateurs sont déjà membres de cette équipe.</p>
      )}

      {error && <p style={{ color: 'red' }}>{error}</p>}
    </div>
  );
}
```

**Utilisation dans la page équipes :**

```tsx
import { useEquipes } from '@/hooks/useEquipes';
import SelectUtilisateur from '@/components/SelectUtilisateur';

// Dans le rendu d'une équipe :
<SelectUtilisateur
  equipeId={equipe.id}
  onAjouter={(userId) => ajouterMembre(equipe.id, userId)}
/>
```

---

### Hook `useEquipes`

Crée `src/hooks/useEquipes.ts` :

```ts
import { useState, useEffect } from 'react';
import api from '@/lib/api';
import { Equipe, CreateEquipePayload, UpdateEquipePayload } from '@/types/equipes';

export function useEquipes() {
  const [equipes, setEquipes]     = useState<Equipe[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError]         = useState<string | null>(null);

  const fetchEquipes = async () => {
    setIsLoading(true);
    try {
      const { data } = await api.get<Equipe[]>('/equipes');
      setEquipes(data);
    } catch {
      setError('Impossible de charger les équipes.');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => { fetchEquipes(); }, []);

  const creerEquipe = async (payload: CreateEquipePayload): Promise<Equipe> => {
    const { data } = await api.post<Equipe>('/equipes', payload);
    setEquipes((prev) => [...prev, data]);
    return data;
  };

  const modifierEquipe = async (id: number, payload: UpdateEquipePayload): Promise<Equipe> => {
    const { data } = await api.put<Equipe>(`/equipes/${id}`, payload);
    setEquipes((prev) => prev.map((e) => (e.id === id ? data : e)));
    return data;
  };

  const supprimerEquipe = async (id: number): Promise<void> => {
    await api.delete(`/equipes/${id}`);
    setEquipes((prev) => prev.filter((e) => e.id !== id));
  };

  const ajouterMembre = async (equipeId: number, utilisateurId: number): Promise<Equipe> => {
    const { data } = await api.post<Equipe>(`/equipes/${equipeId}/membres`, {
      utilisateur_id: utilisateurId,
    });
    setEquipes((prev) => prev.map((e) => (e.id === equipeId ? data : e)));
    return data;
  };

  const retirerMembre = async (equipeId: number, utilisateurId: number): Promise<void> => {
    await api.delete(`/equipes/${equipeId}/membres/${utilisateurId}`);
    await fetchEquipes();
  };

  return {
    equipes,
    isLoading,
    error,
    creerEquipe,
    modifierEquipe,
    supprimerEquipe,
    ajouterMembre,
    retirerMembre,
    refresh: fetchEquipes,
  };
}
```

---

### Logique d'affichage selon le rôle

```tsx
import { useAuth } from '@/context/AuthContext';
import { useEquipes } from '@/hooks/useEquipes';

export default function EquipesPage() {
  const { user } = useAuth();
  const { equipes, isLoading } = useEquipes();
  const isAdmin = user?.role === 'administrateur';

  if (isLoading) return <div>Chargement...</div>;

  return (
    <div>
      {isAdmin && (
        <button onClick={() => { /* ouvrir modal création */ }}>
          Créer une équipe
        </button>
      )}

      {equipes.map((equipe) => (
        <div key={equipe.id}>
          <h2>{equipe.nom}</h2>
          <p>{equipe.description}</p>
          <p>Propriétaire : {equipe.proprietaire.nom} {equipe.proprietaire.prenom}</p>
          <p>Membres : {equipe.membres.length}</p>

          {isAdmin && (
            <>
              <button onClick={() => { /* modifier */ }}>Modifier</button>
              <button onClick={() => { /* ajouter membre */ }}>Ajouter un membre</button>
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
| `201` | Ressource créée |
| `403` | Accès refusé (rôle insuffisant) |
| `404` | Équipe ou membre introuvable |
| `422` | Erreur de validation ou règle métier violée |
| `500` | Erreur serveur |
