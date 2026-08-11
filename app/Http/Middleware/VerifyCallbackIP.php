<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyCallbackIP
{
    private array $allowedIPs = [
        "103.15.222.",
        "103.15.223.",
        "18.139.",
        "18.140.",
        "52.76.",
        "52.77.",
        "13.228.",
        "13.229.",
        "127.0.0.1",
        "31.97.220.103",
    ];

    public function handle(Request $request, Closure $next)
    {
        $ip = $request->ip();
        $allowed = false;
        
        foreach ($this->allowedIPs as $allowedIP) {
            if (str_starts_with($ip, $allowedIP)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            Log::warning("Blocked callback from IP: " . $ip, $request->all());
            return response()->json(["statusCode" => 403, "message" => "Forbidden"], 403);
        }

        return $next($request);
    }
}
