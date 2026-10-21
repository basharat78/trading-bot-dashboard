<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
#[Fillable(['engine_id','beat_at','status','version','open_positions'])]
class Heartbeat extends Model
{
    protected function casts(): array
    {
        return ['beat_at' => 'datetime'];
    }
}
