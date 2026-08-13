<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AMBService;
use App\Services\GameCallbackService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GameController extends Controller
{
    public function __construct(
        private AMBService $ambService,
        private GameCallbackService $callbackService,
        private WalletService $walletService,
    ) {}

    // =====================================================
    //  ดึงรายการค่ายเกมทั้งหมด
    // =====================================================
    public function getProducts(): JsonResponse
    {
        // ดึงค่ายจาก DB (เฉพาะที่ active) เรียงตามชื่อ
        $products = \DB::table('games')
            ->where('is_active', true)
            ->distinct()
            ->orderBy('product_id')
            ->pluck('product_id')
            ->toArray();
        return response()->json(['status' => 'success', 'data' => $products]);
    }

    // =====================================================
    //  ดึงรายการเกมของค่าย
    // =====================================================
    public function listGames(Request $request): JsonResponse
    {
        $request->validate(['productId' => 'required|string']);

        $result = $this->ambService->getGames($request->productId);

        if (($result['code'] ?? 9999) !== 0) {
            return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'ไม่สามารถดึงข้อมูลได้'], 400);
        }

        return response()->json(['status' => 'success', 'data' => $result['data']]);
    }

    public function launchGame(Request $request): JsonResponse
    {
        $data = $request->validate([
            'productId' => 'required|string',
            'gameCode'  => 'required|string',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนเข้าเล่นเกม'], 401);
        }

        if (!$user->username) {
            Log::warning('launchGame: user has no username', ['user_id' => $user->id]);
            return response()->json(['status' => 'error', 'message' => 'บัญชียังไม่พร้อมใช้งาน'], 400);
        }

        if (!$user->isActive()) {
            return response()->json(['status' => 'error', 'message' => 'บัญชีถูกระงับ'], 403);
        }

        if ($this->walletService->getBalance($user) <= 0) {
            return response()->json(['status' => 'error', 'message' => 'ยอดเงินไม่เพียงพอ กรุณาฝากเงินก่อน'], 400);
        }

        $ambUsername = $this->getAMBUsername($user->username, $user->id);
        
        // บันทึก amb_username ถ้ายังไม่มี
        if (!$user->amb_username) {
            $user->update(['amb_username' => $ambUsername]);
        }
        $isMobile = (bool) $request->input('isMobile', false);
        $callbackUrl = (string) ($request->input('callbackUrl') ?? config('app.url', ''));
        $sessionToken = substr(md5(uniqid(mt_rand(), true)), 0, 20);

        try {
            $result = $this->ambService->login(
                $ambUsername,
                (string) $data['productId'],
                (string) $data['gameCode'],
                $isMobile,
                $sessionToken,
                $callbackUrl
            );
        } catch (\Throwable $e) {
            Log::error('launchGame provider failed', [
                'user'      => $user->username,
                'productId' => $data['productId'],
                'gameCode'  => $data['gameCode'],
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'เกมไม่พร้อมให้บริการในขณะนี้ กรุณาลองใหม่ภายหลัง'
            ], 503);
        }

        if (($result['code'] ?? 9999) !== 0) {
            $msg = $result['message'] ?? 'Unknown error';
            if (is_array($msg)) $msg = json_encode($msg);
            return response()->json(['status' => 'error', 'message' => 'เปิดเกมไม่สำเร็จ: ' . $msg], 400);
        }

        $gameUrl = $result['data']['url'] ?? '';
        if (is_array($gameUrl)) $gameUrl = $gameUrl[0] ?? '';

        return response()->json([
            'status' => 'success',
            'data'   => [
                'game_url'  => $gameUrl,
                'productId' => $data['productId'],
            ],
        ]);
    }

    // =====================================================
    //  CALLBACK จากค่ายเกม (Seamless Wallet)
    //  ค่ายเกมเรียกมาหา — ไม่ใช้ user auth
    // =====================================================
    public function getBalance(Request $request): JsonResponse
    {
        $username = $request->input('username');
        
        // 🔒 Validate username
        if (empty($username) || !is_string($username)) {
            \Log::warning('Callback getBalance: username missing', $request->all());
            return response()->json([
                'id'              => $request->input('id', uniqid()),
                'statusCode'      => 10002,  // Invalid parameter
                'timestampMillis' => (int) round(microtime(true) * 1000),
                'productId'       => $request->input('productId', ''),
                'currency'        => $request->input('currency', 'THB'),
                'balance'         => 0,
                'message'         => 'username is required',
            ]);
        }
        
        $result = $this->callbackService->getBalance($username);
        $statusCode = ($result['status'] === 'success') ? 0 : 10001;

        return response()->json([
            'id'              => $request->input('id', uniqid()),
            'statusCode'      => $statusCode,
            'timestampMillis' => (int) round(microtime(true) * 1000),
            'productId'       => $request->input('productId', ''),
            'currency'        => $request->input('currency', 'THB'),
            'balance'         => (float) ($result['balance'] ?? 0),
            'username'        => $request->input('username', ''),
        ]);
    }

    public function bet(Request $request): JsonResponse
    {
        $username = $request->input('username');

        // 🔒 Validate username
        if (empty($username) || !is_string($username)) {
            Log::warning('Callback bet: username missing', $request->all());
            return response()->json([
                'id'              => $request->input('id', uniqid()),
                'statusCode'      => 10002,
                'timestampMillis' => (int) round(microtime(true) * 1000),
                'productId'       => $request->input('productId', ''),
                'currency'        => $request->input('currency', 'THB'),
                'balanceBefore'   => 0,
                'balanceAfter'    => 0,
                'username'        => '',
            ]);
        }

        $txns = $request->input('txns', []);
        $txn = $txns[0] ?? [];

        // ดึง balance ก่อนหัก
        $user = \App\Models\User::where('amb_username', $username)->first();
        $balanceBefore = $user ? $this->walletService->getBalance($user) : 0;

        $result = $this->callbackService->processBet([
            'username'   => $username,
            'round_id'   => $txn['roundId'] ?? $request->input('roundId'),
            'game_id'    => $txn['gameCode'] ?? $request->input('gameCode'),
            'provider'   => $request->input('productId', 'AMB'),
            'bet_amount' => $txn['betAmount'] ?? $request->input('amount', 0),
            'raw'        => $request->all(),
        ]);

        $statusCode = ($result['status'] === 'success') ? 0 : 10001;
        $balanceAfter = (float) ($result['balance'] ?? $balanceBefore);

        return response()->json([
            'id'              => $request->input('id', uniqid()),
            'statusCode'      => $statusCode,
            'timestampMillis' => (int) round(microtime(true) * 1000),
            'productId'       => $request->input('productId', ''),
            'currency'        => $request->input('currency', 'THB'),
            'balanceBefore'   => (float) $balanceBefore,
            'balanceAfter'    => (float) $balanceAfter,
            'username'        => $username,
        ]);
    }

    public function win(Request $request): JsonResponse
    {
        $username = $request->input('username');

        // 🔒 Validate username
        if (empty($username) || !is_string($username)) {
            Log::warning('Callback win: username missing', $request->all());
            return response()->json([
                'id'              => $request->input('id', uniqid()),
                'statusCode'      => 10002,
                'timestampMillis' => (int) round(microtime(true) * 1000),
                'productId'       => $request->input('productId', ''),
                'currency'        => $request->input('currency', 'THB'),
                'balanceBefore'   => 0,
                'balanceAfter'    => 0,
                'username'        => '',
            ]);
        }

        $txns = $request->input('txns', []);
        $txn = $txns[0] ?? [];

        $user = \App\Models\User::where('amb_username', $username)->first();
        $balanceBefore = $user ? $this->walletService->getBalance($user) : 0;

        $isSingleState = (bool) ($txn['isSingleState'] ?? false);
        $betAmount = (float) ($txn['betAmount'] ?? 0);
        $payoutAmount = (float) ($txn['payoutAmount'] ?? $request->input('amount', 0));
        $roundId = $txn['roundId'] ?? $request->input('roundId');
        $gameCode = $txn['gameCode'] ?? $request->input('gameCode');
        $provider = $request->input('productId', 'AMB');

        // Single-state (เช่น PGSOFT): หัก betAmount ก่อน แล้วค่อยบวก payoutAmount
        if ($isSingleState && $betAmount > 0) {
            $this->callbackService->processBet([
                'username'   => $username,
                'round_id'   => $roundId,
                'game_id'    => $gameCode,
                'provider'   => $provider,
                'bet_amount' => $betAmount,
                'raw'        => $request->all(),
            ]);
        }

        // บวก payoutAmount (ถ้ามี)
        if ($payoutAmount > 0) {
            $result = $this->callbackService->processWin([
                'username'   => $username,
                'round_id'   => $roundId,
                'game_id'    => $gameCode,
                'provider'   => $provider,
                'win_amount' => $payoutAmount,
                'raw'        => $request->all(),
            ]);
        } else {
            // แพ้ (payoutAmount = 0) ไม่ต้องบวกเงิน
            $result = ['status' => 'success', 'balance' => $user ? $this->walletService->getBalance($user) : 0];
        }

        $statusCode = ($result['status'] === 'success') ? 0 : 10001;
        $balanceAfter = (float) ($result['balance'] ?? ($user ? $this->walletService->getBalance($user) : 0));

        return response()->json([
            'id'              => $request->input('id', uniqid()),
            'statusCode'      => $statusCode,
            'timestampMillis' => (int) round(microtime(true) * 1000),
            'productId'       => $request->input('productId', ''),
            'currency'        => $request->input('currency', 'THB'),
            'balanceBefore'   => (float) $balanceBefore,
            'balanceAfter'    => (float) $balanceAfter,
            'username'        => $username,
        ]);
    }

    // =====================================================
    //  ดูเครดิต Agent
    // =====================================================
    public function agentCredit(): JsonResponse
    {
        $result = $this->ambService->getAgentCredit();

        if (($result['code'] ?? 9999) !== 0) {
            return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'ดึงข้อมูลไม่ได้'], 400);
        }

        return response()->json(['status' => 'success', 'data' => $result['data']]);
    }

    // =====================================================
    //  ดึงประวัติเดิมพันจาก AMB
    // =====================================================
    public function betRecords(Request $request): JsonResponse
    {
        $data = $request->validate([
            'productId' => 'required|string',
            'startTime' => 'required|string',
            'endTime'   => 'required|string',
        ]);

        $result = $this->ambService->getBetRecords($data['productId'], $data['startTime'], $data['endTime'], $request->nextId);

        if (($result['code'] ?? 9999) !== 0) {
            return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'ดึงข้อมูลไม่ได้'], 400);
        }

        return response()->json(['status' => 'success', 'data' => $result['data']]);
    }

