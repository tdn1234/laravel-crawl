<?php
namespace App\Services\LinkedIn;

use App\Services\LinkedIn\Drivers\WebDriverFactory;
use App\Services\LinkedIn\Processors\CompanyProcessor;
use Illuminate\Support\Facades\Log;

class LinkedinSeleniumService
{
    protected $driver;
    protected $wait;
    protected $loggedIn = false;
    protected $searchUrl;
    protected $companyProcessor;
    protected $webDriverFactory;

    public function __construct(
        WebDriverFactory $webDriverFactory,
        CompanyProcessor $companyProcessor
    ) {
        $this->webDriverFactory = $webDriverFactory;
        $this->companyProcessor = $companyProcessor;
        $this->searchUrl = "https://www.linkedin.com/search/results/companies/?companyHqGeo=%5B%22104514075%22%2C%22106693272%22%2C%22101282230%22%5D&companySize=%5B%22D%22%2C%22E%22%2C%22F%22%5D&industryCompanyVertical=%5B%2227%22%2C%2296%22%2C%221285%22%2C%226%22%2C%2225%22%2C%2255%22%2C%2254%22%2C%221%22%2C%22117%22%2C%22135%22%2C%22146%22%2C%22147%22%2C%2223%22%2C%2226%22%2C%2253%22%2C%227%22%2C%22709%22%2C%22901%22%5D&keywords=a&origin=FACETED_SEARCH&sid=u!U";
    }

    /**
     * Initialize the WebDriver
     */
    public function init()
    {
        list($this->driver, $this->wait) = $this->webDriverFactory->createDriver();
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
            
            // Wait for search results to load
            $this->wait->until(
                \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                    \Facebook\WebDriver\WebDriverBy::xpath("//div[contains(@class, 'search-results')]")
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
                    \Facebook\WebDriver\WebDriverBy::xpath("//button[contains(@aria-label, 'Next') or contains(., 'Next')]")
                );
                
                if (count($nextPageElements) > 0 && $nextPageElements[0]->isEnabled()) {
                    $nextPageElements[0]->click();
                    
                    // Wait for the next page to load
                    sleep(3); // Basic delay
                    
                    // Wait for page to load using a more stable approach
                    $this->wait->until(
                        \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                            \Facebook\WebDriver\WebDriverBy::xpath("//div[contains(@class, 'search-results')]")
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
        return $this->companyProcessor->processCompanyResults($this->driver, $this->wait);
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
