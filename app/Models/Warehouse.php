<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    /** @use HasFactory<\Database\Factories\WarehouseFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        "name",
        "location",
        "capacity"
    ];

    public function itemUnits()
    {
        return $this->hasMany(ItemUnit::class);
    }
}
