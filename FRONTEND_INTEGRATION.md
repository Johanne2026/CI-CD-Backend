# Guide d'intégration Frontend React — API Auth

Ce document décrit tout ce que le frontend React doit implémenter pour s'intégrer
avec ce backend Laravel.

---

## Configuration de base

### URL de l'API

```
http://localhost:8000/api
```

> En production, utilise une variable d'environnement : `VITE_API_URL=https://ton-api.com/api`

### Headers obligatoires sur toutes les requêtes

```
Accept: application/json
Content-Type: application/json
```

Pour les routes protégées :

```
Authorization: Bearer <token>
```

---

## Types TypeScript

Crée un fichier `src/types/auth.ts` :

```ts
export type UserRole = 'administrateur' | 'administrateur_cloud_doi' | 'securite';

export const ROLE_REDIRECT: Record<UserRole, string> = {
  administrateur:            '/dashboard/admin',
  administrateur_cloud_doi:  '/dashboard/cloud-doi',
  securite:                  '/dashboard/securite',
};

export interface User {
  id: number;
  nom: string;
  prenom: string;
  username_outil_cicd: string;   // nom d'utilisateur GitHub
  role: UserRole;
  date_inscription: string;      // ISO 8601 — renseigné à l'inscription
  created_at: string;
  updated_at: string;
  // api_token, token_outil_cicd et mot_de_passe sont masqués par le backend
}

export interface AuthResponse {
  token: string;       // token API à stocker et envoyer dans Authorization
  redirect_to: string; // route vers laquelle naviguer après login/register
  user: User;
}

export interface ValidationError {
  message: string;
  errors: Record<string, string[]>;
}
```

---

## Client Axios

Crée un fichier `src/lib/api.ts` :

```ts
import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
  headers: { Accept: 'application/json' },
});

// Injecte le token automatiquement sur chaque requête
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Redirige vers /login si le token est invalide ou expiré
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  },
);

export default api;
```

---

## Contexte d'authentification

Crée un fichier `src/context/AuthContext.tsx` :

```tsx
import { createContext, useContext, useEffect, useState } from 'react';
import api from '@/lib/api';
import { User, AuthResponse, UserRole } from '@/types/auth';

interface RegisterPayload {
  nom: string;
  prenom: string;
  username_outil_cicd: string;
  mot_de_passe: string;
  mot_de_passe_confirmation: string;
  role: UserRole;
}

interface LoginPayload {
  username_outil_cicd: string;
  mot_de_passe: string;
  token_outil_cicd?: string; // token GitHub, optionnel
}

interface AuthContextType {
  user: User | null;
  token: string | null;
  isLoading: boolean;
  register: (payload: RegisterPayload) => Promise<string>;
  login: (payload: LoginPayload) => Promise<string>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser]           = useState<User | null>(null);
  const [token, setToken]         = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Recharge l'état depuis le localStorage au démarrage
  useEffect(() => {
    const storedToken = localStorage.getItem('token');
    const storedUser  = localStorage.getItem('user');
    if (storedToken && storedUser) {
      setToken(storedToken);
      setUser(JSON.parse(storedUser));
    }
    setIsLoading(false);
  }, []);

  const register = async (payload: RegisterPayload): Promise<string> => {
    const { data } = await api.post<AuthResponse>('/register', payload);
    localStorage.setItem('token', data.token);
    localStorage.setItem('user', JSON.stringify(data.user));
    setToken(data.token);
    setUser(data.user);
    return data.redirect_to;
  };

  const login = async (payload: LoginPayload): Promise<string> => {
    const { data } = await api.post<AuthResponse>('/login', payload);
    localStorage.setItem('token', data.token);
    localStorage.setItem('user', JSON.stringify(data.user));
    setToken(data.token);
    setUser(data.user);
    return data.redirect_to;
  };

  const logout = async (): Promise<void> => {
    await api.post('/logout');
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    setToken(null);
    setUser(null);
  };

  return (
    <AuthContext.Provider value={{ user, token, isLoading, register, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth doit être utilisé dans un AuthProvider');
  return ctx;
}
```

---

## Protection des routes

