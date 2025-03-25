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
     * Modify extractCompanyData to store the jobs URL
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
            'follower_count' => null,
            'jobs_url' => null // New field to store the jobs URL
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
            
            // Extract job count from search listing page and grab jobs URL
            try {
                // Look for the "jobs" link in the company page
                $jobElements = $this->driver->findElements(
                    WebDriverBy::xpath("//a[contains(@href, '/jobs/search/') or contains(@href, '/jobs?')]")
                );
                
                if (count($jobElements) > 0) {
                    foreach ($jobElements as $jobElement) {
                        $jobText = $jobElement->getText();
                        $href = $jobElement->getAttribute('href');
                        
                        // Look for text containing job count (e.g., "See all 6 jobs" or "6 jobs")
                        if (preg_match('/(?:See all )?([0-9,.]+)\s+jobs?/i', $jobText, $matches)) {
                            $companyData['job_count'] = (int)str_replace(',', '', $matches[1]);
                            Log::info("Found job count from link text: " . $companyData['job_count']);
                            
                            // Store the jobs URL
                            if (!empty($href) && (strpos($href, '/jobs/search') !== false || strpos($href, '/jobs?') !== false)) {
                                $companyData['jobs_url'] = $href;
                                Log::info("Stored jobs URL: " . $href);
                            }
                            break;
                        }
                    }
                }
                
                // If job count is still 0 or jobs_url is still null, try to find it in the search results header
                if ($companyData['job_count'] == 0 || $companyData['jobs_url'] == null) {
                    // Extract company ID and create a jobs URL
                    $companyUrl = $this->driver->getCurrentURL();
                    $companyId = null;
                    
                    // Try to extract numeric company ID from URL
                    if (preg_match('/\/company\/(\d+)/', $companyUrl, $matches)) {
                        $companyId = $matches[1];
                    } 
                    // If not found in URL, try from page source
                    else {
                        $pageSource = $this->driver->getPageSource();
                        if (preg_match('/companyId["\s:=]+(\d+)/', $pageSource, $matches)) {
                            $companyId = $matches[1];
                        }
                    }
                    
                    if ($companyId) {
                        // Create jobs URL with company ID
                        $jobsUrl = "https://www.linkedin.com/jobs/search/?f_C={$companyId}&geoId=92000000";
                        $companyData['jobs_url'] = $jobsUrl;
                        Log::info("Created jobs URL from company ID: " . $jobsUrl);
                        
                        // Only navigate to check job count if we haven't found it yet
                        if ($companyData['job_count'] == 0) {
                            // Go to jobs page to check count
                            $this->driver->get($jobsUrl);
                            
                            // Wait for the page to load
                            $this->wait->until(
                                WebDriverExpectedCondition::presenceOfElementLocated(
                                    WebDriverBy::cssSelector('.scaffold-layout__list')
                                )
                            );
                            
                            // Look for job count in search results header
                            $resultCountElements = $this->driver->findElements(
                                WebDriverBy::xpath("//span[contains(text(), 'results')]")
                            );
                            
                            foreach ($resultCountElements as $element) {
                                $text = $element->getText();
                                if (preg_match('/([0-9,]+)\s+results?/', $text, $matches)) {
                                    $companyData['job_count'] = (int)str_replace(',', '', $matches[1]);
                                    Log::info("Found job count from search results: " . $companyData['job_count']);
                                    break;
                                }
                            }
                            
                            // Go back to company page
                            $this->driver->navigate()->back();
                        }
                    }
                }
                
                // If still no jobs URL but we have a company URL, create a fallback jobs URL
                if ($companyData['jobs_url'] == null) {
                    $companyUrl = $this->driver->getCurrentURL();
                    if (!empty($companyUrl)) {
                        $jobsUrl = rtrim($companyUrl, '/') . '/jobs/?viewAsMember=true';
                        $companyData['jobs_url'] = $jobsUrl;
                        Log::info("Created fallback jobs URL: " . $jobsUrl);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Error extracting job count and URL: " . $e->getMessage());
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
     * Process job listings for a company
     * 
     * @param Company $company The company model
     * @return int Number of jobs processed
     */
    protected function processJobs($company)
    {
        try {
            // Get the company URL from the current window
            $companyUrl = $this->driver->getCurrentURL();
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
                $pageSource = $this->driver->getPageSource();
                if (preg_match('/companyId":"(\d+)"/', $pageSource, $idMatches)) {
                    $companyId = $idMatches[1];
                    Log::info("Extracted company ID from page source: " . $companyId);
                }
            }
            
            // If we have a company ID, construct a reliable search URL
            if ($companyId) {
                $jobsUrl = "https://www.linkedin.com/jobs/search/?f_C={$companyId}&geoId=92000000";
                Log::info("Constructed jobs URL using company ID: " . $jobsUrl);
            } else {
                // Fallback to a simple jobs URL based on the company URL
                $jobsUrl = rtrim($companyUrl, '/') . '/jobs/';
                Log::info("Constructed fallback jobs URL: " . $jobsUrl);
            }

            // Try to click the "Show all jobs" link
            $clickedShowAllJobs = $this->clickShowAllJobsLink();

            // If clicking failed, fall back to direct URL navigation
            if (!$clickedShowAllJobs) {
                // Your fallback code using direct URL construction
            }
            
            // Navigate to the jobs page
            Log::info("Navigating to jobs URL: " . $jobsUrl);
            $this->driver->get($jobsUrl);
            
            // Wait for the jobs page to load
            try {
                $this->wait->until(
                    WebDriverExpectedCondition::presenceOfElementLocated(
                        WebDriverBy::cssSelector('.jobs-search-results-list')
                    )
                );
            } catch (\Exception $e) {
                // Try alternative selector
                try {
                    $this->wait->until(
                        WebDriverExpectedCondition::presenceOfElementLocated(
                            WebDriverBy::cssSelector('.scaffold-layout__list')
                        )
                    );
                } catch (\Exception $e2) {
                    // One more alternative
                    $this->wait->until(
                        WebDriverExpectedCondition::presenceOfElementLocated(
                            WebDriverBy::xpath("//div[contains(@class, 'jobs-search')]")
                        )
                    );
                }
            }
            
            // Allow time for the jobs list to fully load
            sleep(3);
            
            // Take a screenshot for debugging if needed
            // $this->driver->takeScreenshot('/path/to/jobs_page.png');
            
            // Find job listings
            $jobElements = [];
            
            // Try multiple selectors to find job listings
            $selectors = [
                "//li[contains(@class, 'scaffold-layout__list-item')][.//div[contains(@class, 'job-card-container')]]",
                "//div[contains(@class, 'job-card-container')]",
                "//li[.//a[contains(@href, '/jobs/view/')]]",
                "//a[contains(@href, '/jobs/view/')]"
            ];
            
            foreach ($selectors as $selector) {
                $jobElements = $this->driver->findElements(WebDriverBy::xpath($selector));
                Log::info("Tried selector: {$selector} - Found: " . count($jobElements) . " elements");
                if (count($jobElements) > 0) {
                    break;
                }
            }
            
            // If still no elements found, log HTML for debugging
            if (count($jobElements) == 0) {
                Log::warning("No job elements found with any selector. Current URL: " . $this->driver->getCurrentURL());
                // Log part of the page source for debugging
                $source = $this->driver->getPageSource();
                Log::debug("Page source excerpt: " . substr($source, 0, 2000) . "...");
            }
            
            $jobsProcessed = 0;
            
            foreach ($jobElements as $index => $element) {
                try {
                    // Get job URL directly
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
                        continue;
                    }
                    
                    if (empty($jobUrl)) {
                        continue;
                    }
                    
                    Log::info("Processing job " . ($index + 1) . " with URL: " . $jobUrl);
                    
                    // Navigate directly to the job URL in a new tab
                    $this->driver->executeScript("window.open(arguments[0], '_blank');", [$jobUrl]);
                    
                    // Switch to the new tab
                    $tabs = $this->driver->getWindowHandles();
                    $this->driver->switchTo()->window(end($tabs));
                    
                    // Wait for job details to load
                    try {
                        $this->wait->until(
                            WebDriverExpectedCondition::presenceOfElementLocated(
                                WebDriverBy::cssSelector('.job-view-layout, .jobs-details')
                            )
                        );
                    } catch (\Exception $e) {
                        // Try an alternative selector
                        $this->wait->until(
                            WebDriverExpectedCondition::presenceOfElementLocated(
                                WebDriverBy::xpath("//div[contains(@class, 'jobs-')]")
                            )
                        );
                    }
                    
                    sleep(2); // Give the page a moment to fully load
                    
                    // Extract data from the job details page
                    $jobData = $this->extractJobDataFromDetailsPage();
                    
                    // Save the job data
                    if (!empty($jobData['title'])) {
                        $this->saveJobData($company, $jobData);
                        $jobsProcessed++;
                    }
                    
                    // Close the job details tab and switch back to the jobs list
                    $tabs = $this->driver->getWindowHandles();
                    if (count($tabs) > 1) {
                        $this->driver->close();
                        $this->driver->switchTo()->window($tabs[0]);
                    }
                    
                    // Add a small delay between processing jobs
                    sleep(rand(1, 3));
                } catch (\Exception $e) {
                    Log::warning("Error processing individual job: " . $e->getMessage());
                    
                    // Try to close any extra tabs and get back to the jobs list
                    $tabs = $this->driver->getWindowHandles();
                    if (count($tabs) > 1) {
                        $this->driver->switchTo()->window(end($tabs));
                        $this->driver->close();
                        $this->driver->switchTo()->window($tabs[0]);
                    }
                    
                    continue;
                }
                
                // Process only a limited number of jobs to avoid long runtimes
                if ($jobsProcessed >= 10) {
                    Log::info("Reached limit of 10 jobs processed. Stopping job processing.");
                    break;
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
     * Click or navigate to the jobs search link for a company
     * 
     * @return bool True if successfully navigated to jobs page
     */
    protected function clickShowAllJobsLink()
    {
        try {
            // First attempt: Look for the specific jobs link in the search results format
            $jobsLinkSelectors = [
                "//a[contains(@href, '/jobs/search?') and contains(@href, 'f_C=')]",
                "//a[contains(@class, 'reusable-search-simple-insight__wrapping-link') and contains(@href, '/jobs/search')]",
                "//a[contains(text(), 'jobs') and contains(@href, '/jobs/search')]"
            ];
            
            foreach ($jobsLinkSelectors as $selector) {
                $jobsLinkElements = $this->driver->findElements(WebDriverBy::xpath($selector));
                
                if (count($jobsLinkElements) > 0) {
                    $href = $jobsLinkElements[0]->getAttribute('href');
                    if (!empty($href)) {
                        Log::info("Found jobs search link: " . $href);
                        // Navigate directly to the href instead of clicking
                        $this->driver->get($href);
                        
                        // Wait briefly for navigation
                        sleep(2);
                        
                        // Verify we're on a jobs page
                        $currentUrl = $this->driver->getCurrentURL();
                        if (strpos($currentUrl, '/jobs/search') !== false) {
                            Log::info("Successfully navigated to jobs search page: " . $currentUrl);
                            return true;
                        }
                    }
                }
            }
            
            // Second attempt: Try to find the "Show all jobs" link on company page
            $showAllJobsSelectors = [
                "//a[contains(@class, 'org-jobs-recently-posted-jobs-module__show-all-jobs-btn-link')]",
                "//a[contains(text(), 'Show all jobs')]",
                "//a[contains(text(), 'See all') and contains(text(), 'jobs')]"
            ];
            
            foreach ($showAllJobsSelectors as $selector) {
                $showAllElements = $this->driver->findElements(WebDriverBy::xpath($selector));
                
                if (count($showAllElements) > 0) {
                    $href = $showAllElements[0]->getAttribute('href');
                    if (!empty($href)) {
                        Log::info("Found 'Show all jobs' link: " . $href);
                        // Navigate directly to the href instead of clicking
                        $this->driver->get($href);
                        
                        // Wait briefly for navigation
                        sleep(2);
                        
                        // Verify we're on a jobs page
                        $currentUrl = $this->driver->getCurrentURL();
                        if (strpos($currentUrl, '/jobs/search') !== false) {
                            Log::info("Successfully navigated to jobs search page: " . $currentUrl);
                            return true;
                        }
                    }
                }
            }
            
            // Third attempt: Extract company ID and construct jobs URL if available
            $currentUrl = $this->driver->getCurrentURL();
            $companyId = null;
            
            // Extract numeric company ID from URL if available
            if (preg_match('/\/company\/(\d+)/', $currentUrl, $matches)) {
                $companyId = $matches[1];
            } 
            // If not, try to extract it from page source
            else {
                $pageSource = $this->driver->getPageSource();
                if (preg_match('/companyId["\s:=]+(\d+)/', $pageSource, $matches)) {
                    $companyId = $matches[1];
                }
            }
            
            if ($companyId) {
                $jobsUrl = "https://www.linkedin.com/jobs/search/?f_C={$companyId}&geoId=92000000";
                Log::info("Constructed jobs URL using company ID: " . $jobsUrl);
                $this->driver->get($jobsUrl);
                
                // Wait briefly for navigation
                sleep(2);
                
                // Verify we're on a jobs page
                $currentUrl = $this->driver->getCurrentURL();
                if (strpos($currentUrl, '/jobs/search') !== false) {
                    Log::info("Successfully navigated to jobs search page: " . $currentUrl);
                    return true;
                }
            }
            
            Log::warning("Could not find or navigate to jobs search link");
            return false;
        } catch (\Exception $e) {
            Log::error("Error trying to navigate to jobs search: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract job data directly from the job details page
     * 
     * @return array Job data
     */
    protected function extractJobDataFromDetailsPage()
    {
        $jobData = [
            'title' => '',
            'location' => '',
            'url' => $this->driver->getCurrentURL(),
            'job_type' => 'Unknown',
            'time_type' => 'full_time',
            'posted_date' => now()->format('Y-m-d'),
            'description' => '',
            'applicant_count' => 0,
            'employment_type' => '',
            'seniority_level' => '',
        ];
        
        try {
            // Extract job title
            $titleElements = $this->driver->findElements(
                WebDriverBy::xpath("//h1[contains(@class, 'job-title') or contains(@class, 'jobs-unified-top-card__job-title')]")
            );
            
            if (count($titleElements) > 0) {
                $jobData['title'] = trim($titleElements[0]->getText());
                // Clean up the title text (remove "with verification" if present)
                $jobData['title'] = preg_replace('/\s+with verification$/', '', $jobData['title']);
                Log::info("Job title: " . $jobData['title']);
            } else {
                // Try alternative selector
                $titleElements = $this->driver->findElements(
                    WebDriverBy::cssSelector("h1, h2")
                );
                foreach ($titleElements as $element) {
                    $text = trim($element->getText());
                    if (strlen($text) > 5 && strlen($text) < 150) {
                        $jobData['title'] = $text;
                        Log::info("Job title (alternative): " . $jobData['title']);
                        break;
                    }
                }
            }
            
            // Extract location
            $locationElements = $this->driver->findElements(
                WebDriverBy::xpath("//span[contains(@class, 'location') or contains(@class, 'jobs-unified-top-card__bullet')]")
            );
            
            if (count($locationElements) > 0) {
                $locationText = trim($locationElements[0]->getText());
                
                // Parse location and work type (On-site/Hybrid/Remote)
                if (preg_match('/(.*?)(?:\s+\((On-site|Hybrid|Remote)\))?$/', $locationText, $matches)) {
                    $jobData['location'] = trim($matches[1]);
                    if (isset($matches[2])) {
                        $jobData['job_type'] = $matches[2];
                    }
                } else {
                    $jobData['location'] = $locationText;
                }
                Log::info("Job location: " . $jobData['location']);
            }
            
            // Extract posted date if available
            $dateElements = $this->driver->findElements(
                WebDriverBy::xpath("//span[contains(text(), 'Posted') or contains(text(), 'ago')]")
            );
            
            foreach ($dateElements as $element) {
                $dateText = trim($element->getText());
                // Convert relative date to timestamp (e.g., "1 week ago", "2 days ago")
                if (preg_match('/(?:Posted )?((\d+)\s+(day|week|month|hour)s?\s+ago)/', $dateText, $matches)) {
                    $number = (int)$matches[2];
                    $unit = $matches[3];
                    
                    $now = new \DateTime();
                    switch ($unit) {
                        case 'day':
                            $now->sub(new \DateInterval("P{$number}D"));
                            break;
                        case 'week':
                            $now->sub(new \DateInterval("P{$number}W"));
                            break;
                        case 'month':
                            $now->sub(new \DateInterval("P{$number}M"));
                            break;
                        case 'hour':
                            $now->sub(new \DateInterval("PT{$number}H"));
                            break;
                    }
                    
                    $jobData['posted_date'] = $now->format('Y-m-d');
                    Log::info("Job posted date: " . $jobData['posted_date']);
                    break;
                }
            }
            
            // Get job description
            $descriptionElements = $this->driver->findElements(
                WebDriverBy::xpath("//div[contains(@class, 'show-more-less-html') or contains(@class, 'jobs-description')]")
            );
            
            if (count($descriptionElements) > 0) {
                $jobData['description'] = trim($descriptionElements[0]->getText());
                Log::info("Found job description: " . substr($jobData['description'], 0, 50) . "...");
            }
            
            // Try to get applicant count
            $applicantElements = $this->driver->findElements(
                WebDriverBy::xpath("//span[contains(text(), 'applicant') or contains(text(), 'application')]")
            );
            
            foreach ($applicantElements as $element) {
                $text = $element->getText();
                if (preg_match('/([0-9,]+)/', $text, $matches)) {
                    $jobData['applicant_count'] = (int)str_replace(',', '', $matches[1]);
                    Log::info("Job applicant count: " . $jobData['applicant_count']);
                    break;
                }
            }
            
            // Get employment type and seniority level
            $criteriaElements = $this->driver->findElements(
                WebDriverBy::xpath("//li[contains(@class, 'job-criteria__item')]")
            );
            
            foreach ($criteriaElements as $element) {
                try {
                    $labelElement = $element->findElement(WebDriverBy::xpath(".//h3"));
                    $valueElement = $element->findElement(WebDriverBy::xpath(".//span"));
                    
                    $label = trim($labelElement->getText());
                    $value = trim($valueElement->getText());
                    
                    if (stripos($label, 'employment type') !== false) {
                        $jobData['employment_type'] = $value;
                        Log::info("Job employment type: " . $value);
                        
                        // Set time_type based on employment type
                        if (stripos($value, 'part-time') !== false || stripos($value, 'part time') !== false) {
                            $jobData['time_type'] = 'part_time';
                        } else if (stripos($value, 'full-time') !== false || stripos($value, 'full time') !== false) {
                            $jobData['time_type'] = 'full_time';
                        }
                    } else if (stripos($label, 'seniority level') !== false) {
                        $jobData['seniority_level'] = $value;
                        Log::info("Job seniority level: " . $value);
                    }
                } catch (\Exception $e) {
                    // Ignore errors in processing individual criteria
                    continue;
                }
            }
            
            // Try to determine time_type from the title if not already set
            if ($jobData['time_type'] == 'full_time' && 
                (stripos($jobData['title'], 'part-time') !== false || stripos($jobData['title'], 'part time') !== false)) {
                $jobData['time_type'] = 'part_time';
            }
            
        } catch (\Exception $e) {
            Log::warning("Error extracting job data from details page: " . $e->getMessage());
        }
        
        return $jobData;
    }


    /**
     * Extract detailed job data from the job detail page
     * 
     * @return array Detailed job data
     */
    protected function extractDetailedJobData()
    {
        $detailedData = [
            'description' => '',
            'applicant_count' => 0,
            'employment_type' => '',
            'seniority_level' => '',
        ];
        
        try {
            // Get job description
            $descriptionElements = $this->driver->findElements(
                WebDriverBy::xpath("//div[contains(@class, 'show-more-less-html__markup')]")
            );
            
            if (count($descriptionElements) > 0) {
                $detailedData['description'] = trim($descriptionElements[0]->getText());
            }
            
            // Try to get applicant count
            $applicantElements = $this->driver->findElements(
                WebDriverBy::xpath("//span[contains(text(), 'applicant') or contains(text(), 'application')]")
            );
            
            foreach ($applicantElements as $element) {
                $text = $element->getText();
                if (preg_match('/([0-9,]+)/', $text, $matches)) {
                    $detailedData['applicant_count'] = (int)str_replace(',', '', $matches[1]);
                    break;
                }
            }
            
            // Get employment type and seniority level
            $criteriaElements = $this->driver->findElements(
                WebDriverBy::xpath("//li[contains(@class, 'job-criteria__item')]")
            );
            
            foreach ($criteriaElements as $element) {
                $labelElement = $element->findElement(WebDriverBy::xpath(".//h3"));
                $valueElement = $element->findElement(WebDriverBy::xpath(".//span"));
                
                $label = trim($labelElement->getText());
                $value = trim($valueElement->getText());
                
                if (stripos($label, 'employment type') !== false) {
                    $detailedData['employment_type'] = $value;
                    // Set time_type based on employment type
                    if (stripos($value, 'part-time') !== false || stripos($value, 'part time') !== false) {
                        $detailedData['time_type'] = 'part_time';
                    } else if (stripos($value, 'full-time') !== false || stripos($value, 'full time') !== false) {
                        $detailedData['time_type'] = 'full_time';
                    }
                } else if (stripos($label, 'seniority level') !== false) {
                    $detailedData['seniority_level'] = $value;
                }
            }
            
        } catch (\Exception $e) {
            Log::warning("Error extracting detailed job data: " . $e->getMessage());
        }
        
        return $detailedData;
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