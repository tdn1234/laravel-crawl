<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobDetail extends Model
{
    use HasFactory;

    protected $table = 'job_detail';
    
    protected $fillable = [
        'job_name',
        'location',
        'open_date',
        'job_type_id',
        'time_type',
        'number_of_applicants',
        'job_description_link',
    ];

    protected $casts = [
        'open_date' => 'date',
    ];

    /**
     * Get the job type that owns the job detail.
     */
    public function jobType(): BelongsTo
    {
        return $this->belongsTo(JobType::class, 'job_type_id');
    }
    
    /**
     * Get the company this job belongs to.
     */
    public function company(): BelongsTo
    {        
        return $this->belongsTo(Company::class);
    }
}
