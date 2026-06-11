<?php

namespace App\Features\Deploiement\Models;

use App\Features\Auth\Models\User;
use App\Features\Projets\Models\Projet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deploiement extends Model
{
    protected $table = 'deploiements';

    protected $fillable = [
        // Identifiants
        'deployment_id',
        'ci_run_id',
        'cd_run_id',
        'projet_id',

        // Infos projet
        'nom_projet',
        'version_projet',
        'commit_hash',
        'branche',
        'environnement',

        // Statuts
        'final_statut',
        'ci_statut',
        'cd_statut',

        // Package
        'package_hash',
        'nom_package',

        // Logs
        'logs',

        // Timing
        'commence_a',
        'fini_a',
        'duree',

        // Acteurs
        'declenche_par',
        'deploye_sur_serveur_par',

        // Compatibilité ancienne structure
        'app',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'commence_a' => 'datetime',
            'fini_a'     => 'datetime',
        ];
    }

    /**
     * Calcule et met à jour final_statut selon ci_statut et cd_statut.
     * Si l'un des deux est ECHEC → final_statut = ECHEC.
     */
    public function recalculerFinalStatut(): void
    {
        if ($this->ci_statut === 'ECHEC' || $this->cd_statut === 'ECHEC') {
            $this->final_statut = 'ECHEC';
        } elseif ($this->ci_statut === 'SUCCES' && $this->cd_statut === 'SUCCES') {
            $this->final_statut = 'SUCCES';
        } elseif ($this->ci_statut === 'EN_COURS' || $this->cd_statut === 'EN_COURS') {
            $this->final_statut = 'EN_COURS';
        } else {
            $this->final_statut = 'EN_ATTENTE';
        }

        $this->save();
    }

    /**
     * Le projet associé à ce déploiement.
     */
    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'projet_id');
    }

    /**
     * L'utilisateur qui a déclenché le pipeline CI.
     */
    public function declenchePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declenche_par');
    }

    /**
     * L'utilisateur Cloud DOI qui a lancé le déploiement CD sur le serveur.
     */
    public function deployeSurServeurPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deploye_sur_serveur_par');
    }
}
