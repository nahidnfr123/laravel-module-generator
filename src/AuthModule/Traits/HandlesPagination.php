<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

trait HandlesPagination
{
    /**
     * Paginate a query if per_page is provided, otherwise return all results.
     */
    public function paginateIfRequested(Model|Builder $query, $simple = true): mixed
    {
        $perPage = Request::integer('per_page');

        // If per_page exists and is a valid positive integer -> paginate
        if ($perPage && $perPage > 0) {
            if ($simple) {
                return $query->simplePaginate($perPage);
            }

            return $query->paginate($perPage);
        }

        // Otherwise -> return all
        return $query->get();
    }

    public function formatePaginatedData($data, $simple = true): mixed
    {
        $perPage = Request::integer('per_page');

        // If per_page exists and is a valid positive integer -> paginate
        if ($perPage && $perPage > 0) {
            if ($simple) {
                return [
                    'data' => $data->getCollection(),
                    'meta' => [
                        'current_page' => $data->currentPage(),
                        'current_page_url' => $data->url($data->currentPage()), // Current page URL
                        'first_page_url' => $data->url(1), // First page URL
                        'next_page_url' => $data->nextPageUrl(), // Next page URL (null if last page)
                        'prev_page_url' => $data->previousPageUrl(), // Previous page URL (null if first page)
                        'path' => $data->path(), // Base URL path for the pagination
                        'per_page' => $data->perPage(), // Items per page
                        'from' => $data->firstItem(), // First item index on current page
                        'to' => $data->lastItem(), // Last item index on current page
                    ],
                ];
            }

            return [
                'data' => $data->getCollection(),
                'meta' => [
                    'total' => $data->total(), // Total number of items
                    'per_page' => $data->perPage(), // Items per page
                    'current_page' => $data->currentPage(), // Current page number
                    'last_page' => $data->lastPage(), // Total number of pages
                    'from' => $data->firstItem(), // First item index on current page
                    'to' => $data->lastItem(), // Last item index on current page
                    'first_page_url' => $data->url(1), // First page URL
                    'last_page_url' => $data->url($data->lastPage()), // Last page URL
                    'current_page_url' => $data->url($data->currentPage()), // Current page URL
                    'next_page_url' => $data->nextPageUrl(), // Next page URL (null if last page)
                    'prev_page_url' => $data->previousPageUrl(), // Previous page URL (null if first page)
                    'path' => $data->path(), // Base URL path for the pagination
                ],
            ];
        }

        return $data;
    }
}
