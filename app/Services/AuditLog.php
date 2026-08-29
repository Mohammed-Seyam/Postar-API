<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AuditLog
{
    /**
     * Log user action
     */
    public static function logAction($action, $data = [])
    {
        Log::info("User Action: $action", [
            'user_id' => auth()->id() ?? null,
            'ip_address' => request()->ip(),
            'data' => $data,
            'timestamp' => now(),
        ]);
    }

    /**
     * Log failed login attempt
     */
    public static function logFailedLogin($email, $ip)
    {
        Log::warning('Failed Login Attempt', [
            'email' => $email,
            'ip_address' => $ip,
            'timestamp' => now(),
        ]);
    }

    /**
     * Log security event
     */
    public static function logSecurityEvent($event, $details = [])
    {
        Log::alert("Security Event: $event", [
            'user_id' => auth()->id() ?? null,
            'ip_address' => request()->ip(),
            'details' => $details,
            'timestamp' => now(),
        ]);
    }

    /**
     * Log data deletion
     */
    public static function logDeletion($model, $id, $userId)
    {
        Log::warning('Data Deletion', [
            'model' => class_basename($model),
            'id' => $id,
            'deleted_by' => $userId,
            'ip_address' => request()->ip(),
            'timestamp' => now(),
        ]);
    }
}
