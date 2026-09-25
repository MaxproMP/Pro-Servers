<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;
use Symfony\Component\HttpFoundation\Response;

final class VerifyFirebaseToken
{
    public function __construct(private readonly Auth $firebaseAuth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = (string) $request->header('Authorization');

        if (! str_starts_with($authorization, 'Bearer ')) {
            return response()->json(['error' => 'Token Firebase requerido.'], 401);
        }

        try {
            $token = trim(substr($authorization, 7));
            $verifiedToken = $this->firebaseAuth->verifyIdToken($token);
            $uid = (string) $verifiedToken->claims()->get('sub');

            if ($uid === '') {
                return response()->json(['error' => 'Token Firebase sin UID.'], 401);
            }

            $request->attributes->set('firebase_uid', $uid);
            $request->setUserResolver(static fn (): string => $uid);

            return $next($request);
        } catch (\Throwable) {
            return response()->json(['error' => 'Token Firebase inválido o expirado.'], 401);
        }
    }
}
