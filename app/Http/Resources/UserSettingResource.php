<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Itp\Core\Models\UserSetting;

/**
 * A user's settings row, as the phone stores it locally. Times of day are `HH:MM:SS`.
 *
 * @mixin UserSetting
 */
class UserSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'living_with_type' => $this->living_with_type,
            'living_with_id' => $this->living_with_id,
            'morning_at' => $this->morning_at,
            'why_at' => $this->why_at,
            'evening_at' => $this->evening_at,
            'morning_reminder' => $this->morning_reminder,
            'why_reminder' => $this->why_reminder,
            'evening_reminder' => $this->evening_reminder,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
