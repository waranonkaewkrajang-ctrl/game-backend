<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Monolog\Handler\NullHandler;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * เทสต์ middleware truewallet.sig กับ route จำลอง (ไม่เรียก controller / ไม่แตะฐานข้อมูล)
 */
class TrueWalletSignatureTest extends TestCase
{
    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'truewallet.webhook_secret'  => self::SECRET,
            'truewallet.webhook_mode'    => 'enforce',
            'truewallet.trusted_proxies' => ['127.0.0.1'],
            'logging.channels.truewallet_webhook' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        ]);

        Route::post('/_test/tw', fn () => response()->json(['ok' => true]))->middleware('truewallet.sig');
    }

    private function jwt(array $payload, string $secret = self::SECRET, string $alg = 'HS256'): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $h   = $b64(['typ' => 'JWT', 'alg' => $alg]);
        $p   = $b64($payload);
        $s   = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$p}", $secret, true)), '+/', '-_'), '=');

        return "{$h}.{$p}.{$s}";
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['event_type' => 'P2P', 'amount' => 50000, 'sender_mobile' => '0812345678', 'transaction_id' => 'T1'];
    }

    private function send(string $body)
    {
        return $this->call('POST', '/_test/tw', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    public function test_route_has_middleware(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/truewallet/webhook');

        $this->assertNotNull($route);
        $this->assertContains('truewallet.sig', $route->gatherMiddleware());
    }

    public function test_valid_jwt_in_message_field_passes(): void
    {
        $this->send(json_encode(['message' => $this->jwt($this->payload())]))->assertOk();
    }

    public function test_valid_raw_jwt_passes(): void
    {
        $this->send($this->jwt($this->payload()))->assertOk();
    }

    public function test_plain_json_is_blocked_in_enforce(): void
    {
        $this->send(json_encode($this->payload()))->assertStatus(401)->assertExactJson(['status' => 'error']);
    }

    public function test_wrong_secret_is_blocked(): void
    {
        $this->send(json_encode(['message' => $this->jwt($this->payload(), 'other-secret')]))->assertStatus(401);
    }

    public function test_alg_none_is_blocked(): void
    {
        $this->send(json_encode(['message' => $this->jwt($this->payload(), self::SECRET, 'none')]))->assertStatus(401);
    }

    public function test_expired_jwt_is_blocked(): void
    {
        $this->send($this->jwt($this->payload(['exp' => time() - 10])))->assertStatus(401);
    }

    public function test_ping_and_empty_body_pass_in_enforce(): void
    {
        $this->postJson('/_test/tw?test=1')->assertOk();
        $this->send('')->assertOk();
    }

    public function test_enforce_without_secret_blocks_signed_request(): void
    {
        config(['truewallet.webhook_secret' => '']);

        $this->send($this->jwt($this->payload()))->assertStatus(401);
    }

    public function test_monitor_lets_unsigned_through(): void
    {
        config(['truewallet.webhook_mode' => 'monitor']);

        $this->send(json_encode($this->payload()))->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_invalid_mode_falls_back_to_monitor(): void
    {
        config(['truewallet.webhook_mode' => 'enfroce']);

        $this->send(json_encode($this->payload()))->assertOk();
    }

    public function test_logs_unsigned_with_claimed_fields_and_masked_phone(): void
    {
        config(['truewallet.webhook_mode' => 'monitor']);

        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('truewallet_webhook')->andReturn($logger);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->call('POST', '/_test/tw', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], json_encode($this->payload()))
            ->assertOk();

        $logger->shouldHaveReceived('log')->with('warning', 'UNSIGNED', Mockery::on(fn ($c) =>
            $c['format'] === 'plain_json'
            && $c['action'] === 'pass'
            && $c['transaction_id'] === 'T1'
            && $c['sender_tail'] === '5678'
            && $c['client_ip'] === '203.0.113.9'
        ))->once();
    }
}
