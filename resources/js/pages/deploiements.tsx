import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

// ─── Types ────────────────────────────────────────────────────────────────────

interface Source {
    app: string;
    version: string;
    statut: 'READY';
    dossier: string;
}

interface DeploiementEnCours {
    deploiement_id: number;
    app: string;
    statut: 'en_attente' | 'en_cours' | 'termine' | 'echoue';
    logs: string;
}

// ─── Constantes ───────────────────────────────────────────────────────────────

const API_URL = (import.meta.env.VITE_API_URL as string) ?? 'http://localhost:8000/api';

const STATUT_LABEL: Record<string, string> = {
    en_attente: 'En attente…',
    en_cours:   'En cours…',
    termine:    'Terminé',
    echoue:     'Échoué',
};

const STATUT_COLOR: Record<string, string> = {
    en_attente: 'text-yellow-600 dark:text-yellow-400',
    en_cours:   'text-blue-600 dark:text-blue-400',
    termine:    'text-green-600 dark:text-green-400',
    echoue:     'text-red-600 dark:text-red-400',
};

const TERMINAL_STATUTS = ['termine', 'echoue'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard',     href: '/dashboard' },
    { title: 'Déploiements',  href: '/deploiements' },
];

// ─── Helpers API ──────────────────────────────────────────────────────────────

function authHeaders(): HeadersInit {
    const token = localStorage.getItem('token');
    return {
        Accept:         'application/json',
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };
}

async function fetchSources(): Promise<Source[]> {
    const res = await fetch(`${API_URL}/deploiements/sources`, {
        headers: authHeaders(),
    });
    if (!res.ok) throw new Error(`Erreur ${res.status}`);
    const data = await res.json();
    return data.sources as Source[];
}

async function lancerDeploi(
    projetId: number,
    app: string,
    version: string,
): Promise<{ deploiement_id: number }> {
    const res = await fetch(`${API_URL}/projets/${projetId}/deployer`, {
        method:  'POST',
        headers: authHeaders(),
        body:    JSON.stringify({ app, version }),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error((err as any).message ?? `Erreur ${res.status}`);
    }
    return res.json();
}

async function fetchLogs(deploiementId: number): Promise<DeploiementEnCours> {
    const res = await fetch(`${API_URL}/deploiements/${deploiementId}/logs`, {
        headers: authHeaders(),
    });
    if (!res.ok) throw new Error(`Erreur ${res.status}`);
    return res.json();
}

// ─── Composant carte source ───────────────────────────────────────────────────

interface SourceCardProps {
    source: Source;
    projetId: number | null;
    onDeploy: (source: Source) => void;
    deploying: boolean;
}

function SourceCard({ source, projetId, onDeploy, deploying }: SourceCardProps) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border bg-white p-5 shadow-sm dark:bg-neutral-900">
            <div className="mb-3 flex items-start justify-between gap-2">
                <div>
                    <p className="text-xs font-medium uppercase tracking-wider text-neutral-400">
                        Application
                    </p>
                    <p className="mt-0.5 text-lg font-semibold text-neutral-900 dark:text-white">
                        {source.app}
                    </p>
                </div>
                <span className="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                    READY ✅
                </span>
            </div>

            <div className="mb-4 space-y-1 text-sm text-neutral-600 dark:text-neutral-400">
                <p>
                    <span className="font-medium text-neutral-700 dark:text-neutral-300">Version :</span>{' '}
                    {source.version}
                </p>
                <p>
                    <span className="font-medium text-neutral-700 dark:text-neutral-300">Dossier :</span>{' '}
                    <code className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800">
                        {source.dossier}
                    </code>
                </p>
            </div>

            <button
                onClick={() => onDeploy(source)}
                disabled={deploying || projetId === null}
                className="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                {deploying ? 'Lancement…' : '🚀 Déployer'}
            </button>

            {projetId === null && (
                <p className="mt-2 text-center text-xs text-neutral-400">
                    Sélectionnez un projet pour déployer
                </p>
            )}
        </div>
    );
}

