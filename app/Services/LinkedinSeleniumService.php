<?php

namespace App\Services;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;
use Illuminate\Support\Facades\Log;
use App\Models\Company;
use App\Models\CompanyStaff;
use App\Models\Industry;
use App\Models\JobDetail;
use App\Models\JobType;

class LinkedinSeleniumService
{
    protected $driver;
    protected $wait;
    protected $loggedIn = false;
    protected $searchUrl;

    public function __construct()
    {
        $this->searchUrl = "https://www.linkedin.com/search/results/companies/?companyHqGeo=%5B%22104514075%22%2C%22106693272%22%2C%22101282230%22%5D&companySize=%5B%22D%22%2C%22E%22%2C%22F%22%5D&industryCompanyVertical=%5B%2227%22%2C%2296%22%2C%221285%22%2C%226%22%2C%2225%22%2C%2255%22%2C%2254%22%2C%221%22%2C%22117%22%2C%22135%22%2C%22146%22%2C%22147%22%2C%2223%22%2C%2226%22%2C%2253%22%2C%227%22%2C%22709%22%2C%22901%22%5D&keywords=a&origin=FACETED_SEARCH&sid=u!U";
    }

    /**
     * Initialize the WebDriver
     */
    public function init()
    {
        $options = new ChromeOptions();
        
        // Optional: Add Chrome options for headless mode if needed
        // $options->addArguments(['--headless', '--disable-gpu']);
        
        // For debugging, it's often easier to see the browser
        $options->addArguments([
            '--start-maximized',
            '--disable-notifications',
            '--disable-infobars'
        ]);

        // Use your existing Chrome profile to maintain authentication
        // Replace with your profile path
        $options->addArguments([
            'user-data-dir=/home/tdn/.config/google-chrome/Default',
            'profile-directory=Default'
        ]);
        
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
            
        // Use 'host.docker.internal' to reference host machine from Docker
        $this->driver = RemoteWebDriver::create(
            'http://host.docker.internal:4444/wd/hub', 
            $capabilities
        );
        
        // Create a wait object with 20 second timeout
        $this->wait = new WebDriverWait($this->driver, 20);

        $this->loggedIn = true;
        
        return $this;
    }
    
    /**
     * Crawl company data
     */
    public function crawlCompanies()
    {
        if (!$this->loggedIn) {
            throw new \Exception('You must be logged in to crawl LinkedIn data');
        }
        
        try {
            // Navigate to company search results
            $this->driver->get($this->searchUrl);
            
            // Wait for search results to load - using a more stable approach
            $this->wait->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::xpath("//div[contains(@class, 'search-results')]")
                )
            );
            
            sleep(3); // Add a small delay to ensure content is fully loaded
            
            // Process company results from first page
            $companyCount = $this->processCompanyResults();
            
            // Process additional pages if needed
            $currentPage = 1;
            $maxPages = 3; // Set max pages to crawl to avoid excessive requests
            
            while ($currentPage < $maxPages) {
                // Using more reliable XPath with text content for pagination
                $nextPageElements = $this->driver->findElements(
                    WebDriverBy::xpath("//button[contains(@aria-label, 'Next') or contains(., 'Next')]")
                );
                
                if (count($nextPageElements) > 0 && $nextPageElements[0]->isEnabled()) {
                    $nextPageElements[0]->click();
                    
                    // Wait for the next page to load
                    sleep(3); // Basic delay
                    
                    // Wait for page to load using a more stable approach
                    $this->wait->until(
                        WebDriverExpectedCondition::presenceOfElementLocated(
                            WebDriverBy::xpath("//div[contains(@class, 'search-results')]")
                        )
                    );
                    
                    // Process results from this page
                    $companyCount += $this->processCompanyResults();
                    $currentPage++;
                } else {
                    break; // No more pages
                }
            }
            
