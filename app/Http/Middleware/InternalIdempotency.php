<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key for write actions from the unified admin.
 *
 * - Header "Idempotency-Key" required (16–100 chars, [A-Za-z0-9_-]).
 * - First request with a key: a "processing" row is inserted (unique key) BEFORE the action runs,
 *   then the HTTP result is stored.
 * - Same key again:  finished + same request → the stored result is returned (header Idempotent-Replay: true),
 *                    the action is NOT run again;
 *                    still running → 409;  different request with the same key → 422.
 * - The caller (NestJS) never retries a money action blindly; after a timeout it asks
 *   GET /internal/admin/operations/{key} what actually happened.
 */
class InternalIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9_\-]{16,100}$/', $key)) {
            return response()->json(['status' => 'error', 'message' => 'ต้องมี Idempotency-Key ที่ถูกต้อง'], 400);
        }

        $admin = $request->user();
        $hash = self::requestHash($request, (int) ($admin->id ?? 0));

        try {
            DB::table('internal_admin_operations')->insert([
                'idempotency_key' => $key,
                'admin_id'        => (int) ($admin->id ?? 0),
                'actor'           => substr(preg_replace('/[^\w:.\-@]/u', '', (string) $request->header('X-Acting-Admin', '')), 0, 80),
                'method'          => $request->getMethod(),
                'path'            => substr($request->path(), 0, 255),
                'request_hash'    => $hash,
                'status'          => 'processing',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (QueryException $e) {
            $row = DB::table('internal_admin_operations')->where('idempotency_key', $key)->first();
            if (! $row) {
                throw $e;
            }
            if (! hash_equals($row->request_hash, $hash)) {
                return response()->json(['status' => 'error', 'message' => 'Idempotency-Key นี้ถูกใช้กับคำสั่งอื่นแล้ว'], 422);
            }
            if ($row->status !== 'done') {
                return response()->json(['status' => 'error', 'message' => 'คำสั่งนี้กำลังดำเนินการอยู่ — รอผลก่อน ห้ามกดซ้ำ'], 409);
            }

            return response($row->response_body ?? '', (int) $row->response_status, [
                'Content-Type'       => 'application/json',
                'Idempotent-Replay'  => 'true',
            ]);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // the action threw before producing a response — release the key so the caller may decide again
            DB::table('internal_admin_operations')->where('idempotency_key', $key)->delete();
            throw $e;
        }

        DB::table('internal_admin_operations')->where('idempotency_key', $key)->update([
            'status'          => 'done',
            'response_status' => $response->getStatusCode(),
            'response_body'   => substr((string) $response->getContent(), 0, 65535),
            'updated_at'      => now(),
        ]);

        return $response;
    }

    /** method + path + admin + inputs (uploaded files by name/size/hash, not raw bytes in memory twice) */
    public static function requestHash(Request $request, int $adminId): string
    {
        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            foreach (is_array($file) ? $file : [$file] as $i => $f) {
                if ($f instanceof UploadedFile) {
                    $files["$name.$i"] = [$f->getClientOriginalName(), $f->getSize(), @hash_file('sha256', $f->getRealPath())];
                }
            }
        }
        $input = $request->except(array_keys($request->allFiles()));
        ksort($input);
        ksort($files);

        return hash('sha256', json_encode([$request->getMethod(), $request->path(), $adminId, $input, $files]));
    }
}
