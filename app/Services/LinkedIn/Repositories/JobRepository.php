<?php
namespace App\Services\LinkedIn\Repositories;

use App\Models\JobDetail;
use App\Models\JobType;
use Illuminate\Support\Facades\Log;

class JobRepository
{
    /**
     * Save job data to database
     * 
     * @param Company $company
     * @param array $jobData
     * @return JobDetail
     */
    public function saveJob($company, $jobData)
    {
        try {
            // Find or create job type based on employment_type if available, otherwise use job_type
            $jobTypeText = !empty($jobData['employment_type']) ? $jobData['employment_type'] : $jobData['job_type'];
            $jobType = JobType::firstOrCreate(['job_type' => $jobTypeText]);
            
            // Prepare data for saving
            $saveData = [
                'location' => $jobData['location'],
                'time_type' => $jobData['time_type'],
                'number_of_applicants' => $jobData['applicant_count'] ?? 0,
                'job_description_link' => $jobData['url'],
            ];
            
            // Add open_date if available
            if (!empty($jobData['posted_date'])) {
                $saveData['open_date'] = $jobData['posted_date'];
            } else {
                $saveData['open_date'] = now();
            }
            
            // Save the job
            $job = JobDetail::updateOrCreate(
                [
                    'job_name' => $jobData['title'],
                    'job_type_id' => $jobType->id
                ],
                $saveData
            );
            
            if ($job->wasRecentlyCreated) {
                Log::info("Created new job: " . $jobData['title'] . " for company: " . $company->company_name);
            } else {
                Log::info("Updated existing job: " . $jobData['title'] . " for company: " . $company->company_name);
            }
            
            return $job;
        } catch (\Exception $e) {
            Log::warning("Error saving job data: " . $e->getMessage());
            return null;
        }
    }
}