            Log::info("Completed LinkedIn crawl. Processed $companyCount companies.");
            return $companyCount;
        } catch (\Exception $e) {
            Log::error('Error crawling LinkedIn companies: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Process company results from the current page
     */
    protected function processCompanyResults()
    {
        // Using XPath with stable attributes instead of class names
        $companyElements = $this->driver->findElements(
            WebDriverBy::xpath("//li[.//a[contains(@href, '/company/')]]")
        );
        
        // Fallback using the list structure within cards
        if (count($companyElements) == 0) {
            $companyElements = $this->driver->findElements(
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
                
                // Extract company URL - focus on getting a valid URL rather than the name at this stage
                $companyLinkElements = $element->findElements(
                    WebDriverBy::xpath(".//a[contains(@href, '/company/') and not(contains(@href, '/setup/new'))]")
                );
                
                if (count($companyLinkElements) == 0) {
                    continue; // Skip if no valid company link found
                }
                
                $companyLinkElement = $companyLinkElements[0];
                $companyUrl = $companyLinkElement->getAttribute('href');
                
                // Additional validation of the URL
                if (!preg_match('/\/company\/[a-zA-Z0-9\-]+\/?$/', $companyUrl)) {
                    // This doesn't look like a normal company page URL
                    Log::warning("Skipping suspicious company URL: " . $companyUrl);
                    continue;
                }
                
                // Log the URL we're about to visit
                Log::info("Opening company URL: " . $companyUrl);
                
                // Open in new tab using JavaScript
                $this->driver->executeScript("window.open(arguments[0], '_blank');", [$companyUrl]);
                
                // Wait for the new tab to open
                $this->driver->wait(10)->until(
                    function () {
                        return count($this->driver->getWindowHandles()) > 1;
                    }
                );
                
                // Switch to the new tab
                $tabs = $this->driver->getWindowHandles();
                $this->driver->switchTo()->window(end($tabs));
                
                // Wait for the company page to load
                $this->wait->until(
                    WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::xpath("//div[contains(@class, 'org-top-card')]")
                    )
                );
                
                // Extract company data from the details page
                $companyData = $this->extractCompanyData();
                
                // If the extraction was successful
                if (!empty($companyData['name'])) {
                    // Save company to database
                    $company = $this->saveCompanyData($companyData);
                    
                    // Process jobs if there are any jobs listed
                    if ($companyData['job_count'] > 0) {
                        $jobTabElements = $this->driver->findElements(
                            WebDriverBy::xpath('//a[contains(@href, "/jobs") or contains(text(), "jobs")]')
                        );
                        
                        if (count($jobTabElements) > 0) {
                            Log::info("Navigating to jobs tab for " . $companyData['name']);
                            $jobTabElements[0]->click();
                            $this->processJobs($company);
                        }
                    }
                    
                    // Process company staff
                    $this->processCompanyStaff($company);
                    
                    $count++;
                }
                
                // Close the company tab and switch back to search results
                $this->driver->close();
                $this->driver->switchTo()->window($tabs[0]);
                
                // Add a random delay between processing companies
                sleep(rand(3, 7));
            } catch (\Exception $e) {
                Log::warning("Error processing company: " . $e->getMessage());
                
                // Try to close any extra tabs and get back to search results
                $tabs = $this->driver->getWindowHandles();
                if (count($tabs) > 1) {
                    $this->driver->switchTo()->window(end($tabs));
                    $this->driver->close();
                    $this->driver->switchTo()->window($tabs[0]);
                }
                
                continue;
            }
        }
        
        return $count;
    }
    
    /**
     * Extract company data from the details page
     * 
     * @return array Company data including name, industry, employee count, etc.
     */
    protected function extractCompanyData()
    {
        $companyData = [
            'name' => '',
            'industry' => 'Unknown',
            'employee_count' => null,
            'job_count' => 0,
            'follower_count' => null
        ];
        
        try {
            // Company name - based on the provided HTML structure
            $companyNameElements = $this->driver->findElements(
                WebDriverBy::xpath("//h1[contains(@class, 'org-top-card-summary__title')]")
            );
            
            if (count($companyNameElements) > 0) {
                $companyData['name'] = trim($companyNameElements[0]->getText());
                Log::info("Extracted company name from details page: " . $companyData['name']);
            } else {
                // Fallback to URL if we can't find the name
                $companyUrl = $this->driver->getCurrentURL();
                if (preg_match('/\/company\/([^\/]+)/', $companyUrl, $matches)) {
                    $companyData['name'] = str_replace('-', ' ', $matches[1]);
                    $companyData['name'] = ucwords($companyData['name']);
                    Log::info("Using company name from URL: " . $companyData['name']);
                }
            }
            
            // Industry - based on the provided HTML structure
            $industryElements = $this->driver->findElements(
                WebDriverBy::xpath("//div[contains(@class, 'org-top-card-summary-info-list__info-item')][1]")
            );
            
            if (count($industryElements) > 0) {
                $companyData['industry'] = trim($industryElements[0]->getText());
                Log::info("Extracted industry from details page: " . $companyData['industry']);
            }
            
            // Employee count - based on the provided HTML structure
            $employeeElements = $this->driver->findElements(
                WebDriverBy::xpath("//a[contains(@href, 'currentCompany') or contains(@class, 'org-top-card-summary-info-list__info-item')][contains(., 'employee')]")
            );
            
            if (count($employeeElements) > 0) {
                $employeeText = trim($employeeElements[0]->getText());
                Log::info("Found employee text: " . $employeeText);
                
                // Parse different employee count formats
                if (preg_match('/([0-9,.]+K?)-([0-9,.]+K?)|([0-9,.]+K?)\s+employees/', $employeeText, $matches)) {
                    // Handle range format like "501-1K"
                    if (isset($matches[2])) {
                        $upper = $matches[2];
                        // Convert K notation if needed
                        if (strpos($upper, 'K') !== false) {
                            $upper = (float) str_replace('K', '', $upper) * 1000;
                        }
                        $companyData['employee_count'] = (int) $upper;
                    } 
                    // Handle single value format like "23 employees" or "2.3K employees"
                    else if (isset($matches[3])) {
                        $count = $matches[3];
                        // Convert K notation if needed
                        if (strpos($count, 'K') !== false) {
                            $count = (float) str_replace('K', '', $count) * 1000;
                        }
                        $companyData['employee_count'] = (int) $count;
                    }
                    
                    Log::info("Parsed employee count: " . $companyData['employee_count']);
                }
            }
            
            // Follower count
            $followerElements = $this->driver->findElements(
                WebDriverBy::xpath("//div[contains(@class, 'org-top-card-summary-info-list__info-item')][contains(., 'followers')]")
            );
            
            if (count($followerElements) > 0) {
                $followerText = trim($followerElements[0]->getText());
                
                // Parse follower count format (e.g., "74K followers")
                preg_match('/([0-9,.]+K?)\s+followers/', $followerText, $matches);
                if (isset($matches[1])) {
                    $count = $matches[1];
                    // Convert K notation if needed
                    if (strpos($count, 'K') !== false) {
                        $count = (float) str_replace('K', '', $count) * 1000;
                    }
                    $companyData['follower_count'] = (int) $count;
                    Log::info("Parsed follower count: " . $companyData['follower_count']);
                }
            }
            
            // Get job count - might need to visit the jobs tab
            $jobElements = $this->driver->findElements(
                WebDriverBy::xpath("//a[contains(@href, '/jobs') and (contains(., 'job') or contains(., 'Job'))]")
            );
            
            if (count($jobElements) > 0) {
                $jobText = $jobElements[0]->getText();
                // Look for numbers followed by "jobs"
                preg_match('/([0-9,.]+)\s+jobs?/i', $jobText, $matches);
                if (isset($matches[1])) {
                    $companyData['job_count'] = (int)str_replace(',', '', $matches[1]);
                    Log::info("Found job count from link text: " . $companyData['job_count']);
                }
            }
        } catch (\Exception $e) {
            Log::error("Error extracting company data: " . $e->getMessage());
        }
        
        return $companyData;
    }
    
    /**
     * Save company data to database
     * 
     * @param array $companyData Company data from extraction
     * @return Company The saved company model
     */
    protected function saveCompanyData($companyData)
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
    
    /**
     * Process company staff
     */
    protected function processCompanyStaff($company)
    {
        try {
            // Look for the "People" tab using text content which is more stable
            $peopleElements = $this->driver->findElements(
                WebDriverBy::xpath('//a[contains(@href, "/people/") or contains(text(), "People")]')
            );
            
            if (count($peopleElements) > 0) {
                $peopleElements[0]->click();
                
                // Wait for the people page to load - look for elements that contain profile information
                $this->wait->until(
                    WebDriverExpectedCondition::presenceOfAllElementsLocatedBy(
                        WebDriverBy::xpath('//div[contains(@class, "profile-card") or contains(@class, "entity-lockup")]')
                    )
                );
                
                // Get staff members - use more general locator
                $staffElements = $this->driver->findElements(
                    WebDriverBy::xpath('//div[contains(@class, "profile-card") or contains(@class, "entity-lockup")]')
                );
                
                foreach ($staffElements as $element) {
                    try {
                        $staffData = $this->extractStaffData($element);
                        
                        if (!empty($staffData['name']) && !empty($staffData['link'])) {
                            $this->saveStaffData($company, $staffData);
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error processing staff for company {$company->company_name}: " . $e->getMessage());
        }
    }
    
    /**
     * Extract staff data from profile card element
     * 
     * @param \Facebook\WebDriver\Remote\RemoteWebElement $element
     * @return array Staff data
     */
    protected function extractStaffData($element)
    {
        $staffData = [
            'name' => '',
            'link' => '',
            'title' => '',
        ];
        
        // Get name from various elements that might contain it
        $nameElement = null;
        $potentialNameElements = $element->findElements(
            WebDriverBy::xpath('.//div[contains(@class, "title") or contains(@class, "name")]')
        );
        
        foreach ($potentialNameElements as $potential) {
            $text = $potential->getText();
            if (!empty(trim($text))) {
                $staffData['name'] = trim($text);
                break;
            }
        }
        
        // Look for profile links
        $linkElements = $element->findElements(
            WebDriverBy::xpath('.//a[contains(@href, "/in/")]')
        );
        
        if (count($linkElements) > 0) {
            $staffData['link'] = $linkElements[0]->getAttribute('href');
        }
        
        // Try to get job title if available
        $titleElements = $element->findElements(
            WebDriverBy::xpath('.//div[contains(@class, "subtitle") or contains(@class, "secondary")]')
        );
        
        if (count($titleElements) > 0) {
            $staffData['title'] = trim($titleElements[0]->getText());
        }
        
        return $staffData;
    }
    
    /**
     * Save staff data to database
     * 
     * @param Company $company
     * @param array $staffData
     * @return CompanyStaff
     */
    protected function saveStaffData($company, $staffData)
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
    
    /**
     * Process job listings
     */
    protected function processJobs($company)
    {
        try {
            // Wait for the jobs page to load - look for job listings by their structure
            $this->wait->until(
                WebDriverExpectedCondition::presenceOfAllElementsLocatedBy(
                    WebDriverBy::xpath('//div[contains(@class, "jobs-search") or contains(@class, "job-card")]')
                )
            );
            
            // Get job listings - more generic job list selector
            $jobElements = $this->driver->findElements(
                WebDriverBy::xpath('//li[.//a[contains(@href, "/jobs/view/")] or .//div[contains(@class, "job-card")]]')
            );
            
            foreach ($jobElements as $element) {
                try {
                    $jobData = $this->extractJobData($element);
                    
                    if (!empty($jobData['title'])) {
                        $this->saveJobData($company, $jobData);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error processing jobs for company {$company->company_name}: " . $e->getMessage());
        }
    }
    
    /**
     * Extract job data from job card element
     * 
     * @param \Facebook\WebDriver\Remote\RemoteWebElement $element
     * @return array Job data
     */
    protected function extractJobData($element)
    {
        $jobData = [
            'title' => '',
            'location' => '',
            'job_type' => 'Unknown',
            'time_type' => 'full_time',
            'applicant_count' => 0,
            'url' => ''
        ];
        
        // Extract job title - look for headings or links to job posts
        $titleElements = $element->findElements(
            WebDriverBy::xpath('.//a[contains(@href, "/jobs/view/") or contains(@class, "job-title")] | .//h3')
        );
        
        if (count($titleElements) > 0) {
            $jobData['title'] = trim($titleElements[0]->getText());
            $jobData['url'] = $titleElements[0]->getAttribute('href');
        }
        
        // Extract location - typically in small text near the title
        $locationElements = $element->findElements(
            WebDriverBy::xpath('.//span[contains(@class, "location") or contains(@class, "metadata")]')
        );
        
        if (count($locationElements) > 0) {
            $jobData['location'] = trim($locationElements[0]->getText());
        }
        
        // Extract job type - could be in different positions
        $jobTypeElements = $element->findElements(
            WebDriverBy::xpath('.//span[contains(text(), "Full-time") or contains(text(), "Part-time") or contains(text(), "Contract")]')
        );
        
        if (count($jobTypeElements) > 0) {
            $jobData['job_type'] = trim($jobTypeElements[0]->getText());
            
            // Determine time type (full-time or part-time)
            if (stripos($jobData['job_type'], 'part-time') !== false) {
                $jobData['time_type'] = 'part_time';
            }
        }
        
        // Extract applicant count if available
        $textNodes = $element->findElements(
            WebDriverBy::xpath('.//*[contains(text(), "applicant") or contains(text(), "application")]')
        );
        
        foreach ($textNodes as $node) {
            $text = $node->getText();
            preg_match('/([0-9,]+)/', $text, $matches);
            if (isset($matches[1])) {
                $jobData['applicant_count'] = (int) str_replace(',', '', $matches[1]);
                break;
            }
        }
        
        return $jobData;
    }
    
    /**
     * Save job data to database
     * 
     * @param Company $company
     * @param array $jobData
     * @return JobDetail
     */
    protected function saveJobData($company, $jobData)
    {
        try {
            // Find or create job type
            $jobType = JobType::firstOrCreate(['job_type' => $jobData['job_type']]);
            
            // Save the job
            $job = JobDetail::updateOrCreate(
                [
                    'job_name' => $jobData['title'],
                    'job_type_id' => $jobType->id
                ],
                [
                    'location' => $jobData['location'],
                    'open_date' => now(),
                    'time_type' => $jobData['time_type'],
                    'number_of_applicants' => $jobData['applicant_count'],
                    'job_description_link' => $jobData['url']
                ]
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
    
    /**
     * Close the browser and clean up
     */
    public function close()
    {
        if ($this->driver) {
            $this->driver->quit();
        }
    }
}