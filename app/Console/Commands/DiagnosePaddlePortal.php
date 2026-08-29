<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DiagnosePaddlePortal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paddle:diagnose-portal {email : Email of the user whose customer portal is failing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose why the Paddle customer portal link fails for a user (config, customer id, portal session)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sandbox = (bool) config('paddle.sandbox');
        $apiKey = (string) config('paddle.api_key');
        $baseUrl = $sandbox ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';

        $this->line('');
        $this->info('1. Configuration');
        $this->line('   environment : '.($sandbox ? 'SANDBOX' : 'PRODUCTION'));
        $this->line('   api base    : '.$baseUrl);
        $this->line('   api key     : '.($apiKey !== ''
            ? substr($apiKey, 0, 12).'... ('.strlen($apiKey).' chars)'
            : '<MISSING>'));

        if ($apiKey === '') {
            $this->error('   PADDLE_API_KEY is empty. The portal cannot work without it.');

            return self::FAILURE;
        }

        $expectedPrefix = $sandbox ? 'pdl_sdbx_' : 'pdl_live_';
        if (! str_starts_with($apiKey, $expectedPrefix)) {
            $this->warn('   API key does not start with "'.$expectedPrefix.'" — key/environment mismatch is likely.');
        }

        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $this->line('');
        $this->info('2. API credentials');
        $ping = Http::withHeaders($headers)->get("{$baseUrl}/customers", ['per_page' => 1]);
        if ($ping->failed()) {
            $this->error('   Paddle rejected the API key: HTTP '.$ping->status());
            $this->line('   '.$ping->body());

            return self::FAILURE;
        }
        $this->line('   Key accepted by '.$baseUrl);

        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error('No user found with email '.$email);

            return self::FAILURE;
        }

        $this->line('');
        $this->info('3. Local records');
        $stored = $user->customer?->paddle_id;
        $this->line('   user id            : '.$user->id);
        $this->line('   plan_id            : '.$user->plan_id);
        $this->line('   customers.paddle_id: '.($stored ?: '<none>'));

        if (! $stored) {
            $this->warn('   No customer row — the portal button has nothing to open.');
        } elseif (str_starts_with($stored, 'ctm_mock_')) {
            $this->warn('   Placeholder id created by billingInfo() self-healing; Paddle will not recognise it.');
        }

        $subs = Subscription::where('user_id', $user->id)->get();
        foreach ($subs as $sub) {
            $this->line('   subscription       : '.($sub->paddle_subscription_id ?: $sub->paddle_id).' ['.$sub->status.']');
        }
        if ($subs->isEmpty()) {
            $this->line('   subscription       : <none>');
        }

        $this->line('');
        $this->info('4. Paddle customer lookup by email');
        $lookup = Http::withHeaders($headers)->get("{$baseUrl}/customers", [
            'email' => $user->email,
            'status' => 'active',
        ]);

        if ($lookup->failed()) {
            $this->error('   Lookup failed: HTTP '.$lookup->status());
            $this->line('   '.$lookup->body());

            return self::FAILURE;
        }

        $realId = $lookup->json('data.0.id');
        if (! $realId) {
            $this->error('   No Paddle customer exists for '.$user->email.' in '.($sandbox ? 'sandbox' : 'production').'.');
            $this->line('   Either the purchase was made in the other environment, or under a different email.');

            return self::FAILURE;
        }

        $this->line('   Paddle customer id : '.$realId);
        if ($stored !== $realId) {
            $this->warn('   Stored id differs from Paddle — portalUrl() will repair this on the next call.');
        }

        $this->line('');
        $this->info('5. Portal session');
        $session = Http::withHeaders($headers)
            ->withBody('{}', 'application/json')
            ->post("{$baseUrl}/customers/{$realId}/portal-sessions");

        if ($session->failed()) {
            $this->error('   Portal session failed: HTTP '.$session->status());
            $this->line('   '.$session->body());

            return self::FAILURE;
        }

        $urls = $session->json('data.urls') ?? [];
        $overview = $urls['general']['overview'] ?? $urls['overview'] ?? null;

        if (! $overview) {
            $this->error('   Paddle returned no overview URL.');
            $this->line('   '.$session->body());

            return self::FAILURE;
        }

        $this->line('   overview URL       : '.$overview);
        $this->line('');
        $this->info('Portal is reachable for this user.');

        return self::SUCCESS;
    }
}
