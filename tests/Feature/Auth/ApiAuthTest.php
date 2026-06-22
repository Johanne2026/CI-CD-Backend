<?php

namespace Tests\Feature\Auth;

use App\Features\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Inscription ──────────────────────────────────────────────────────────

    public function test_inscription_avec_donnees_valides(): void
    {
        $response = $this->postJson('/api/register', [
            'nom'                       => 'Dupont',
            'prenom'                    => 'Jean',
            'email'                     => 'jean@example.com',
            'mot_de_passe'              => 'Azerty1234',
            'mot_de_passe_confirmation' => 'Azerty1234',
            'role'                      => 'administrateur',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['token', 'redirect_to', 'user'])
            ->assertJsonPath('redirect_to', '/dashboard/admin');

        $this->assertDatabaseHas('Utilisateurs', ['email' => 'jean@example.com']);
    }

    public function test_inscription_echoue_si_email_deja_pris(): void
    {
        User::factory()->create(['email' => 'jean@example.com']);

        $this->postJson('/api/register', [
            'nom'                       => 'Dupont',
            'prenom'                    => 'Jean',
            'email'                     => 'jean@example.com',
            'mot_de_passe'              => 'Azerty1234',
            'mot_de_passe_confirmation' => 'Azerty1234',
            'role'                      => 'administrateur',
        ])->assertStatus(422);
    }

    public function test_inscription_echoue_si_role_invalide(): void
    {
        $this->postJson('/api/register', [
            'nom'                       => 'Dupont',
            'prenom'                    => 'Jean',
            'email'                     => 'jean@example.com',
            'mot_de_passe'              => 'Azerty1234',
            'mot_de_passe_confirmation' => 'Azerty1234',
            'role'                      => 'superadmin',
        ])->assertStatus(422);
    }

    // ── Connexion ────────────────────────────────────────────────────────────

    public function test_connexion_avec_identifiants_valides(): void
    {
        User::factory()->create([
            'email'        => 'test@example.com',
            'mot_de_passe' => 'password',
            'role'         => 'administrateur_cloud_doi',
        ]);

        $response = $this->postJson('/api/login', [
            'email'        => 'test@example.com',
            'mot_de_passe' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'redirect_to', 'user'])
            ->assertJsonPath('redirect_to', '/dashboard/cloud-doi');
    }

    public function test_connexion_echoue_avec_mauvais_mot_de_passe(): void
    {
        User::factory()->create([
            'email'        => 'test@example.com',
            'mot_de_passe' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email'        => 'test@example.com',
            'mot_de_passe' => 'mauvais',
        ])->assertStatus(422);
    }

    public function test_connexion_echoue_avec_email_inexistant(): void
    {
        $this->postJson('/api/login', [
            'email'        => 'inconnu@example.com',
            'mot_de_passe' => 'password',
        ])->assertStatus(422);
    }

    // ── Déconnexion ──────────────────────────────────────────────────────────

    public function test_deconnexion_supprime_le_token(): void
    {
        $token = \Illuminate\Support\Str::random(60);
        $user  = User::factory()->create(['api_token' => hash('sha256', $token)]);

        $this->postJson('/api/logout', [], [
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->assertStatus(200);

        $this->assertNull($user->fresh()->api_token);
    }

    public function test_deconnexion_necessite_authentification(): void
    {
        $this->postJson('/api/logout')->assertStatus(401);
    }

    // ── Redirection par rôle ─────────────────────────────────────────────────

    public function test_redirection_selon_role_securite(): void
    {
        User::factory()->create([
            'email'        => 'secu@example.com',
            'mot_de_passe' => 'password',
            'role'         => 'securite',
        ]);

        $this->postJson('/api/login', [
            'email'        => 'secu@example.com',
            'mot_de_passe' => 'password',
        ])->assertJsonPath('redirect_to', '/dashboard/securite');
    }
}
