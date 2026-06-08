# Guide d'intégration Frontend — Déploiement via upload .zip

> Prérequis : client Axios + contexte Auth décrits dans `FRONTEND_INTEGRATION.md`.

---

## Flux en deux étapes

```
ÉTAPE 1 — Upload
──────────────────────────────────────────────────────────────────
[Utilisateur] sélectionne un .zip

POST /api/projets/{id}/upload-zip   (multipart/form-data, champ "zip")
    ↓  [attend — peut prendre plusieurs minutes selon la taille]
[Backend Laravel]
  • Lit deploy.meta.json dans le .zip  →  extrait app + version
  • Envoie le .zip brut à POST http://IP_VM:5000/upload
    [API VM] extrait dans C:\Deploy\Sources\PFE-firstBranch\
  • INSERT deploiements (statut = en_attente)
← { deploiement_id, app, version, message: "✅ Upload réussi" }

[Frontend] affiche : "✅ Upload de PFE-firstBranch réussi. Prêt à déployer."


ÉTAPE 2 — Déploiement
──────────────────────────────────────────────────────────────────
POST /api/deploiements/{deploiement_id}/lancer
    ↓  [attend — jusqu'à 30 min, le temps que deploy.ps1 termine]
[Backend Laravel]
  • Passe statut → en_cours
  • POST http://IP_VM:5000/deploy  { app, user }
    [API VM] exécute deploy.ps1 de façon SYNCHRONE (attend la fin)
    [deploy.ps1] validate → backup → install → configure → migrate → verify
    [API VM] répond quand terminé  { statut: "termine"|"echoue", logs }
  • Met à jour la BD (statut + logs)
← { deploiement_id, statut: "termine"|"echoue", logs, message }

[Frontend] reçoit la réponse finale → affiche ✅ ou ❌
```

**Pas de polling.** Les deux requêtes sont bloquantes — le frontend attend
la réponse HTTP qui confirme la fin de chaque étape.

---

## Structure du .zip

```
PFE-firstBranch.zip
  deploy.meta.json    ← obligatoire
  index.php
  app/
  ...
```

```json
{
  "application": {
    "name":    "PFE-firstBranch",
    "version": "1.0.0",
    "type":    "laravel"
  },
  "deployment": {
    "target_directory": "C:\\xampp\\htdocs\\PFE-firstBranch"
  }
}
```

---

## Variables d'environnement backend

```env
DEPLOY_VM_URL="http://IP_VM:5000"
```

---

## Endpoints

| Méthode | Endpoint | Auth | Rôle | Description |
|---|---|---|---|---|
| `POST` | `/api/projets/{id}/upload-zip` | Oui | `administrateur_cloud_doi` | Upload .zip + extraction VM |
| `POST` | `/api/deploiements/{id}/lancer` | Oui | `administrateur_cloud_doi` | Lance deploy.ps1 (synchrone) |
| `GET` | `/api/deploiements/{id}/logs` | Oui | Tous | Relecture logs en BD |

---

## POST /api/projets/{id}/upload-zip

**Type :** `multipart/form-data` | **Champ :** `zip` (.zip, max 500 Mo)
**Timeout Axios recommandé :** 30 min (`timeout: 1800000`)

**Réponse 200 :**
```json
{
  "message":        "✅ Upload de \"PFE-firstBranch\" réussi. Prêt à déployer.",
  "deploiement_id": 42,
  "app":            "PFE-firstBranch",
  "version":        "1.0.0"
}
```

**Erreurs :**
```json
{ "message": "Le fichier .zip ne contient pas de deploy.meta.json valide." }   // 422
{ "message": "deploy.meta.json doit contenir application.name et application.version." } // 422
{ "message": "Échec de l'upload vers la VM (500) : ..." }                      // 502
{ "message": "Accès refusé." }                                                 // 403
{ "message": "Ce projet est archivé." }                                        // 422
{ "message": "DEPLOY_VM_URL non configuré dans .env." }                        // 500
```

---

## POST /api/deploiements/{id}/lancer

**Corps :** aucun body requis.
**Timeout Axios recommandé :** 31 min (`timeout: 1860000`)

La requête reste ouverte jusqu'à ce que `deploy.ps1` termine sur la VM.

**Réponse 200 — succès :**
```json
{
  "message":        "✅ Déploiement de \"PFE-firstBranch\" terminé avec succès.",
  "deploiement_id": 42,
  "statut":         "termine",
  "logs":           "Deploiement SUCCESS pour PFE-firstBranch_1.0.0"
}
```

**Réponse 200 — échec deploy.ps1 :**
```json
{
  "message":        "❌ Déploiement de \"PFE-firstBranch\" échoué.",
  "deploiement_id": 42,
  "statut":         "echoue",
  "logs":           "ECHEC du deploiement... | Rollback effectué."
}
```

**Réponse 502 — VM inaccessible :**
```json
{
  "message":        "Impossible de joindre la VM : Connection refused",
  "deploiement_id": 42,
  "statut":         "echoue"
}
```

