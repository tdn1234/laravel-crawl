<?php
namespace App\Services\LinkedIn\Processors;

use App\Services\LinkedIn\Extractors\JobExtractor;
use App\Services\LinkedIn\Repositories\JobRepository;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\Log;

class JobProcessor
{
    protected $jobExtractor;
    protected $jobRepository;
    
    public function __construct(
        JobExtractor $jobExtractor,
        JobRepository $jobRepository
    ) {
        $this->jobExtractor = $jobExtractor;
        $this->jobRepository = $jobRepository;
    }
    
    /**
     * Process jobs for a company
     * 
     * @param RemoteWebDriver $driver
     * @param WebDriverWait $wait
     * @param Company $company
     * @param string|null $jobsUrl
     * @return int Number of jobs processed
     */
    public function processJobsForCompany($driver, $wait, $company, $jobsUrl = null)
    {
        try {
            if (empty($jobsUrl)) {
                // Try to construct a URL using company data
                $jobsUrl = $this->constructJobsUrl($driver);
            }
            
            if (empty($jobsUrl)) {
                Log::warning("No jobs URL available for company: " . $company->company_name);
                return 0;
            }
            
            // Navigate to the jobs page
            Log::info("Navigating to jobs URL: " . $jobsUrl);
            $driver->get($jobsUrl);
            
            // Wait for the jobs page to load
            $this->waitForJobsPageToLoad($driver, $wait);
            
            // Find job listings
            $jobElements = $this->findJobElements($driver);
            
            $jobsProcessed = 0;
            
            foreach ($jobElements as $index => $element) {
                try {
                    // Get job URL
                    $jobUrl = $this->getJobUrl($element);
                    if (empty($jobUrl)) {
                        continue;
                    }
                    
                    Log::info("Processing job " . ($index + 1) . " with URL: " . $jobUrl);
                    
                    // Open in new tab and process
                    $jobData = $this->processIndividualJob($driver, $wait, $jobUrl);
                    
                    // Save the job data
                    if (!empty($jobData['title'])) {
                        $this->jobRepository->saveJob($company, $jobData);
                        $jobsProcessed++;
                    }
                    
                    // Process only a limited number of jobs to avoid long runtimes
                    if ($jobsProcessed >= 10) {
                        Log::info("Reached limit of 10 jobs processed. Stopping job processing.");
                        break;
                    }
                } catch (\Exception $e) {
                    Log::warning("Error processing individual job: " . $e->getMessage());
                    continue;
                }
            }
            
            Log::info("Processed " . $jobsProcessed . " jobs for company: " . $company->company_name);
            return $jobsProcessed;
        } catch (\Exception $e) {
            Log::warning("Error processing jobs for company {$company->company_name}: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Construct jobs URL using company data
     * 
     * @param RemoteWebDriver $driver
     * @return string|null
     */
    private function constructJobsUrl($driver)
    {
        $companyUrl = $driver->getCurrentURL();
        $companyId = null;
        $jobsUrl = null;
        
        // Extract company ID from current URL
        if (preg_match('/\/company\/(\d+)/', $companyUrl, $matches)) {
            $companyId = $matches[1];
            Log::info("Extracted company ID from URL: " . $companyId);
        } else if (preg_match('/\/company\/([^\/]+)/', $companyUrl, $matches)) {
            // If numeric ID not found, use the company name/slug
            $companySlug = $matches[1];
            Log::info("Extracted company slug from URL: " . $companySlug);
            
            // Try to get the company ID from the page source
            $pageSource = $driver->getPageSource();
            if (preg_match('/companyId":"(\d+)"/', $pageSource, $idMatches)) {
                $companyId = $idMatches[1];
                Log::info("Extracted company ID from page source: " . $companyId);
            }
        }
        
        // If we have a company ID, construct a reliable search URL
        if ($companyId) {
            $jobsUrl = "https://www.linkedin.com/jobs/search?geoId=92000000&f_C={$companyId}";
            Log::info("Constructed jobs URL using company ID: " . $jobsUrl);
        } else {
            // Fallback to a simple jobs URL based on the company URL
            $jobsUrl = rtrim($companyUrl, '/') . '/jobs/';
            Log::info("Constructed fallback jobs URL: " . $jobsUrl);
        }
        
        return $jobsUrl;
    }
    
    /**
     * Wait for jobs page to load
     * 
     * @param RemoteWebDriver $driver
     * @param WebDriverWait $wait
     */
    private function waitForJobsPageToLoad($driver, $wait)
    {
        try {
            $wait->until(
                \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('.jobs-search-results-list')
                )
            );
        } catch (\Exception $e) {
            // Try alternative selector
            try {
                $wait->until(
                    \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::cssSelector('.scaffold-layout__list')
                    )
                );
            } catch (\Exception $e2) {
                // One more alternative
                $wait->until(
                    \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::xpath("//div[contains(@class, 'jobs-search')]")
                    )
                );
            }
        }
        
        // Allow time for the jobs list to fully load
        sleep(3);
    }
    
