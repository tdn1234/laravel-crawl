<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyStaff extends Model
{
    use HasFactory;

    protected $table = 'company_staffs';
    
    protected $fillable = [
        'company_id',
        'full_name',
        'contact_link',
    ];

    /**
     * Get the company that the staff member belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}