public function recentlyPlayed(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = (int) $request->query('limit', 12);
        $limit = max(1, min($limit, 30));

        // Step 1: หา distinct games ที่เล่นล่าสุด
        $recentLogs = \DB::table('game_logs')
            ->select(
                'provider',
                'game_id',
                \DB::raw('MAX(created_at) as last_played_at')
            )
            ->where('user_id', $userId)
            ->where('action', 'bet')
            ->groupBy('provider', 'game_id')
            ->orderByDesc('last_played_at')
            ->limit($limit)
            ->get();

        // Step 2: Join กับตาราง games เพื่อเอา game_name, image_url
        $result = $recentLogs->map(function ($log) {
            $game = \DB::table('games')
                ->where('product_id', $log->provider)
                ->where('game_code', $log->game_id)
                ->where('is_active', true)
                ->select('id', 'product_id', 'game_code', 'game_name', 'game_name_th', 'image_url', 'category', 'type')
                ->first();

            return [
                'provider'       => $log->provider,
                'game_id'        => $log->game_id,
                'last_played_at' => $log->last_played_at,
                // ข้อมูลเกมจาก join
                'id'             => $game?->id,
                'product_id'     => $game?->product_id ?? $log->provider,
                'game_code'      => $game?->game_code ?? $log->game_id,
                'game_name'      => $game?->game_name ?? $log->game_id,
                'game_name_th'   => $game?->game_name_th,
                'image_url'      => $game?->image_url,
                'category'       => $game?->category,
                'type'           => $game?->type,
            ];
        })->filter(fn($item) => $item['id'] !== null); // เอาแค่เกมที่มีในระบบ

        return response()->json([
            'status' => 'success',
            'data'   => $result->values(),
        ]);
    }

    // =====================================================
    //  ประวัติการเล่นเกม (จาก DB ของเรา)
    // =====================================================
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบ'], 401);
        }

        $logs = $user->gameLogs()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['status' => 'success', 'data' => $logs]);
    }

    // =====================================================
    //  แปลง username
    // =====================================================
    private function getAMBUsername(string $username, ?int $userId = null): string
   {
    return 'sn' . str_pad($userId ?? 0, 5, '0', STR_PAD_LEFT);
    // ผลลัพธ์: sn00001, sn00002, sn00003...
   }
}