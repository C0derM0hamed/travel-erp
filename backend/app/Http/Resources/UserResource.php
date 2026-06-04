<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'roleLabel' => $this->role_label ?: $this->role,
            'avatar' => $this->avatar,
            'must_change_password' => (bool) ($this->must_change_password ?? false),
            'is_active' => (bool) ($this->is_active ?? true),
        ];
    }
}
