<?php
namespace App\Services\LinkedIn\Repositories;

use App\Models\Company;
use App\Models\Industry;
use Illuminate\Support\Facades\Log;

class CompanyRepository
{
    /**
     * Save company data to database
     * 
     * @param array $companyData
     * @return Company
     */
    public function saveCompany($companyData)
    {
        // First, get or create the industry
        try {
            $industry = Industry::firstOrCreate(['industry_name' => $companyData['industry']]);
            
            if ($industry->wasRecentlyCreated) {
                Log::info("Created new industry: " . $companyData['industry']);
            } else {
                Log::info("Using existing industry: " . $companyData['industry'] . " (ID: " . $industry->id . ")");
            }
        } catch (\Exception $e) {
            Log::error("Error handling industry: " . $e->getMessage());
            // Create a fallback industry if needed
            $industry = Industry::firstOrCreate(['industry_name' => 'Unknown']);
        }
        
        // Now create or update the company using composite key
        try {
            $company = Company::updateOrCreate(
                [
                    'company_name' => $companyData['name'],
                    'industry_id' => $industry->id
                ],
                [
                    'employee_number' => $companyData['employee_count'],
                    'open_jobs' => $companyData['job_count']
                ]
            );
            
            if ($company->wasRecentlyCreated) {
                Log::info("Created new company: " . $companyData['name'] . " in industry: " . $industry->industry_name);
            } else {
                Log::info("Updated existing company: " . $companyData['name'] . " in industry: " . $industry->industry_name);
            }
            
            return $company;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Database error when saving company: " . $e->getMessage());
            
            // Try to recover if it's a duplicate entry error
            if ($e->errorInfo[1] == 1062) { // MySQL duplicate entry error code
                $company = Company::where('company_name', $companyData['name'])
                                ->where('industry_id', $industry->id)
                                ->first();
                                
                if ($company) {
                    $company->employee_number = $companyData['employee_count'];
                    $company->open_jobs = $companyData['job_count'];
                    $company->save();
                    Log::info("Manually updated existing company after error: " . $companyData['name']);
                    return $company;
                }
            }
            
            // If recovery failed, throw the error
            throw $e;
        }
    }
}
