<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function login(Request $request): UserResource
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        // Login só existe para o SPA (origem stateful, com sessão). Outra origem recebe um erro claro, não um 500.
        if (! $request->hasSession()) {
            throw new HttpResponseException(response()->json(['message' => 'Origem não autorizada para entrar no sistema.'], 403));
        }

        // Limite por e-mail + IP. A tentativa é contada ANTES de conferir a senha (o incremento do cache é atômico):
        // requisições paralelas não passam todas pela checagem antes de alguma ser contada. Login válido zera o contador.
        $key = Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::hit($key, 60) > self::MAX_ATTEMPTS) {
            $seconds = RateLimiter::availableIn($key);
            $message = "Muitas tentativas de entrada. Tente novamente em {$seconds} segundos.";

            throw new HttpResponseException(response()->json(['message' => $message, 'errors' => ['email' => [$message]]], 429, ['Retry-After' => $seconds]));
        }

        if (! Auth::guard('web')->attempt([...$credentials, 'active' => true])) {
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }
}
