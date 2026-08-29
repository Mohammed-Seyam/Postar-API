<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use App\Models\WaitlistSignup;
use App\Mail\WaitlistConfirmationMail;

class SendWaitlistConfirmationJob implements ShouldQueue
{
    use Queueable;

    public $signup;

    /**
     * Create a new job instance.
     */
    public function __construct(WaitlistSignup $signup)
    {
        $this->signup = $signup;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mail::to($this->signup->email)->send(new WaitlistConfirmationMail($this->signup));
    }
}
