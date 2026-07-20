<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AMBService;
use App\Services\GameCallbackService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $result = $this->ambService->getProducts();

        if (($result['code'] ?? 9999) !== 0) {
            return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'ไม่สามารถดึงข้อมูลได้'], 400);
        }

        return response()->json(['status' => 'success', 'data' => $result['data']]);
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

        if (!$user->isActive()) {
            return response()->json(['status' => 'error', 'message' => 'บัญชีถูกระงับ'], 403);
        }

        if ($this->walletService->getBalance($user) <= 0) {
            return response()->json(['status' => 'error', 'message' => 'ยอดเงินไม่เพียงพอ กรุณาฝากเงินก่อน'], 400);
        }

        $ambUsername = $this->getAMBUsername($user->username, $user->id);
        $isMobile = (bool) $request->input('isMobile', false);
        $callbackUrl = (string) ($request->input('callbackUrl') ?? config('app.url', ''));
        $sessionToken = substr(md5(uniqid(mt_rand(), true)), 0, 20);

        $result = $this->ambService->login(
            $ambUsername,
            (string) $data['productId'],
            (string) $data['gameCode'],
            $isMobile,
            $sessionToken,
            $callbackUrl
        );

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
        $result = $this->callbackService->getBalance($request->input('username'));

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
        $txns = $request->input('txns', []);
        $txn = $txns[0] ?? [];

        // ดึง balance ก่อนจ่าย
        $user = \App\Models\User::where('amb_username', $username)->first();
        $balanceBefore = $user ? $this->walletService->getBalance($user) : 0;

        $result = $this->callbackService->processWin([
            'username'   => $username,
            'round_id'   => $txn['roundId'] ?? $request->input('roundId'),
            'game_id'    => $txn['gameCode'] ?? $request->input('gameCode'),
            'provider'   => $request->input('productId', 'AMB'),
            'win_amount' => $txn['payoutAmount'] ?? $request->input('amount', 0),
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

    // =====================================================
    //  ประวัติการเล่นเกม (จาก DB ของเรา)
    // =====================================================
    public function history(Request $request): JsonResponse
    {
        $logs = $request->user()
            ->gameLogs()
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