<?php

namespace App\Http\Resources\Api\V1;

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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'estado' => $this->estado,
            // (BUG-16) Antes se devolvía sólo el primer rol (->first()). Ahora la lista completa.
            'roles' => $this->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
