# Guide d'intégration Frontend React — Workflows CI/CD

Ce document décrit comment récupérer et afficher les pipelines GitHub Actions
d'un dépôt lié à un projet.

> Prérequis :
> - Le projet doit avoir un `url_depot` renseigné (voir `PROJETS_INTEGRATION.md`)
> - L'utilisateur doit avoir un `token_outil_cicd` valide (voir connexion GitHub)

---

## Comment ça fonctionne

Le backend agit comme **proxy** vers l'API GitHub Actions :

```
Frontend → POST /api/projets/{id}/workflows/sync → Backend → GitHub API → Réponse JSON
```

Le token GitHub de l'utilisateur connecté est utilisé côté serveur — il n'est jamais exposé au frontend.

> **Important :** L'endpoint GitHub Actions `/actions/workflows` requiert un token même pour les dépôts publics. L'utilisateur doit avoir connecté son compte GitHub via le formulaire "Connecter GitHub" avant de pouvoir synchroniser les pipelines.

---

## Endpoints

| Méthode | Endpoint | Description |
|---|---|---|
| `POST` | `/api/projets/{id}/workflows/sync` | Récupère tous les pipelines du dépôt |
| `GET` | `/api/projets/{id}/workflows/{workflowId}/runs` | Récupère les exécutions d'un pipeline |

Les deux endpoints nécessitent `Authorization: Bearer <token>`.

---

## POST /api/projets/{id}/workflows/sync — Synchroniser les pipelines

Appelle l'API GitHub Actions et retourne tous les workflows (fichiers `.yml`) du dépôt lié.

**Pas de body requis.**

**Réponse 200 :**
```json
{
  "depot": "organisation/mon-repo",
  "total": 3,
  "workflows": [
    {
      "id": 12345678,
      "nom": "CI Pipeline",
      "fichier": ".github/workflows/ci.yml",
      "etat": "active",
      "url_github": "https://github.com/organisation/mon-repo/actions/workflows/ci.yml",
      "badge_url": "https://github.com/organisation/mon-repo/actions/workflows/ci.yml/badge.svg",
      "created_at": "2026-01-01T00:00:00Z",
      "updated_at": "2026-05-25T00:00:00Z"
    },
    {
      "id": 12345679,
      "nom": "Deploy Production",
      "fichier": ".github/workflows/deploy.yml",
      "etat": "active",
      "url_github": "https://github.com/organisation/mon-repo/actions/workflows/deploy.yml",
      "badge_url": "https://github.com/organisation/mon-repo/actions/workflows/deploy.yml/badge.svg",
      "created_at": "2026-01-01T00:00:00Z",
      "updated_at": "2026-05-25T00:00:00Z"
    }
  ]
}
```

**Champs d'un workflow :**

| Champ | Type | Description |
|---|---|---|
| `id` | number | ID GitHub du workflow |
| `nom` | string | Nom du workflow (défini dans le fichier `.yml`) |
| `fichier` | string | Chemin du fichier (ex: `.github/workflows/ci.yml`) |
| `etat` | string | `active`, `disabled_manually`, `disabled_inactivity` |
| `url_github` | string | Lien vers la page GitHub du workflow |
| `badge_url` | string | URL du badge de statut (à intégrer en `<img>`) |

---

## GET /api/projets/{id}/workflows/{workflowId}/runs — Exécutions d'un pipeline

Retourne les 10 dernières exécutions d'un workflow spécifique.

**Réponse 200 :**
```json
{
  "total": 42,
  "runs": [
    {
      "id": 9876543210,
      "nom": "CI Pipeline",
      "statut": "completed",
      "conclusion": "success",
      "branche": "main",
      "commit_sha": "a1b2c3d",
      "declencheur": "push",
      "url_github": "https://github.com/organisation/mon-repo/actions/runs/9876543210",
      "debut": "2026-05-25T10:00:00Z",
      "fin": "2026-05-25T10:05:30Z"
    }
  ]
}
```

**Valeurs de `statut` :** `queued` | `in_progress` | `completed`

**Valeurs de `conclusion` :** `success` | `failure` | `cancelled` | `skipped` | `null` (si en cours)

---

## Erreurs possibles

| Code | Cause | Message |
|---|---|---|
| `401` | Token GitHub invalide ou expiré | `"Token GitHub invalide ou expiré..."` |
| `403` | Utilisateur non membre de l'équipe | `"Accès refusé."` |
| `404` | Dépôt introuvable sur GitHub | `"Dépôt introuvable : owner/repo..."` |
| `422` | Projet sans dépôt lié ou sans token | Message explicite |

---

## Types TypeScript

Crée `src/types/workflows.ts` :

```ts
export type WorkflowEtat = 'active' | 'disabled_manually' | 'disabled_inactivity';
export type RunStatut    = 'queued' | 'in_progress' | 'completed';
export type RunConclusion = 'success' | 'failure' | 'cancelled' | 'skipped' | null;

export interface Workflow {
  id: number;
  nom: string;
  fichier: string;
  etat: WorkflowEtat;
  url_github: string;
  badge_url: string;
  created_at: string;
  updated_at: string;
}

export interface WorkflowRun {
  id: number;
  nom: string;
  statut: RunStatut;
  conclusion: RunConclusion;
  branche: string;
  commit_sha: string;
  declencheur: string;
  url_github: string;
  debut: string;
  fin: string;
}

export interface SyncResponse {
  depot: string;
  total: number;
  workflows: Workflow[];
}

export interface RunsResponse {
  total: number;
  runs: WorkflowRun[];
}
```

