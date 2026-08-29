<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = $request->user()->socialAccounts()->withTrashed()->get();

        return response()->json(['data' => $accounts]);
    }

    public function destroy(Request $request, $id)
    {
        $type = $request->query('type', 'soft');
        $account = $request->user()->socialAccounts()->withTrashed()->findOrFail($id);

        if ($type === 'hard') {
            $account->forceDelete();

            return response()->json(['message' => 'Account deleted permanently.']);
        }

        $account->delete();

        return response()->json(['message' => 'Account disconnected successfully.']);
    }
}