Crée un fichier `src/components/ProtectedRoute.tsx` :

```tsx
import { Navigate } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import { UserRole, ROLE_REDIRECT } from '@/types/auth';

interface ProtectedRouteProps {
  children: React.ReactNode;
  allowedRole: UserRole;
}

export default function ProtectedRoute({ children, allowedRole }: ProtectedRouteProps) {
  const { user, isLoading } = useAuth();

  if (isLoading) return <div>Chargement...</div>;

  // Non connecté → login
  if (!user) return <Navigate to="/login" replace />;

  // Mauvais rôle → redirige vers sa propre page
  if (user.role !== allowedRole) {
    return <Navigate to={ROLE_REDIRECT[user.role]} replace />;
  }

  return <>{children}</>;
}
```

---

## Configuration des routes (React Router v6)

Dans `src/App.tsx` :

```tsx
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from '@/context/AuthContext';
import ProtectedRoute from '@/components/ProtectedRoute';

import LoginPage          from '@/pages/auth/LoginPage';
import RegisterPage       from '@/pages/auth/RegisterPage';
import AdminDashboard     from '@/pages/dashboard/AdminDashboard';
import CloudDoiDashboard  from '@/pages/dashboard/CloudDoiDashboard';
import SecuriteDashboard  from '@/pages/dashboard/SecuriteDashboard';

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          {/* Routes publiques */}
          <Route path="/login"    element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />

          {/* Routes protégées par rôle */}
          <Route
            path="/dashboard/admin"
            element={
              <ProtectedRoute allowedRole="administrateur">
                <AdminDashboard />
              </ProtectedRoute>
            }
          />
          <Route
            path="/dashboard/cloud-doi"
            element={
              <ProtectedRoute allowedRole="administrateur_cloud_doi">
                <CloudDoiDashboard />
              </ProtectedRoute>
            }
          />
          <Route
            path="/dashboard/securite"
            element={
              <ProtectedRoute allowedRole="securite">
                <SecuriteDashboard />
              </ProtectedRoute>
            }
          />

          {/* Fallback */}
          <Route path="*" element={<Navigate to="/login" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
```

---

## Formulaire d'inscription

Crée `src/pages/auth/RegisterPage.tsx` :

```tsx
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import { UserRole } from '@/types/auth';

const ROLES: { value: UserRole; label: string }[] = [
  { value: 'administrateur',            label: 'Administrateur' },
  { value: 'administrateur_cloud_doi',  label: 'Administrateur Cloud DOI' },
  { value: 'securite',                  label: 'Sécurité' },
];

export default function RegisterPage() {
  const { register } = useAuth();
  const navigate = useNavigate();

  const [form, setForm] = useState({
    nom: '',
    prenom: '',
    username_outil_cicd: '',
    mot_de_passe: '',
    mot_de_passe_confirmation: '',
    role: '' as UserRole,
  });
  const [errors, setErrors]       = useState<Record<string, string[]>>({});
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});
    setIsLoading(true);
    try {
      const redirectTo = await register(form);
      navigate(redirectTo);
    } catch (err: any) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors);
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <div>
        <label>Nom</label>
        <input type="text" value={form.nom}
          onChange={(e) => setForm({ ...form, nom: e.target.value })} />
        {errors.nom && <span>{errors.nom[0]}</span>}
      </div>

      <div>
        <label>Prénom</label>
        <input type="text" value={form.prenom}
          onChange={(e) => setForm({ ...form, prenom: e.target.value })} />
        {errors.prenom && <span>{errors.prenom[0]}</span>}
      </div>

      <div>
        <label>Nom d'utilisateur GitHub</label>
        <input type="text" value={form.username_outil_cicd}
          onChange={(e) => setForm({ ...form, username_outil_cicd: e.target.value })} />
        {errors.username_outil_cicd && <span>{errors.username_outil_cicd[0]}</span>}
      </div>

      <div>
        <label>Mot de passe</label>
        <input type="password" value={form.mot_de_passe}
          onChange={(e) => setForm({ ...form, mot_de_passe: e.target.value })} />
        {errors.mot_de_passe && <span>{errors.mot_de_passe[0]}</span>}
      </div>

      <div>
        <label>Confirmer le mot de passe</label>
        <input type="password" value={form.mot_de_passe_confirmation}
          onChange={(e) => setForm({ ...form, mot_de_passe_confirmation: e.target.value })} />
      </div>

      <div>
        <label>Rôle</label>
        <select value={form.role}
          onChange={(e) => setForm({ ...form, role: e.target.value as UserRole })}>
          <option value="">-- Sélectionner un rôle --</option>
          {ROLES.map((r) => (
            <option key={r.value} value={r.value}>{r.label}</option>
          ))}
        </select>
        {errors.role && <span>{errors.role[0]}</span>}
      </div>

      <button type="submit" disabled={isLoading}>
        {isLoading ? 'Inscription...' : "S'inscrire"}
      </button>
    </form>
  );
}
```

