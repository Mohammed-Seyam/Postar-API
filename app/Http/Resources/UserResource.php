<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->plan;
        $subscription = $this->subscription();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'language' => $this->language,
            'settings' => $this->settings,
            'phone' => $this->phone,
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'display_name' => $plan->display_name,
            ] : null,
            'cancel_at_period_end' => $subscription ? (bool) $subscription->cancel_at_period_end : false,
            'cancel_at' => $subscription && $subscription->cancel_at ? $subscription->cancel_at->toIso8601String() : null,
            'created_at' => $this->created_at,
        ];
    }
}
