<?php

namespace App\Http\Resources;

use App\Traits\MetaResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class RoleCollection extends ResourceCollection
{
    use MetaResponseTrait;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->transform(function ($user) {
                return RoleResource::make($user);
            }),
            'meta' => $this->generateMeta(),
        ];
    }
}