---

## Formulaire de connexion

Crée `src/pages/auth/LoginPage.tsx` :

```tsx
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();

  const [form, setForm] = useState({
    username_outil_cicd: '',
    mot_de_passe: '',
    token_outil_cicd: '',   // token GitHub, optionnel
  });
  const [errors, setErrors]       = useState<Record<string, string[]>>({});
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});
    setIsLoading(true);
    try {
      const payload = {
        username_outil_cicd: form.username_outil_cicd,
        mot_de_passe: form.mot_de_passe,
        ...(form.token_outil_cicd ? { token_outil_cicd: form.token_outil_cicd } : {}),
      };
      const redirectTo = await login(payload);
      navigate(redirectTo);
    } catch (err: any) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors);
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <div>
        <label>Nom d'utilisateur GitHub</label>
        <input type="text" value={form.username_outil_cicd}
          onChange={(e) => setForm({ ...form, username_outil_cicd: e.target.value })} />
        {errors.username_outil_cicd && <span>{errors.username_outil_cicd[0]}</span>}
      </div>

      <div>
        <label>Mot de passe</label>
        <input type="password" value={form.mot_de_passe}
          onChange={(e) => setForm({ ...form, mot_de_passe: e.target.value })} />
        {errors.mot_de_passe && <span>{errors.mot_de_passe[0]}</span>}
      </div>

      <div>
        <label>Token GitHub (optionnel)</label>
        <input type="text" value={form.token_outil_cicd}
          onChange={(e) => setForm({ ...form, token_outil_cicd: e.target.value })}
          placeholder="ghp_xxxxxxxxxxxx" />
      </div>

      <button type="submit" disabled={isLoading}>
        {isLoading ? 'Connexion...' : 'Se connecter'}
      </button>
    </form>
  );
}
```

---

## Bouton de déconnexion

```tsx
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';

export default function LogoutButton() {
  const { logout } = useAuth();
  const navigate = useNavigate();

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  return <button onClick={handleLogout}>Se déconnecter</button>;
}
```

---

## Endpoints — Référence rapide

| Méthode | Endpoint | Auth | Description |
|---|---|---|---|
| `POST` | `/api/register` | Non | Inscription |
| `POST` | `/api/login` | Non | Connexion |
| `GET` | `/api/user` | Oui | Utilisateur connecté |
| `POST` | `/api/logout` | Oui | Déconnexion |

---

## Redirection par rôle

| Rôle | `redirect_to` |
|---|---|
| `administrateur` | `/dashboard/admin` |
| `administrateur_cloud_doi` | `/dashboard/cloud-doi` |
| `securite` | `/dashboard/securite` |

---

## Variables d'environnement

Dans le `.env` de ton projet React :

```env
VITE_API_URL=http://localhost:8000/api
```

Dans le `.env` du backend Laravel :

```env
FRONTEND_URL=http://localhost:5173
CORS_ALLOWED_ORIGINS=http://localhost:5173
```

---

## Codes HTTP

| Code | Signification |
|---|---|
| `200` | Succès |
| `201` | Ressource créée (register) |
| `401` | Token absent ou invalide → rediriger vers /login |
| `422` | Erreur de validation → afficher `errors` dans le formulaire |
| `500` | Erreur serveur |
