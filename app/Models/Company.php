<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Company extends Model
{
    use HasFactory;

    protected $table = 'company';
    
    protected $fillable = [
        'company_name',
        'industry_id',
        'employee_number',
        'open_jobs',
    ];

    /**
     * Get the industry that the company belongs to.
     */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /**
     * Get the staff members for the company.
     */
    public function staffMembers(): HasMany
    {
        return $this->hasMany(CompanyStaff::class);
    }
    
    /**
     * Get the job details associated with the company.
     */
    public function jobDetails(): HasMany
    {
        return $this->hasMany(JobDetail::class);
    }
}