// ─── Composant panneau de logs ────────────────────────────────────────────────

interface LogsPanelProps {
    deploy: DeploiementEnCours;
    onClose: () => void;
}

function LogsPanel({ deploy, onClose }: LogsPanelProps) {
    const logsRef = useRef<HTMLPreElement>(null);

    // Auto-scroll vers le bas à chaque nouveau log
    useEffect(() => {
        if (logsRef.current) {
            logsRef.current.scrollTop = logsRef.current.scrollHeight;
        }
    }, [deploy.logs]);

    const isTerminal = TERMINAL_STATUTS.includes(deploy.statut);

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border bg-white shadow-sm dark:bg-neutral-900">
            {/* Header */}
            <div className="flex items-center justify-between border-b border-neutral-200 px-5 py-3 dark:border-neutral-700">
                <div className="flex items-center gap-3">
                    <span className="text-sm font-semibold text-neutral-800 dark:text-white">
                        Déploiement #{deploy.deploiement_id} — {deploy.app}
                    </span>
                    <span className={`text-sm font-medium ${STATUT_COLOR[deploy.statut] ?? ''}`}>
                        {!isTerminal && (
                            <span className="mr-1 inline-block animate-spin">⟳</span>
                        )}
                        {STATUT_LABEL[deploy.statut] ?? deploy.statut}
                    </span>
                </div>
                {isTerminal && (
                    <button
                        onClick={onClose}
                        className="rounded-md px-3 py-1 text-xs text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800"
                    >
                        Fermer
                    </button>
                )}
            </div>

            {/* Terminal logs */}
            <pre
                ref={logsRef}
                className="h-64 overflow-y-auto bg-neutral-950 p-4 font-mono text-xs leading-relaxed text-green-400"
            >
                {deploy.logs
                    ? deploy.logs
                    : (
                        <span className="animate-pulse text-neutral-500">
                            En attente des logs…
                        </span>
                    )
                }
            </pre>
        </div>
    );
}

// ─── Page principale ──────────────────────────────────────────────────────────

