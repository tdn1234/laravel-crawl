<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Industry extends Model
{
    use HasFactory;

    protected $table = 'industry';
    
    protected $fillable = [
        'industry_name',
    ];

    /**
     * Get the companies for this industry.
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
