<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        // Force Json response
        $request->headers->set('Accept', 'application/json');

        $allowedOrigins = [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:3000',
        ];

        $origin = $request->headers->get('Origin');

        // Check if origin is allowed
        $allowOrigin = in_array($origin, $allowedOrigins, true) ? $origin : null;

        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, [
                'Access-Control-Allow-Origin' => $allowOrigin ?? $allowedOrigins[0],
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-XSRF-TOKEN',
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        // Process the actual request
        $response = $next($request);

        // Add CORS headers to response
        if ($allowOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-XSRF-TOKEN');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
