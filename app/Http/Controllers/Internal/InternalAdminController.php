<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\BankChangeRequest;
use App\Models\GameLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Internal (unified admin) versions of the old admin routes that are written as closures in routes/api.php.
 * Closures cannot be re-used from another route file, so the bodies are copied here 1:1 (same queries,
 * same validation, same responses). The old admin routes are NOT changed.
 *
 * Only difference: settings never return secret values (token/password…) — they are masked, and a masked
 * value sent back is ignored so saving the settings page does not overwrite the real secret.
 */
class InternalAdminController extends Controller
{
    public const MASK = '********';

    private static function isSecretKey(string $key): bool
    {
        $patterns = array_filter(array_map('trim', explode(',', strtolower((string) config('services.internal_admin.secret_setting_patterns')))));
        $k = strtolower($key);
        foreach ($patterns as $p) {
            if ($p !== '' && str_contains($k, $p)) {
                return true;
            }
        }
        return false;
    }

    // ---------- settings ----------
    public function settings(): JsonResponse
    {
        $all = Setting::pluck('value', 'key');
        $out = [];
        foreach ($all as $key => $value) {
            $out[$key] = (self::isSecretKey((string) $key) && $value !== null && $value !== '') ? self::MASK : $value;
        }
        return response()->json($out);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        foreach ($request->all() as $key => $value) {
            if ($value === self::MASK && self::isSecretKey((string) $key)) {
                continue; // unchanged secret — keep the stored value
            }
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        return response()->json(['message' => 'success']);
    }

    public function telegramTest(Request $request): JsonResponse
    {
        $token = $request->bot_token === self::MASK ? Setting::getValue('telegram_bot_token') : $request->bot_token;
        $service = new \App\Services\TelegramService();
        $result = $service->sendTest($token, $request->chat_id);
        if ($result) {
            return response()->json(['status' => 'success', 'message' => 'ส่งสำเร็จ']);
        }
        return response()->json(['status' => 'error', 'message' => 'ส่งไม่สำเร็จ เช็ค Token กับ Chat ID'], 400);
    }

    public function telegramFetchChats(Request $request): JsonResponse
    {
        $given = $request->input('bot_token');
        $token = ($given && $given !== self::MASK) ? $given : Setting::getValue('telegram_bot_token');
        if (! $token) {
            return response()->json(['status' => 'error', 'message' => 'กรุณาใส่ Bot Token ก่อน'], 400);
        }
        return response()->json(app(\App\Services\TelegramService::class)->fetchChatIds($token));
    }

    // ---------- reads (copied from routes/api.php closures) ----------
    public function transactions(Request $request): JsonResponse
    {
        $query = DB::table('transactions')
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->leftJoin('admins', 'transactions.processed_by', '=', 'admins.id')
            ->select('transactions.*', 'users.username', 'users.phone', 'admins.name as admin_name')
            ->orderBy('transactions.created_at', 'desc')
            ->orderBy('transactions.id', 'desc'); // stable pages: many rows share the same created_at
        if ($request->filled('type')) {
            $query->where('transactions.type', $request->type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('users.username', 'like', "%{$s}%")
                  ->orWhere('users.phone', 'like', "%{$s}%")
                  ->orWhere('transactions.reference_id', 'like', "%{$s}%");
            });
        }
        // Without a search the joins cannot change the row count (transactions.user_id is a NOT NULL FK with
        // ON DELETE CASCADE → every row has its user; admins is a LEFT JOIN) → count transactions alone:
        // 1.7 s → 0.2 s on 2M rows. With a search the full joined count is still used.
        $total = $request->filled('search') ? null : DB::table('transactions')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(min((int) $request->input('per_page', 20), 1000), ['*'], 'page', null, $total),
        ]);
    }

    public function bankChanges(Request $request): JsonResponse
    {
        $query = BankChangeRequest::with('user:id,username,full_name,phone')->orderBy('created_at', 'desc');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        return response()->json($query->paginate(20));
    }

    public function referrals(Request $request): JsonResponse
    {
        $query = User::withCount(['referrals as referred_count'])->orderBy('referred_count', 'desc');
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('referral_code', 'like', "%{$search}%");
            });
        }
        $page = $query->paginate(50);

        // เพื่อนของผู้แนะนำในหน้านี้ + สัญญาณ "อาจชวนตัวเอง" (แจ้งเตือนอย่างเดียว ไม่บล็อก)
        $ids = $page->getCollection()->where('referred_count', '>', 0)->pluck('id');
        $friends = $ids->isEmpty() ? collect() : User::whereIn('referred_by', $ids)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'username', 'referred_by', 'status', 'bank_name', 'full_name', 'last_login_ip', 'created_at'])
            ->groupBy('referred_by');

        $page->getCollection()->transform(function (User $u) use ($friends) {
            $list = ($friends[$u->id] ?? collect())->map(fn (User $f) => [
                'id'         => $f->id,
                'username'   => $f->username,
                'status'     => $f->status,
                'created_at' => $f->created_at,
                'flags'      => self::referralFlags($u, $f),
            ])->values();
            $u->setAttribute('friends', $list);
            $u->setAttribute('flagged_count', $list->filter(fn ($f) => count($f['flags']) > 0)->count());
            return $u;
        });

        return response()->json(['data' => $page]);
    }

    /**
     * จุดที่ผู้แนะนำกับเพื่อนซ้ำกัน — ชื่อบัญชีธนาคาร / ชื่อจริง / IP ล่าสุด
     * (ระบบเก็บแค่ IP ล่าสุดของแต่ละคน จึงเป็นสัญญาณให้แอดมินตรวจต่อ ไม่ใช่หลักฐาน)
     *
     * @return list<string>
     */
    public static function referralFlags(User $referrer, User $friend): array
    {
        $norm = function (?string $name): string {
            $n = mb_strtolower(trim((string) $name));
            $n = preg_replace('/^(นาย|นางสาว|นาง|น\.ส\.|mr\.?|mrs\.?|ms\.?|miss)\s*/u', '', $n);
            return preg_replace('/\s+/u', '', $n);
        };
        $flags = [];
        if ($norm($referrer->bank_name) !== '' && $norm($referrer->bank_name) === $norm($friend->bank_name)) {
            $flags[] = 'same_bank_name';
        }
        if ($norm($referrer->full_name) !== '' && $norm($referrer->full_name) === $norm($friend->full_name)) {
            $flags[] = 'same_full_name';
        }
        if ($referrer->last_login_ip && $referrer->last_login_ip === $friend->last_login_ip) {
            $flags[] = 'same_ip';
        }
        return $flags;
    }

    public function gameLogs(Request $request): JsonResponse
    {
        $query = GameLog::with('user')->orderBy('created_at', 'desc')->orderBy('id', 'desc'); // stable pages
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('game_name', 'like', "%{$search}%")
                  ->orWhere('round_id', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('username', 'like', "%{$search}%");
                  });
        }
        return response()->json(['data' => $query->paginate(50)]);
    }

    public function ipCheck(Request $request): JsonResponse
    {
        $query = User::whereNotNull('last_login_ip')->orderBy('last_login_at', 'desc');
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('username', 'like', "%{$search}%")
                  ->orWhere('last_login_ip', 'like', "%{$search}%");
        }
        return response()->json(['data' => $query->paginate(50)]);
    }

    // ---------- writes (copied from routes/api.php closures) ----------
    public function approveBankChange(Request $request, $id): JsonResponse
    {
        $req = BankChangeRequest::findOrFail($id);
        if ($req->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'คำขอนี้ถูกดำเนินการแล้ว'], 400);
        }
        User::where('id', $req->user_id)->update([
            'bank_code'    => $req->new_bank_code,
            'bank_account' => $req->new_bank_account,
            'bank_name'    => $req->new_bank_name,
        ]);
        $req->update([
            'status'      => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_note'  => $request->input('note'),
        ]);
        return response()->json(['status' => 'success', 'message' => 'อนุมัติเปลี่ยนบัญชีสำเร็จ']);
    }

    public function rejectBankChange(Request $request, $id): JsonResponse
    {
        $req = BankChangeRequest::findOrFail($id);
        if ($req->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'คำขอนี้ถูกดำเนินการแล้ว'], 400);
        }
        $req->update([
            'status'      => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_note'  => $request->input('note', 'ข้อมูลไม่ตรงกับที่สมัคร'),
        ]);
        return response()->json(['status' => 'success', 'message' => 'ปฏิเสธคำขอแล้ว']);
    }

    public function resetMemberPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => 'required|string|min:6|max:50']);
        $user->update(['password' => Hash::make($data['password'])]);
        return response()->json(['status' => 'success', 'message' => 'รีเซ็ตรหัสผ่านสำเร็จ']);
    }

    // ---------- operation outcome (for the caller after a timeout) ----------
    public function operation(string $key): JsonResponse
    {
        $row = DB::table('internal_admin_operations')->where('idempotency_key', $key)->first();
        if (! $row) {
            return response()->json(['state' => 'not_found'], 404);
        }
        return response()->json([
            'state'           => $row->status,              // processing | done
            'response_status' => $row->response_status,
            'response'        => $row->response_body ? json_decode($row->response_body, true) : null,
            'created_at'      => $row->created_at,
            'updated_at'      => $row->updated_at,
        ]);
    }
}
