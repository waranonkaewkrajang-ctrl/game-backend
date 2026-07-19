<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AMBService
{
    private string $apiUrl;
    private string $authHeader;

    public function __construct()
    {
        $this->apiUrl = config('amb.api_url');
        $agentUsername = config('amb.agent_username');
        $apiKey = config('amb.api_key');
        $this->authHeader = 'Basic ' . base64_encode($agentUsername . ':' . $apiKey);
    }

    /**
     * ดึงรายการค่ายเกมทั้งหมด
     */
    public function getProducts(): array
    {
        return $this->get('/seamless/products');
    }

    /**
     * ดึงรายการเกมของค่าย
     */
    public function getGames(string $productId): array
    {
        return $this->get('/seamless/games', ['productId' => $productId]);
    }

    /**
     * เข้าเล่นเกม (ได้ URL กลับมา)
     */
    public function login(string $username, string $productId, string $gameCode, bool $isMobile = false, string $sessionToken = '', string $callbackUrl = '', ?int $limit = null): array
    {
        $params = [
            'username'      => $username,
            'productId'     => $productId,
            'gameCode'      => $gameCode,
            'isMobileLogin' => $isMobile,
            'sessionToken'  => $sessionToken ?: $this->generateSessionToken(),
        ];

        if ($callbackUrl) {
            $params['callbackUrl'] = $callbackUrl;
        }

        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        return $this->post('/seamless/logIn', $params);
    }

    /**
     * ดึง Bet Limits
     */
    public function getBetLimits(string $productId): array
    {
        return $this->get('/seamless/betLimitsV2', ['productId' => $productId]);
    }

    /**
     * ดึงประวัติการเดิมพัน
     */
    public function getBetRecords(string $productId, string $startTime, string $endTime, ?string $nextId = null): array
    {
        $params = [
            'productId' => $productId,
            'startTime' => $startTime,
            'endTime'   => $endTime,
        ];

        if ($nextId) {
            $params['nextId'] = $nextId;
        }

        return $this->get('/seamless/betTransactionsV2', $params);
    }

    /**
     * ดู Replay
     */
    public function getReplay(string $productId, string $username, string $betId): array
    {
        return $this->get('/seamless/betTransactionReplay', [
            'productId' => $productId,
            'username'  => $username,
            'betId'     => $betId,
        ]);
    }

    /**
     * เช็คสถานะผู้เล่น
     */
    public function checkPlayerStatus(string $productId, string $username): array
    {
        return $this->get('/seamless/checkPlayerStatus', [
            'productId' => $productId,
            'username'  => $username,
        ]);
    }

    /**
     * เช็คผู้เล่นออนไลน์
     */
    public function checkOnlineStatus(string $productId, string $username): array
    {
        return $this->get('/seamless/checkOnlineStatus', [
            'productId' => $productId,
            'username'  => $username,
        ]);
    }

    /**
     * เตะผู้เล่นออก
     */
    public function kickOutPlayer(string $productId, string $username): array
    {
        return $this->post('/seamless/kickOutPlayer', [
            'productId' => $productId,
            'username'  => $username,
        ]);
    }

    /**
     * ดูเครดิต Agent
     */
    public function getAgentCredit(): array
    {
        return $this->get('/seamless/getAgentCredit');
    }

    /**
     * สร้าง Session Token
     */
    private function generateSessionToken(): string
    {
        return substr(md5(uniqid(mt_rand(), true)), 0, 20);
    }

    /**
     * HTTP GET
     */
    private function get(string $endpoint, array $params = []): array
    {
        try {
            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => $this->authHeader,
                'Content-Type'  => 'application/json',
            ])->timeout(30)->get($this->apiUrl . $endpoint, $params);

            $data = $response->json();
            Log::info('AMB GET', ['endpoint' => $endpoint, 'code' => $data['code'] ?? null]);
            return $data;
        } catch (\Exception $e) {
            Log::error('AMB GET Error', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            return ['code' => 9999, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * HTTP POST
     */
    private function post(string $endpoint, array $body = []): array
    {
        try {
            $response = Http::withoutVerifying()->withHeaders([
                'Authorization' => $this->authHeader,
                'Content-Type'  => 'application/json',
            ])->timeout(30)->post($this->apiUrl . $endpoint, $body);

            $data = $response->json();
            Log::info('AMB POST', ['endpoint' => $endpoint, 'code' => $data['code'] ?? null]);
            return $data;
        } catch (\Exception $e) {
            Log::error('AMB POST Error', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            return ['code' => 9999, 'message' => $e->getMessage(), 'data' => null];
        }
    }
}