    /**
     * Find job elements on page
     * 
     * @param RemoteWebDriver $driver
     * @return array Elements containing job listings
     */
    private function findJobElements($driver)
    {
        $jobElements = [];
        
        // Try multiple selectors to find job listings
        $selectors = [
            "//li[contains(@class, 'scaffold-layout__list-item')][.//div[contains(@class, 'job-card-container')]]",
            "//div[contains(@class, 'job-card-container')]",
            "//li[.//a[contains(@href, '/jobs/view/')]]",
            "//a[contains(@href, '/jobs/view/')]"
        ];
        
        foreach ($selectors as $selector) {
            $jobElements = $driver->findElements(WebDriverBy::xpath($selector));
            Log::info("Tried selector: {$selector} - Found: " . count($jobElements) . " elements");
            if (count($jobElements) > 0) {
                break;
            }
        }
        
        // If still no elements found, log HTML for debugging
        if (count($jobElements) == 0) {
            Log::warning("No job elements found with any selector. Current URL: " . $driver->getCurrentURL());
            // Log part of the page source for debugging
            $source = $driver->getPageSource();
            Log::debug("Page source excerpt: " . substr($source, 0, 2000) . "...");
        }
        
        return $jobElements;
    }
    
    /**
     * Get job URL from element
     * 
     * @param RemoteWebElement $element
     * @return string|null
     */
    private function getJobUrl($element)
    {
        $jobUrl = null;
        
        try {
            // Try to get URL from element if it's an anchor
            if ($element->getTagName() == 'a') {
                $jobUrl = $element->getAttribute('href');
            } else {
                // Otherwise find the anchor inside the element
                $linkElement = $element->findElement(
                    WebDriverBy::xpath(".//a[contains(@href, '/jobs/view/')]")
                );
                $jobUrl = $linkElement->getAttribute('href');
            }
        } catch (\Exception $e) {
            // If we can't get the URL, skip this job
            Log::warning("Could not get job URL: " . $e->getMessage());
            return null;
        }
        
        return $jobUrl;
    }
    
    /**
     * Process an individual job
     * 
     * @param RemoteWebDriver $driver
     * @param WebDriverWait $wait
     * @param string $jobUrl
     * @return array Job data
     */
    private function processIndividualJob($driver, $wait, $jobUrl)
    {
        $jobData = [];
        $originalWindowHandle = $driver->getWindowHandle();
        
        try {
            // Open in new tab
            $driver->executeScript("window.open(arguments[0], '_blank');", [$jobUrl]);
            
            // Switch to the new tab
            $tabs = $driver->getWindowHandles();
            $driver->switchTo()->window(end($tabs));
            
            // Wait for job details to load
            try {
                $wait->until(
                    \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::cssSelector('.job-view-layout, .jobs-details')
                    )
                );
            } catch (\Exception $e) {
                // Try an alternative selector
                $wait->until(
                    \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::xpath("//div[contains(@class, 'jobs-')]")
                    )
                );
            }
            
            sleep(2); // Give the page a moment to fully load
            
            // Extract data from the job details page
            $jobData = $this->jobExtractor->extractJobData($driver);
            
            // Close the job details tab and switch back to the jobs list
            $driver->close();
            $driver->switchTo()->window($originalWindowHandle);
            
            // Add a small delay between processing jobs
            sleep(rand(1, 3));
        } catch (\Exception $e) {
            Log::warning("Error processing job: " . $e->getMessage());
            
            // Try to close any extra tabs and get back to the jobs list
            $tabs = $driver->getWindowHandles();
            if (count($tabs) > 1) {
                $driver->switchTo()->window(end($tabs));
                $driver->close();
                $driver->switchTo()->window($originalWindowHandle);
            }
        }
        
        return $jobData;
    }
}
