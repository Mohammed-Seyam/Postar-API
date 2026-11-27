<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One-time verification code (registration, email change, password change,
 * account deletion). Rendered with the shared email layout.
 */
class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    private const PURPOSES = [
        'register' => ['subject' => 'رمز التحقق لإنشاء حسابك', 'action' => 'لإنشاء الحساب'],
        'email_change' => ['subject' => 'رمز التحقق لتغيير البريد الإلكتروني', 'action' => 'لتغيير البريد الإلكتروني'],
        'password_change' => ['subject' => 'رمز التحقق لتغيير كلمة المرور', 'action' => 'لتغيير كلمة المرور'],
        'delete_account' => ['subject' => 'رمز التحقق لحذف الحساب', 'action' => 'لحذف الحساب نهائياً'],
    ];

    public function __construct(
        public string|int $code,
        public string $purpose,
        public int $minutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::PURPOSES[$this->purpose]['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verification_code',
            with: [
                'code' => (string) $this->code,
                'action' => self::PURPOSES[$this->purpose]['action'],
                'minutes' => $this->minutes,
                'destructive' => $this->purpose === 'delete_account',
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
