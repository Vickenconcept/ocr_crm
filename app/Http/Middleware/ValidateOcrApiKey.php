<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateOcrApiKey
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = (string) config('services.ocr.api_key', '');

        if ($configuredKey === '') {
            return response()->json([
                'message' => 'OCR API key is not configured on the server.',
            ], 503);
        }

        $providedKey = (string) ($request->header('X-OCR-API-Key') ?? $request->bearerToken() ?? '');

        if (! hash_equals($configuredKey, $providedKey)) {
            return response()->json([
                'message' => 'Invalid API key.',
            ], 401);
        }

        return $next($request);
    }
}
