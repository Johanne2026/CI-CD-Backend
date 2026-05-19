<?php

namespace App\Features\Auth\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mot_de_passe_actuel'        => ['required', 'current_password'],
            'mot_de_passe'               => ['required', Password::defaults(), 'confirmed'],
            'mot_de_passe_confirmation'  => ['required'],
        ]);

        $request->user()->update([
            'mot_de_passe' => $validated['mot_de_passe'],
        ]);

        return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }
}