---

## GET /api/deploiements/{id}/logs

Relecture d'un déploiement passé depuis la BD.

**Réponse 200 :**
```json
{
  "deploiement_id": 42,
  "statut":         "termine",
  "logs":           "Deploiement SUCCESS pour PFE-firstBranch_1.0.0"
}
```

---

## Types TypeScript

```ts
// src/types/deploiement.ts

export type StatutDeploi = 'en_attente' | 'en_cours' | 'termine' | 'echoue';

export interface UploadResponse {
  message:        string;
  deploiement_id: number;
  app:            string;
  version:        string;
}

export interface LancerResponse {
  message:        string;
  deploiement_id: number;
  statut:         StatutDeploi;
  logs:           string;
}

export interface LogsResponse {
  deploiement_id: number;
  statut:         StatutDeploi;
  logs:           string;
}
```

---

## Hook `useDeploiement`

```ts
// src/hooks/useDeploiement.ts
import { useState } from 'react';
import api from '@/lib/api';
import { UploadResponse, LancerResponse, StatutDeploi } from '@/types/deploiement';

export type EtapeDeploi =
  | 'idle'
  | 'upload_en_cours'
  | 'upload_ok'
  | 'deploiement_en_cours'
  | 'termine'
  | 'echoue';

export function useDeploiement(projetId: number) {
  const [etape, setEtape]                 = useState<EtapeDeploi>('idle');
  const [deploiementId, setDeploiementId] = useState<number | null>(null);
  const [app, setApp]                     = useState<string>('');
  const [version, setVersion]             = useState<string>('');
  const [messageUpload, setMessageUpload] = useState<string>('');
  const [messageDeploi, setMessageDeploi] = useState<string>('');
  const [logs, setLogs]                   = useState<string>('');
  const [erreur, setErreur]               = useState<string | null>(null);
  const [progression, setProgression]     = useState<number>(0);

  const reset = () => {
    setEtape('idle');
    setDeploiementId(null);
    setApp(''); setVersion('');
    setMessageUpload(''); setMessageDeploi('');
    setLogs(''); setErreur(null); setProgression(0);
  };

  // ── Étape 1 : Upload ────────────────────────────────────────────────────
  const uploadZip = async (fichierZip: File) => {
    reset();
    setEtape('upload_en_cours');

    const formData = new FormData();
    formData.append('zip', fichierZip);

    try {
      const { data } = await api.post<UploadResponse>(
        `/projets/${projetId}/upload-zip`,
        formData,
        {
          headers:  { 'Content-Type': 'multipart/form-data' },
          timeout:  1800000, // 30 minutes
          onUploadProgress: (event) => {
            if (event.total) {
              setProgression(Math.round((event.loaded * 100) / event.total));
            }
          },
        },
      );

      setDeploiementId(data.deploiement_id);
      setApp(data.app);
      setVersion(data.version);
      setMessageUpload(data.message);
      setEtape('upload_ok');
      setProgression(0);

      // Lancer automatiquement le déploiement
      await lancerDeploi(data.deploiement_id);

    } catch (err: any) {
      setErreur(err.response?.data?.message ?? "Erreur lors de l'upload.");
      setEtape('echoue');
      setProgression(0);
    }
  };

  // ── Étape 2 : Déploiement synchrone ────────────────────────────────────
  const lancerDeploi = async (id: number) => {
    setEtape('deploiement_en_cours');

    try {
      // Timeout 31 min — attend que deploy.ps1 termine complètement
      const { data } = await api.post<LancerResponse>(
        `/deploiements/${id}/lancer`,
        {},
        { timeout: 1860000 },
      );

      setMessageDeploi(data.message);
      setLogs(data.logs ?? '');
      setEtape(data.statut === 'termine' ? 'termine' : 'echoue');

      if (data.statut === 'echoue') {
        setErreur(data.message);
      }
    } catch (err: any) {
      setErreur(err.response?.data?.message ?? 'Erreur lors du lancement.');
      setEtape('echoue');
    }
  };

  return {
    uploadZip,
    etape,
    deploiementId,
    app, version,
    messageUpload, messageDeploi,
    logs, erreur, progression,
    reset,
  };
}
```

---

## Composant `DeploiementPanel`

