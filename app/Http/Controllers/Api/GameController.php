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

        $ambUsername = $this->getAMBUsername($user->username);
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
        return response()->json($result);
    }

    public function bet(Request $request): JsonResponse
    {
        $result = $this->callbackService->processBet([
            'username'   => $request->input('username'),
            'round_id'   => $request->input('roundId') ?? $request->input('round_id'),
            'game_id'    => $request->input('gameCode') ?? $request->input('game_id'),
            'provider'   => $request->input('productId') ?? $request->input('provider', 'AMB'),
            'bet_amount' => $request->input('amount') ?? $request->input('bet_amount'),
            'raw'        => $request->all(),
        ]);

        return response()->json($result);
    }

    public function win(Request $request): JsonResponse
    {
        $result = $this->callbackService->processWin([
            'username'   => $request->input('username'),
            'round_id'   => $request->input('roundId') ?? $request->input('round_id'),
            'game_id'    => $request->input('gameCode') ?? $request->input('game_id'),
            'provider'   => $request->input('productId') ?? $request->input('provider', 'AMB'),
            'win_amount' => $request->input('amount') ?? $request->input('win_amount'),
            'raw'        => $request->all(),
        ]);

        return response()->json($result);
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
    private function getAMBUsername(string $username): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $username));
    }
}