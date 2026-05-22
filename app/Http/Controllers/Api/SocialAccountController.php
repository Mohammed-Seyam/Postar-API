<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = $request->user()->socialAccounts()->get();
        return response()->json(['data' => $accounts]);
    }

    public function destroy(Request $request, $id)
    {
        $account = $request->user()->socialAccounts()->findOrFail($id);
        
        // E.g., make an API call to revoke token securely on platform endpoints
        
        $account->delete();

        return response()->json(['message' => 'Account disconnected successfully.']);
    }
}