```tsx
// src/components/DeploiementPanel.tsx
import { useRef } from 'react';
import { useAuth } from '@/context/AuthContext';
import { useDeploiement } from '@/hooks/useDeploiement';

interface Props { projetId: number; }

export default function DeploiementPanel({ projetId }: Props) {
  const { user }  = useAuth();
  const inputRef  = useRef<HTMLInputElement>(null);
  const {
    uploadZip, etape, app, version,
    messageUpload, messageDeploi,
    logs, erreur, progression,
  } = useDeploiement(projetId);

  const peutDeployer = user?.role === 'administrateur_cloud_doi';
  const occupé       = etape === 'upload_en_cours' || etape === 'deploiement_en_cours';

  return (
    <div className="space-y-5">

      {/* ── Bouton sélection .zip ─────────────────────────────── */}
      {peutDeployer && (
        <div className="space-y-1">
          <input
            ref={inputRef}
            type="file"
            accept=".zip"
            className="hidden"
            onChange={async (e) => {
              const f = e.target.files?.[0];
              e.target.value = '';
              if (f) await uploadZip(f);
            }}
          />
          <button
            onClick={() => inputRef.current?.click()}
            disabled={occupé}
            className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white
                       hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50
                       transition-colors"
          >
            📦 Déployer (.zip)
          </button>
          <p className="text-xs text-gray-500">
            Archive <code>.zip</code> contenant <code>deploy.meta.json</code> — max 500 Mo
          </p>
        </div>
      )}

      {/* ── Étape 1 : Upload en cours ─────────────────────────── */}
      {etape === 'upload_en_cours' && (
        <div className="space-y-2">
          <p className="text-sm text-blue-600 font-medium">📤 Upload en cours...</p>
          {progression > 0 && (
            <div className="space-y-1">
              <div className="flex justify-between text-xs text-gray-500">
                <span>Envoi vers la VM</span>
                <span>{progression}%</span>
              </div>
              <div className="h-2 rounded-full bg-gray-200">
                <div
                  className="h-2 rounded-full bg-blue-500 transition-all duration-300"
                  style={{ width: `${progression}%` }}
                />
              </div>
            </div>
          )}
        </div>
      )}

      {/* ── Étape 1 : Upload réussi ───────────────────────────── */}
      {messageUpload && etape !== 'idle' && (
        <div className="rounded-lg bg-green-50 border border-green-200 p-3 text-sm text-green-800">
          {messageUpload}
          {app && version && (
            <span className="ml-2 font-mono text-xs text-green-600">{app} v{version}</span>
          )}
        </div>
      )}

      {/* ── Étape 2 : Déploiement en cours ───────────────────── */}
      {etape === 'deploiement_en_cours' && (
        <p className="text-sm text-blue-600 font-medium animate-pulse">
          🔄 Déploiement en cours sur la VM... (peut prendre plusieurs minutes)
        </p>
      )}

      {/* ── Étape 2 : Résultat final ──────────────────────────── */}
      {(etape === 'termine' || etape === 'echoue') && messageDeploi && (
        <div className={`rounded-lg border p-3 text-sm font-medium ${
          etape === 'termine'
            ? 'bg-green-50 border-green-200 text-green-800'
            : 'bg-red-50 border-red-200 text-red-800'
        }`}>
          {messageDeploi}
        </div>
      )}

      {/* ── Erreur upload ────────────────────────────────────── */}
      {erreur && etape === 'echoue' && !messageDeploi && (
        <div className="rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800">
          ❌ {erreur}
        </div>
      )}

      {/* ── Logs deploy.ps1 ──────────────────────────────────── */}
      {logs && (
        <div>
          <p className="text-xs uppercase tracking-wide text-gray-400 mb-2">
            Logs de déploiement
          </p>
          <pre className="bg-gray-900 text-green-400 rounded-lg p-4 text-xs
                          overflow-auto max-h-64 whitespace-pre-wrap font-mono">
            {logs}
          </pre>
        </div>
      )}
    </div>
  );
}
```

---

## Cycle de vie de `etape`

```
idle
 ↓ sélection .zip
upload_en_cours        → barre de progression
 ↓ réponse 200
upload_ok              → "✅ Upload réussi"  (automatique)
 ↓
deploiement_en_cours   → "🔄 en cours..."  (peut durer plusieurs minutes)
 ↓ réponse 200
termine | echoue       → "✅ Terminé" | "❌ Échoué" + logs
```

---

## Règles d'affichage par rôle

| Rôle | Bouton Déployer | Voir les logs |
|---|---|---|
| `administrateur_cloud_doi` | ✅ | ✅ |
| `administrateur` | ❌ | ✅ |
| `securite` | ❌ | ✅ |

---

## Codes HTTP

| Code | Endpoint | Signification |
|---|---|---|
| `200` | `/upload-zip` | Upload + extraction réussis |
| `200` | `/lancer` | Déploiement terminé (`termine` ou `echoue` dans le body) |
| `403` | les deux | Rôle insuffisant |
| `422` | `/upload-zip` | .zip invalide, meta absent, projet archivé |
| `422` | `/lancer` | Déploiement déjà en cours ou terminé |
| `500` | les deux | `DEPLOY_VM_URL` non configuré |
| `502` | les deux | VM inaccessible |

---

## Autres documentations

- **Auth** → `FRONTEND_INTEGRATION.md`
- **Projets** → `PROJETS_INTEGRATION.md`
- **Équipes** → `EQUIPES_INTEGRATION.md`
- **Workflows** → `WORKFLOWS_INTEGRATION.md`
