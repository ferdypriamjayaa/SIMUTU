<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Indicator;

class IndicatorCategory extends Model
{
    public function indicators()
    {
        return $this->hasMany(Indicator::class);
    }
}
