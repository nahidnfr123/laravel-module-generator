<?php

namespace App\Models;

use App\Traits\HasSlugModelBinding;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use HasSlugModelBinding, SoftDeletes;
}
