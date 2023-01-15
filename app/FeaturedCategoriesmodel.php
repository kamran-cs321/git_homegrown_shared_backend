<?php

namespace App;

use App\Utils\Traits\CommonRelations;
use App\Utils\Traits\CommonScopes;
use App\Utils\Traits\Search;
use Illuminate\Database\Eloquent\Model;

class FeaturedCategoriesmodel extends Model
{
    use CommonRelations, CommonScopes, Search;
    public $table = "featured_categories";
}
