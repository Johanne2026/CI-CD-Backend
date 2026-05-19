<?php

namespace App\Features\Auth\Controllers\Settings;

use App\Features\Auth\Requests\Settings\ProfileUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verifie = null;
        }

        $request->user()->save();

        return response()->json($request->user());
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'mot_de_passe' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();
        $user->delete();

        return response()->json(['message' => 'Compte supprimé avec succès.']);
    }
}
