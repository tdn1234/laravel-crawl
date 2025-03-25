<?php
namespace App\Services\LinkedIn\Processors;

use App\Services\LinkedIn\Extractors\CompanyExtractor;
use App\Services\LinkedIn\Processors\JobProcessor;
use App\Services\LinkedIn\Processors\StaffProcessor;
use App\Services\LinkedIn\Repositories\CompanyRepository;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\Log;

class CompanyProcessor
{
    protected $companyExtractor;
    protected $companyRepository;
    protected $jobProcessor;
    protected $staffProcessor;
    
    public function __construct(
        CompanyExtractor $companyExtractor,
        CompanyRepository $companyRepository,
        JobProcessor $jobProcessor,
        StaffProcessor $staffProcessor
    ) {
        $this->companyExtractor = $companyExtractor;
        $this->companyRepository = $companyRepository;
        $this->jobProcessor = $jobProcessor;
        $this->staffProcessor = $staffProcessor;
    }
    
    /**
     * Process company results from the current page
     * 
     * @param RemoteWebDriver $driver
     * @param WebDriverWait $wait
     * @return int Number of companies processed
     */
    public function processCompanyResults($driver, $wait)
    {
        // Using XPath with stable attributes instead of class names
        $companyElements = $driver->findElements(
            WebDriverBy::xpath("//li[.//a[contains(@href, '/company/')]]")
        );
        
        // Fallback using the list structure within cards
        if (count($companyElements) == 0) {
            $companyElements = $driver->findElements(
                WebDriverBy::xpath("//div[contains(@class, 'artdeco-card')]//ul/li")
            );
        }
        
        $count = 0;
        
        foreach ($companyElements as $element) {
            try {
                // Skip the feedback card
                $helpfulElements = $element->findElements(
                    WebDriverBy::xpath('.//*[contains(text(), "Are these results helpful?")]')
                );
                if (count($helpfulElements) > 0) {
                    continue;
                }
                
                // Extract company URL from search results
                $companyUrl = $this->extractCompanyUrl($element);
                if (empty($companyUrl)) {
                    continue;
                }
                
                // Log the URL we're about to visit
                Log::info("Opening company URL: " . $companyUrl);
                
                // Get job link from search results
                $jobUrl = $this->extractJobUrlFromSearchResult($element);
                
                // Open in new tab using JavaScript
                $driver->executeScript("window.open(arguments[0], '_blank');", [$companyUrl]);
                
                // Wait for the new tab to open
                $driver->wait(10)->until(
                    function () use ($driver) {
                        return count($driver->getWindowHandles()) > 1;
                    }
                );
                
                // Switch to the new tab
                $tabs = $driver->getWindowHandles();
                $driver->switchTo()->window(end($tabs));
                
                // Wait for the company page to load
                $wait->until(
                    \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::xpath("//div[contains(@class, 'org-top-card')]")
                    )
                );
                
                // Extract company data from the details page
                $companyData = $this->companyExtractor->extractCompanyData($driver, $wait, $jobUrl);
                
                // If the extraction was successful
                if (!empty($companyData['name'])) {
                    // Save company to database
                    $company = $this->companyRepository->saveCompany($companyData);
                    
                    // Process jobs if there are any jobs listed
                    if ($companyData['job_count'] > 0) {
                        $this->jobProcessor->processJobsForCompany($driver, $wait, $company, $companyData['jobs_url']);
                    }
                    
                    // Process company staff
                    $this->staffProcessor->processStaffForCompany($driver, $wait, $company);
                    
                    $count++;
                }
                
                // Close the company tab and switch back to search results
                $driver->close();
                $driver->switchTo()->window($tabs[0]);
                
                // Add a random delay between processing companies
                sleep(rand(3, 7));
            } catch (\Exception $e) {
                Log::warning("Error processing company: " . $e->getMessage());
                
                // Try to close any extra tabs and get back to search results
                $tabs = $driver->getWindowHandles();
                if (count($tabs) > 1) {
                    $driver->switchTo()->window(end($tabs));
                    $driver->close();
                    $driver->switchTo()->window($tabs[0]);
                }
                
                continue;
            }
        }
        
        return $count;
    }
    
    /**
     * Extract company URL from company element
     * 
     * @param RemoteWebElement $element
     * @return string|null
     */
    private function extractCompanyUrl($element)
    {
        $companyLinkElements = $element->findElements(
            WebDriverBy::xpath(".//a[contains(@href, '/company/') and not(contains(@href, '/setup/new'))]")
        );
        
        if (count($companyLinkElements) == 0) {
            return null;
        }
        
        $companyLinkElement = $companyLinkElements[0];
        $companyUrl = $companyLinkElement->getAttribute('href');
        
        // Additional validation of the URL
        if (!preg_match('/\/company\/[a-zA-Z0-9\-]+\/?$/', $companyUrl)) {
            // This doesn't look like a normal company page URL
            Log::warning("Skipping suspicious company URL: " . $companyUrl);
            return null;
        }
        
        return $companyUrl;
    }
    
    /**
     * Extract job URL from company element in search results
     * 
     * @param RemoteWebElement $element
     * @return string|null
     */
    private function extractJobUrlFromSearchResult($element)
    {
        $jobLinkElements = $element->findElements(
            WebDriverBy::xpath(".//a[contains(@class, 'reusable-search-simple-insight__wrapping-link')]")
        );
        
        if (count($jobLinkElements) == 0) {
            return null;
        }
        
        return $jobLinkElements[0]->getAttribute('href');
    }
}
