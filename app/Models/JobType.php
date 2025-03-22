<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobType extends Model
{
    use HasFactory;

    protected $table = 'jobs_types';
    
    protected $fillable = [
        'job_type',
    ];

    /**
     * Get the job details for this job type.
     */
    public function jobDetails(): HasMany
    {
        return $this->hasMany(JobDetail::class, 'job_type_id');
    }
}