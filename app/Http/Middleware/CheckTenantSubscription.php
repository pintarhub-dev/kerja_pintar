<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $user->tenant ?? null;

        if ($tenant && ! $tenant->has_active_subscription) {
            return response()->json([
                'success' => false,
                'message' => 'Masa berlangganan perusahaan Anda telah habis. Harap hubungi Kami untuk melakukan perpanjangan paket agar bisa melakukan absensi.',
                'error_code' => 'SUBSCRIPTION_EXPIRED'
            ], 403);
        }

        return $next($request);
    }
}
