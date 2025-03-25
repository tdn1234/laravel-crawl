<?php
namespace App\Services\LinkedIn\Repositories;

use App\Models\CompanyStaff;
use Illuminate\Support\Facades\Log;

class StaffRepository
{
    /**
     * Save staff data to database
     * 
     * @param Company $company
     * @param array $staffData
     * @return CompanyStaff
     */
    public function saveStaff($company, $staffData)
    {
        try {
            $staff = CompanyStaff::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'full_name' => $staffData['name']
                ],
                [
                    'contact_link' => $staffData['link']
                ]
            );
            
            if ($staff->wasRecentlyCreated) {
                Log::info("Created new staff member: " . $staffData['name'] . " for company: " . $company->company_name);
            } else {
                Log::info("Updated existing staff member: " . $staffData['name'] . " for company: " . $company->company_name);
            }
            
            return $staff;
        } catch (\Exception $e) {
            Log::warning("Error saving staff data: " . $e->getMessage());
            return null;
        }
    }
}

