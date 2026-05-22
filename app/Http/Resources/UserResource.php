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
            'created_at' => $this->created_at,
        ];
    }
}
