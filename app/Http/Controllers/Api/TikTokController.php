<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platforms\TikTokService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TikTokController extends Controller
{
    protected TikTokService $tikTokService;

    public function __construct(TikTokService $tikTokService)
    {
        $this->tikTokService = $tikTokService;
    }

    public function getCreatorInfo(Request $request)
    {
        $user = $request->user();

        // Get the user's active TikTok account
        $account = $user->socialAccounts()
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'No active TikTok account found.'], 404);
        }

        try {
            $creatorInfo = $this->tikTokService->getCreatorInfo($account);
            
            // Inject the account_name (nickname) into the response so frontend can display it easily
            if (!isset($creatorInfo['nickname']) && $account->account_name) {
                $creatorInfo['nickname'] = $account->account_name;
            } elseif (!isset($creatorInfo['nickname']) && $account->username) {
                $creatorInfo['nickname'] = $account->username;
            }

            return response()->json(['data' => $creatorInfo]);
        } catch (\Exception $e) {
            Log::error('Failed to get TikTok creator info', [
                'user_id' => $user->id,
                'account_id' => $account->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Failed to retrieve TikTok creator info.'], 500);
        }
    }
}