---

## Hook `useWorkflows`

Crée `src/hooks/useWorkflows.ts` :

```ts
import { useState } from 'react';
import api from '@/lib/api';
import { SyncResponse, RunsResponse, Workflow, WorkflowRun } from '@/types/workflows';

export function useWorkflows(projetId: number) {
  const [workflows, setWorkflows]   = useState<Workflow[]>([]);
  const [depot, setDepot]           = useState<string>('');
  const [isLoading, setIsLoading]   = useState(false);
  const [error, setError]           = useState<string | null>(null);

  // Synchronise et récupère tous les pipelines
  const synchroniser = async (): Promise<void> => {
    setIsLoading(true);
    setError(null);
    try {
      const { data } = await api.post<SyncResponse>(`/projets/${projetId}/workflows/sync`);
      setWorkflows(data.workflows);
      setDepot(data.depot);
    } catch (err: any) {
      setError(err.response?.data?.message ?? 'Erreur lors de la synchronisation.');
    } finally {
      setIsLoading(false);
    }
  };

  // Récupère les exécutions d'un workflow
  const getRuns = async (workflowId: number): Promise<WorkflowRun[]> => {
    const { data } = await api.get<RunsResponse>(
      `/projets/${projetId}/workflows/${workflowId}/runs`
    );
    return data.runs;
  };

  return { workflows, depot, isLoading, error, synchroniser, getRuns };
}
```

---

## Composant `WorkflowsPage`

```tsx
import { useWorkflows } from '@/hooks/useWorkflows';
import { Workflow, RunConclusion } from '@/types/workflows';

// Couleur selon la conclusion du dernier run
const conclusionColor: Record<string, string> = {
  success:   'text-green-600',
  failure:   'text-red-600',
  cancelled: 'text-gray-500',
  skipped:   'text-yellow-500',
};

// Label lisible pour l'état du workflow
const etatLabel: Record<string, string> = {
  active:                 'Actif',
  disabled_manually:      'Désactivé',
  disabled_inactivity:    'Inactif',
};

interface Props {
  projetId: number;
}

export default function WorkflowsPage({ projetId }: Props) {
  const { workflows, depot, isLoading, error, synchroniser } = useWorkflows(projetId);

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h2>Pipelines CI/CD {depot && <span className="text-gray-500 text-sm">— {depot}</span>}</h2>
        <button
          onClick={synchroniser}
          disabled={isLoading}
          className="px-4 py-2 bg-blue-600 text-white rounded-lg disabled:opacity-50"
        >
          {isLoading ? 'Synchronisation...' : '↻ Synchroniser'}
        </button>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded p-4 mb-4 text-red-700">
          {error}
        </div>
      )}

      {workflows.length === 0 && !isLoading && (
        <p className="text-gray-500">
          Aucun pipeline trouvé. Cliquez sur "Synchroniser" pour charger les workflows du dépôt.
        </p>
      )}

      <div className="grid gap-4">
        {workflows.map((workflow) => (
          <WorkflowCard key={workflow.id} workflow={workflow} projetId={projetId} />
        ))}
      </div>
    </div>
  );
}

function WorkflowCard({ workflow, projetId }: { workflow: Workflow; projetId: number }) {
  return (
    <div className="bg-white border rounded-lg p-4 flex items-center justify-between">
      <div>
        <h3 className="font-medium">{workflow.nom}</h3>
        <p className="text-sm text-gray-500">{workflow.fichier}</p>
        <span className={`text-xs px-2 py-0.5 rounded-full ${
          workflow.etat === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'
        }`}>
          {etatLabel[workflow.etat] ?? workflow.etat}
        </span>
      </div>
      <div className="flex items-center gap-3">
        {/* Badge de statut GitHub */}
        <img src={workflow.badge_url} alt={`Statut ${workflow.nom}`} />
        <a
          href={workflow.url_github}
          target="_blank"
          rel="noopener noreferrer"
          className="text-sm text-blue-600 hover:underline"
        >
          Voir sur GitHub →
        </a>
      </div>
    </div>
  );
}
```

---

## Logique d'affichage recommandée

```
1. Arriver sur la page Workflows d'un projet
2. Si workflows.length === 0 → afficher message + bouton "Synchroniser"
3. Clic sur "Synchroniser" → POST /api/projets/{id}/workflows/sync
4. Afficher la liste des workflows avec badge de statut
5. Clic sur un workflow → GET /api/projets/{id}/workflows/{workflowId}/runs
6. Afficher les 10 dernières exécutions avec statut coloré
```

---

## Autres documentations

- **Auth** → `FRONTEND_INTEGRATION.md`
- **Équipes** → `EQUIPES_INTEGRATION.md`
- **Projets** → `PROJETS_INTEGRATION.md`
