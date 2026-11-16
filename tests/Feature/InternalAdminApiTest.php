<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateInternalService;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Internal read-only admin API used by the unified admin (NestJS).
 * Verifies: service-token auth, GET-only, IP allowlist, whitelist (no settings / no write routes),
 * and that the existing admin API is unchanged (still requires a Sanctum admin login).
 */
class InternalAdminApiTest extends TestCase
{
    // No database: the project's migration set cannot run on the sqlite test DB (a migration re-declares
    // App\Models\Reward). Auth/whitelist behaviour is tested at the HTTP + middleware level instead.

    private const TOKEN = 'test-internal-admin-token-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.internal_admin.token_hashes' => hash('sha256', 'old-rotated-token-xxxxxxxxxxxxxxxxxxxxxxxx').','.hash('sha256', self::TOKEN),
            'services.internal_admin.allowed_ips' => '',
        ]);
    }

    private function auth(string $token = self::TOKEN): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'X-Acting-Admin' => '5:staff01'];
    }

    public function test_rejects_missing_wrong_and_short_tokens(): void
    {
        $this->getJson('/api/internal/admin/banners')->assertStatus(401);
        $this->getJson('/api/internal/admin/banners', $this->auth('wrong-token-wrong-token-wrong-token-xx'))->assertStatus(401);
        $this->getJson('/api/internal/admin/banners', $this->auth('short'))->assertStatus(401);
        $this->getJson('/api/internal/admin/banners?token='.self::TOKEN)->assertStatus(401); // never via query string
    }

    private function runMiddleware(string $method, ?string $token, string $ip = '127.0.0.1'): int
    {
        $request = Request::create('/api/internal/admin/banners', $method, [], [], [], ['REMOTE_ADDR' => $ip]);
        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }
        return (new AuthenticateInternalService())->handle($request, fn () => response('passed', 200))->getStatusCode();
    }

    public function test_valid_token_passes_and_rotated_tokens_both_work(): void
    {
        $this->assertSame(200, $this->runMiddleware('GET', self::TOKEN));
        $this->assertSame(200, $this->runMiddleware('GET', 'old-rotated-token-xxxxxxxxxxxxxxxxxxxxxxxx'));
        $this->assertSame(405, $this->runMiddleware('POST', self::TOKEN));
        $this->assertSame(401, $this->runMiddleware('GET', null));
        // through the real route: past auth → reaches the existing controller (not 401/403/404)
        $status = $this->getJson('/api/internal/admin/banners', $this->auth())->getStatusCode();
        $this->assertNotContains($status, [401, 403, 404, 405]);
    }

    public function test_no_token_hashes_configured_means_closed(): void
    {
        config(['services.internal_admin.token_hashes' => '']);
        $this->getJson('/api/internal/admin/banners', $this->auth())->assertStatus(401);
    }

    public function test_ip_allowlist(): void
    {
        config(['services.internal_admin.allowed_ips' => '10.9.8.7']);
        $this->getJson('/api/internal/admin/banners', $this->auth())->assertStatus(403);
        $this->assertSame(200, $this->runMiddleware('GET', self::TOKEN, '10.9.8.7'));
        $this->assertSame(403, $this->runMiddleware('GET', self::TOKEN, '10.9.8.8'));
    }

    public function test_read_only_and_whitelisted(): void
    {
        $this->postJson('/api/internal/admin/banners', [], $this->auth())->assertStatus(405);
        $this->getJson('/api/internal/admin/settings', $this->auth())->assertStatus(404); // contains the Telegram bot token
        $this->postJson('/api/internal/admin/deposits/1/approve', [], $this->auth())->assertStatus(404);
        $this->getJson('/api/internal/admin/users/abc', $this->auth())->assertStatus(404);
    }

    public function test_existing_admin_api_is_unchanged_and_ignores_the_service_token(): void
    {
        $this->getJson('/api/admin/banners')->assertStatus(401);
        // The service token is never accepted on /api/admin/*: that group is still auth:sanctum + admin, which looks
        // tokens up in personal_access_tokens (no DB in this test), while service tokens live only in env hashes.
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/admin/banners', 'GET'));
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('admin', $route->gatherMiddleware());
        $this->assertNotContains('internal.service', $route->gatherMiddleware());
    }
}
