<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RealEmail implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 1. Syntax check
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $fail('يرجى إدخال بريد إلكتروني صالح.');

            return;
        }

        $domain = strtolower(substr(strrchr($value, '@'), 1));

        // 2. DNS / MX records check (checks if the domain actually exists and can receive email)
        if (! checkdnsrr($domain, 'MX') && ! checkdnsrr($domain, 'A')) {
            $fail('النطاق (Domain) الخاص بالبريد الإلكتروني غير موجود أو لا يمكنه استقبال الرسائل.');

            return;
        }

        // 3. Block common disposable/temporary email providers
        $disposableDomains = [
            'mailinator.com',
            'yopmail.com',
            'tempmail.com',
            'temp-mail.org',
            '10minutemail.com',
            'guerrillamail.com',
            'sharklasers.com',
            'trashmail.com',
            'getairmail.com',
            'dispostable.com',
            'maildrop.cc',
            'crazymailing.com',
            'owlymail.com',
            'tempail.com',
            'throwawaymail.com',
            'mailnesia.com',
            'mailcatch.com',
            'mailnull.com',
            'moakt.com',
            'guerrillamailblock.com',
            'guerrillamail.net',
            'guerrillamail.org',
            'guerrillamail.biz',
            'guerrillamail.de',
            'spam4.me',
            'grr.la',
        ];

        if (in_array($domain, $disposableDomains)) {
            $fail('غير مسموح باستخدام بريد إلكتروني مؤقت.');
        }
    }
}
