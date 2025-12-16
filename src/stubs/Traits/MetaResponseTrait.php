<?php

namespace App\Traits;

use Illuminate\Pagination\LengthAwarePaginator;

trait MetaResponseTrait
{
    /**
     * Generate meta information for the resource collection.
     *
     * @return array<int|string, mixed>
     */
    protected function generateMeta(): array
    {
        if ($this->resource instanceof LengthAwarePaginator) {
            return [
                'first_page_url' => $this->url(1),
                'prev_page_url' => $this->previousPageUrl(),
                'next_page_url' => $this->nextPageUrl(),
                'last_page_url' => $this->url($this->lastPage()),
                'path' => $this->path(),
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
                'from' => $this->firstItem(),
                'to' => $this->lastItem(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
            ];
        }

        return [];
    }
}