export default function Deploiements() {
    const [sources, setSources]         = useState<Source[]>([]);
    const [loading, setLoading]         = useState(true);
    const [error, setError]             = useState<string | null>(null);

    // ID du projet sélectionné (à adapter selon votre UI de sélection de projet)
    // Pour l'instant on expose un champ de saisie simple
    const [projetId, setProjetId]       = useState<number | null>(null);
    const [projetInput, setProjetInput] = useState('');

    const [deploying, setDeploying]           = useState(false);
    const [deployError, setDeployError]       = useState<string | null>(null);
    const [currentDeploy, setCurrentDeploy]   = useState<DeploiementEnCours | null>(null);

    const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

    // ── Scan des sources ──────────────────────────────────────────────────────

    const scannerSources = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const data = await fetchSources();
            setSources(data);
        } catch (e: unknown) {
            setError(e instanceof Error ? e.message : 'Erreur lors du scan.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        scannerSources();
    }, [scannerSources]);

    // ── Polling des logs ──────────────────────────────────────────────────────

    const stopPolling = useCallback(() => {
        if (pollingRef.current) {
            clearInterval(pollingRef.current);
            pollingRef.current = null;
        }
    }, []);

    const startPolling = useCallback(
        (deploiementId: number, app: string) => {
            stopPolling();

            const poll = async () => {
                try {
                    const data = await fetchLogs(deploiementId);
                    setCurrentDeploy({ ...data, app });

                    if (TERMINAL_STATUTS.includes(data.statut)) {
                        stopPolling();
                        setDeploying(false);
                    }
                } catch {
                    // silencieux — on réessaie au prochain tick
                }
            };

            poll(); // premier appel immédiat
            pollingRef.current = setInterval(poll, 3000);
        },
        [stopPolling],
    );

    useEffect(() => () => stopPolling(), [stopPolling]);

    // ── Lancer un déploiement ─────────────────────────────────────────────────

    const handleDeploy = useCallback(
        async (source: Source) => {
            if (projetId === null) return;

            setDeploying(true);
            setDeployError(null);
            setCurrentDeploy(null);

            try {
                const { deploiement_id } = await lancerDeploi(projetId, source.app, source.version);

                // Initialiser l'état local du déploiement
                setCurrentDeploy({
                    deploiement_id,
                    app:    source.app,
                    statut: 'en_attente',
                    logs:   '',
                });

                startPolling(deploiement_id, source.app);
            } catch (e: unknown) {
                setDeployError(e instanceof Error ? e.message : 'Erreur lors du déploiement.');
                setDeploying(false);
            }
        },
        [projetId, startPolling],
    );

    // ── Rendu ─────────────────────────────────────────────────────────────────

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Déploiements" />

            <div className="flex flex-col gap-6 p-6">

                {/* ── Sélection du projet ── */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border bg-white p-5 dark:bg-neutral-900">
                    <h2 className="mb-3 text-sm font-semibold text-neutral-800 dark:text-white">
                        Projet cible
                    </h2>
                    <div className="flex items-center gap-3">
                        <input
                            type="number"
                            min={1}
                            placeholder="ID du projet (ex: 1)"
                            value={projetInput}
                            onChange={(e) => {
                                setProjetInput(e.target.value);
                                const n = parseInt(e.target.value, 10);
                                setProjetId(isNaN(n) ? null : n);
                            }}
                            className="w-48 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-blue-500 focus:outline-none dark:border-neutral-600 dark:bg-neutral-800 dark:text-white"
                        />
                        {projetId !== null && (
                            <span className="text-sm text-green-600 dark:text-green-400">
                                ✓ Projet #{projetId} sélectionné
                            </span>
                        )}
                    </div>
                </div>

                {/* ── En-tête Sources ── */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-neutral-900 dark:text-white">
                            Sources disponibles
                        </h1>
                        <p className="mt-0.5 text-sm text-neutral-500">
                            Dossier partagé :{' '}
                            <code className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800">
                                D:\MATCHI01\Deploy\Sources
                            </code>
                        </p>
                    </div>
                    <button
                        onClick={scannerSources}
                        disabled={loading}
                        className="rounded-lg border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                    >
                        {loading ? 'Scan…' : '↻ Actualiser'}
                    </button>
                </div>

                {/* ── États ── */}
                {error && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-400">
                        {error}
                    </div>
                )}

                {deployError && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-400">
                        {deployError}
                    </div>
                )}

                {/* ── Grille des sources ── */}
                {loading ? (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {[1, 2, 3].map((i) => (
                            <div
                                key={i}
                                className="border-sidebar-border/70 h-44 animate-pulse rounded-xl border bg-neutral-100 dark:bg-neutral-800"
                            />
                        ))}
                    </div>
                ) : sources.length === 0 ? (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed py-16 text-center">
                        <span className="text-3xl">📂</span>
                        <p className="text-sm font-medium text-neutral-600 dark:text-neutral-400">
                            Aucune source détectée
                        </p>
                        <p className="text-xs text-neutral-400">
                            Déposez un dossier contenant un{' '}
                            <code className="rounded bg-neutral-100 px-1 dark:bg-neutral-800">
                                deploy.meta.json
                            </code>{' '}
                            dans le dossier Sources.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {sources.map((source) => (
                            <SourceCard
                                key={source.dossier}
                                source={source}
                                projetId={projetId}
                                onDeploy={handleDeploy}
                                deploying={deploying}
                            />
                        ))}
                    </div>
                )}

                {/* ── Panneau de logs ── */}
                {currentDeploy && (
                    <LogsPanel
                        deploy={currentDeploy}
                        onClose={() => {
                            setCurrentDeploy(null);
                            setDeploying(false);
                        }}
                    />
                )}
            </div>
        </AppLayout>
    );
}
