<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Monolog\Handler\NullHandler;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * เทสต์ middleware amb.ip กับ route จำลอง (ไม่เรียก GameController / ไม่แตะฐานข้อมูล)
 */
class AmbCallbackIpTest extends TestCase
{
    private const AMB      = '52.74.201.149';
    private const ATTACKER = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'amb.callback_ips'         => [self::AMB, '54.179.77.241', '47.131.243.144'],
            'amb.callback_ip_mode'     => 'enforce',
            'amb.trusted_proxies'      => ['127.0.0.1'],
            'logging.channels.amb_callback' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        ]);

        Route::post('/_test/amb-ip', fn () => response()->json(['ok' => true]))->middleware('amb.ip');
    }

    private function hit(string $remote, ?string $xff = null)
    {
        $headers = $xff !== null ? ['X-Forwarded-For' => $xff] : [];

        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->postJson('/_test/amb-ip', ['username' => 'sn00001'], $headers);
    }

    public function test_all_callback_routes_have_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/game/callback/'));

        $this->assertCount(6, $routes);
        foreach ($routes as $r) {
            $this->assertContains('amb.ip', $r->gatherMiddleware(), $r->uri());
        }
    }

    public function test_enforce_allows_whitelisted_ip_via_nginx(): void
    {
        $this->hit('127.0.0.1', self::AMB)->assertOk();
    }

    public function test_enforce_allows_whitelisted_ip_direct(): void
    {
        $this->hit(self::AMB)->assertOk();
    }

    public function test_enforce_blocks_unknown_ip(): void
    {
        $this->hit('127.0.0.1', self::ATTACKER)
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'forbidden']);
    }

    public function test_enforce_blocks_spoofed_xff_through_nginx(): void
    {
        // ผู้โจมตีส่ง X-Forwarded-For: <IP AMB> มาเอง nginx ต่อ IP จริงไว้ท้าย
        $this->hit('127.0.0.1', self::AMB . ', ' . self::ATTACKER)->assertForbidden();
    }

    public function test_enforce_ignores_xff_when_not_from_trusted_proxy(): void
    {
        // ยิงตรงไม่ผ่าน nginx แล้วแนบ header ปลอม
        $this->hit(self::ATTACKER, self::AMB)->assertForbidden();
    }

    public function test_enforce_blocks_when_nginx_sends_no_xff(): void
    {
        $this->hit('127.0.0.1')->assertForbidden();
    }

    public function test_enforce_blocks_malformed_xff(): void
    {
        $this->hit('127.0.0.1', 'not-an-ip')->assertForbidden();
    }

    public function test_enforce_with_empty_whitelist_blocks_everything(): void
    {
        config(['amb.callback_ips' => []]);

        $this->hit(self::AMB)->assertForbidden();
    }

    public function test_monitor_lets_unknown_ip_through(): void
    {
        config(['amb.callback_ip_mode' => 'monitor']);

        $this->hit('127.0.0.1', self::ATTACKER)->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_invalid_mode_falls_back_to_monitor(): void
    {
        config(['amb.callback_ip_mode' => 'enfroce']);

        $this->hit(self::ATTACKER)->assertOk();
    }

    public function test_logs_result_with_real_client_ip(): void
    {
        config(['amb.callback_ip_mode' => 'monitor']);

        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('amb_callback')->andReturn($logger);

        $this->hit('127.0.0.1', self::AMB . ', ' . self::ATTACKER)->assertOk();

        $logger->shouldHaveReceived('log')->with('warning', 'FAIL', Mockery::on(fn ($c) =>
            $c['client_ip'] === self::ATTACKER
            && $c['remote_addr'] === '127.0.0.1'
            && $c['mode'] === 'monitor'
            && $c['username'] === 'sn00001'
        ))->once();
    }
}
