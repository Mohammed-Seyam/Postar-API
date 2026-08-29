<?php

namespace App\Mail;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostPublishStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $post;
    public $finalStatus;
    public $thumbUrl;
    public $publishTime;
    public $mediaKind;
    public $mediaLabel;

    /**
     * Create a new message instance.
     */
    public function __construct(Post $post, string $finalStatus)
    {
        $this->post = $post;
        $this->finalStatus = $finalStatus;
        $this->mediaKind = $post->mediaKind();
        $this->mediaLabel = $post->mediaLabel();

        $timezone = $post->user->settings['timezone'] ?? 'Africa/Cairo';
        $this->publishTime = $post->updated_at 
            ? $post->updated_at->timezone($timezone)->format('Y-m-d H:i') 
            : now()->timezone($timezone)->format('Y-m-d H:i');

        try {
            $this->thumbUrl = $post->playableThumbnailUrl();
        } catch (\Exception $e) {
            $this->thumbUrl = null;
        }
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $noun = $this->mediaLabel['noun'];
        $possessive = $this->mediaLabel['possessive'];

        $subject = $this->finalStatus === 'published'
            ? "نجاح نشر {$noun} {$possessive} بك على بوستار"
            : "فشل نشر {$noun} {$possessive} بك على بوستار";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.post_publish_status',
            with: [
                'post' => $this->post,
                'status' => $this->finalStatus,
                'mediaKind' => $this->mediaKind,
                'mediaLabel' => $this->mediaLabel,
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
