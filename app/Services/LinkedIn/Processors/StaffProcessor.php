<?php
namespace App\Services\LinkedIn\Processors;

use App\Services\LinkedIn\Extractors\StaffExtractor;
use App\Services\LinkedIn\Repositories\StaffRepository;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\Log;

class StaffProcessor
{
    protected $staffExtractor;
    protected $staffRepository;
    
    public function __construct(
        StaffExtractor $staffExtractor,
        StaffRepository $staffRepository
    ) {
        $this->staffExtractor = $staffExtractor;
        $this->staffRepository = $staffRepository;
    }
    
    /**
     * Process staff for a company
     * 
     * @param RemoteWebDriver $driver
     * @param WebDriverWait $wait
     * @param Company $company
     * @return int Number of staff processed
     */
    public function processStaffForCompany($driver, $wait, $company)
    {
        $staffCount = 0;
        
        try {
            // Look for the "People" tab using text content which is more stable
            $peopleElements = $driver->findElements(
                WebDriverBy::xpath('//a[contains(@href, "/people/") or contains(text(), "People")]')
            );
            
            if (count($peopleElements) > 0) {
                $peopleElements[0]->click();
                
                // Wait for the people page to load - look for elements that contain profile information
                $wait->until(
                    \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfAllElementsLocatedBy(
                        WebDriverBy::xpath('//div[contains(@class, "profile-card") or contains(@class, "entity-lockup")]')
                    )
                );
                
                // Get staff members - use more general locator
                $staffElements = $driver->findElements(
                    WebDriverBy::xpath('//div[contains(@class, "profile-card") or contains(@class, "entity-lockup")]')
                );
                
                foreach ($staffElements as $element) {
                    try {
                        $staffData = $this->staffExtractor->extractStaffData($element);
                        
                        if (!empty($staffData['name']) && !empty($staffData['link'])) {
                            $this->staffRepository->saveStaff($company, $staffData);
                            $staffCount++;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error processing staff for company {$company->company_name}: " . $e->getMessage());
        }
        
        return $staffCount;
    }
}