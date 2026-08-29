<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SupportMessage;
use App\Mail\FeedbackMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SupportController extends Controller
{
    public function contact(Request $request)
    {
        $request->validate([
            'message' => 'required|string|min:10',
        ]);

        $user = $request->user();

        Mail::to('support@postar.blog')->send(new SupportMessage($user, $request->message));

        return response()->json(['message' => 'Support request sent successfully.']);
    }

    public function feedback(Request $request)
    {
        $request->validate([
            'type' => 'required|in:Complaint,Suggestion',
            'message' => 'required|string|min:10',
        ]);

        $user = $request->user();

        Mail::to('team@postar.blog')->send(new FeedbackMessage($user, $request->type, $request->message));

        return response()->json(['message' => 'Feedback sent successfully.']);
    }
}
