<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platforms\InstagramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Meta callbacks for the Instagram app (registered in the Meta App Dashboard):
 *  - Deauthorize URL:    POST /api/instagram/deauthorize
 *  - Data deletion URL:  POST /api/instagram/data-deletion
 *  - Deletion status:    GET  /api/instagram/data-deletion?code=...
 *
 * Both POST callbacks carry a `signed_request` verified with the app secret.
 */
class InstagramController extends Controller
{
    public function __construct(private InstagramService $instagram) {}

    /**
     * The user removed Postar from their Instagram account: disable the
     * connection and wipe the stored token (soft delete so it can be re-linked).
     */
    public function deauthorize(Request $request): JsonResponse
    {
        $data = $this->instagram->parseSignedRequest($request->input('signed_request'));

        if (! $data || empty($data['user_id'])) {
            return response()->json(['error' => 'Invalid signed_request.'], 400);
        }

        $accounts = $this->instagram->accountsForInstagramUser((string) $data['user_id']);

        foreach ($accounts as $account) {
            $account->update([
                'access_token' => '',
                'is_active' => false,
            ]);
            $account->delete();
        }

        Log::info('Instagram deauthorize processed', ['accounts' => $accounts->count()]);

        return response()->json(['success' => true]);
    }

    /**
     * Data deletion request: permanently delete the stored Instagram connection
     * (tokens, profile data, and its post targets / analytics via FK cascade).
     * Meta requires a JSON body with a status URL and a confirmation code.
     */
    public function dataDeletion(Request $request): JsonResponse
    {
        $data = $this->instagram->parseSignedRequest($request->input('signed_request'));

        if (! $data || empty($data['user_id'])) {
            return response()->json(['error' => 'Invalid signed_request.'], 400);
        }

        $accounts = $this->instagram->accountsForInstagramUser((string) $data['user_id'], true);

        DB::transaction(function () use ($accounts) {
            foreach ($accounts as $account) {
                $account->forceDelete();
            }
        });

        $confirmationCode = $this->makeConfirmationCode();

        Log::info('Instagram data deletion processed', [
            'accounts' => $accounts->count(),
            'confirmation_code' => $confirmationCode,
        ]);

        return response()->json([
            'url' => url('/api/instagram/data-deletion').'?code='.$confirmationCode,
            'confirmation_code' => $confirmationCode,
        ]);
    }

    /**
     * Status page for a deletion request. Deletion runs synchronously, so any
     * authentic confirmation code is already complete.
     */
    public function dataDeletionStatus(Request $request): JsonResponse
    {
        $code = (string) $request->query('code', '');

        if (! $this->isValidConfirmationCode($code)) {
            return response()->json(['error' => 'Unknown confirmation code.'], 404);
        }

        return response()->json([
            'confirmation_code' => $code,
            'status' => 'completed',
            'message' => 'Your Instagram data has been deleted from Postar.',
        ]);
    }

    /**
     * "<random>.<hmac>" — verifiable later without storing anything.
     */
    private function makeConfirmationCode(): string
    {
        $random = Str::lower(Str::random(16));

        return $random.'.'.$this->codeSignature($random);
    }

    private function isValidConfirmationCode(string $code): bool
    {
        if (! preg_match('/^([a-z0-9]{16})\.([a-f0-9]{16})$/', $code, $m)) {
            return false;
        }

        return hash_equals($this->codeSignature($m[1]), $m[2]);
    }

    private function codeSignature(string $random): string
    {
        return substr(hash_hmac('sha256', 'instagram-data-deletion:'.$random, (string) config('app.key')), 0, 16);
    }
}
