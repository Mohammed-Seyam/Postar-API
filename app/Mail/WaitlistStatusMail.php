<?php

namespace App\Mail;

use App\Models\WaitlistSignup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public $signup;

    /**
     * Create a new message instance.
     */
    public function __construct(WaitlistSignup $signup)
    {
        $this->signup = $signup;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'رابط حالة قائمة الانتظار الخاص بك / Your Waitlist Status Link',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:4200'));
        
        return new Content(
            view: 'emails.waitlist-status',
            with: [
                'statusUrl' => rtrim($frontendUrl, '/') . '/waitlist/status?token=' . $this->signup->status_token,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
