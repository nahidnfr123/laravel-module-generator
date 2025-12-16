<?php

namespace App\Traits;

use App\HasSlug\SlugOptions;

trait HasSlugModelBinding
{
    use HasSlug;

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->replaceRouteKey(true, true);
    }
}
