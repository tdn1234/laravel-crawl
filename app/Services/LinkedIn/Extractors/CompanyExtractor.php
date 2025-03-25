<?php
namespace App\Services\LinkedIn\Extractors;

use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\Log;

class CompanyExtractor
{
    /**
     * Extract company data from details page
     * 
     * @param RemoteWebDriver $driver
     * @param WebDriverWait $wait
     * @param string|null $jobUrlFromSearch
     * @return array Company data
     */
    public function extractCompanyData($driver, $wait, $jobUrlFromSearch = null)
    {
        $companyData = [
            'name' => '',
            'industry' => 'Unknown',
            'employee_count' => null,
            'job_count' => 0,
            'follower_count' => null,
            'jobs_url' => $jobUrlFromSearch // Initialize with job URL from search results if available
        ];
        
        try {
            // Extract basic company info
            $companyData = array_merge(
                $companyData,
                $this->extractCompanyBasicInfo($driver)
            );
            
            // Extract job data
            $jobData = $this->extractJobInfo($driver, $wait, $companyData['jobs_url']);
            $companyData = array_merge($companyData, $jobData);
            
        } catch (\Exception $e) {
            Log::error("Error extracting company data: " . $e->getMessage());
        }
        
        return $companyData;
    }
    
    /**
     * Extract basic company information
     * 
     * @param RemoteWebDriver $driver
     * @return array Basic company info
     */
    private function extractCompanyBasicInfo($driver)
    {
        $data = [
            'name' => '',
            'industry' => 'Unknown',
            'employee_count' => null,
            'follower_count' => null
        ];
        
        // Company name
        $companyNameElements = $driver->findElements(
            WebDriverBy::xpath("//h1[contains(@class, 'org-top-card-summary__title')]")
        );
        
        if (count($companyNameElements) > 0) {
            $data['name'] = trim($companyNameElements[0]->getText());
            Log::info("Extracted company name from details page: " . $data['name']);
        } else {
            // Fallback to URL if we can't find the name
            $companyUrl = $driver->getCurrentURL();
            if (preg_match('/\/company\/([^\/]+)/', $companyUrl, $matches)) {
                $data['name'] = str_replace('-', ' ', $matches[1]);
                $data['name'] = ucwords($data['name']);
                Log::info("Using company name from URL: " . $data['name']);
            }
        }
        
        // Industry
        $industryElements = $driver->findElements(
            WebDriverBy::xpath("//div[contains(@class, 'org-top-card-summary-info-list__info-item')][1]")
        );
        
        if (count($industryElements) > 0) {
            $data['industry'] = trim($industryElements[0]->getText());
            Log::info("Extracted industry from details page: " . $data['industry']);
        }
        
        // Employee count
        $employeeElements = $driver->findElements(
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
                    $data['employee_count'] = (int) $upper;
                } 
                // Handle single value format like "23 employees" or "2.3K employees"
                else if (isset($matches[3])) {
                    $count = $matches[3];
                    // Convert K notation if needed
                    if (strpos($count, 'K') !== false) {
                        $count = (float) str_replace('K', '', $count) * 1000;
                    }
                    $data['employee_count'] = (int) $count;
                }
                
                Log::info("Parsed employee count: " . $data['employee_count']);
            }
        }
        
        // Follower count
        $followerElements = $driver->findElements(
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
                $data['follower_count'] = (int) $count;
                Log::info("Parsed follower count: " . $data['follower_count']);
            }
        }
        
        return $data;
    }
    
    /**
     * Extract job information
     * 
     * @param RemoteWebDriver $driver
     * @param WebDriverWait $wait
     * @param string|null $jobUrlFromSearch
     * @return array Job data
     */
    private function extractJobInfo($driver, $wait, $jobUrlFromSearch = null)
    {
        $jobData = [
            'job_count' => 0,
            'jobs_url' => $jobUrlFromSearch
        ];
        
        try {
            // If we already have a job URL from search results, extract job count from it
            if (!empty($jobUrlFromSearch)) {
                // Extract job count from job URL text if available
                $jobLinkElements = $driver->findElements(
                    WebDriverBy::xpath("//a[contains(@href, '{$jobUrlFromSearch}')]")
                );
                
                if (count($jobLinkElements) > 0) {
                    $jobText = $jobLinkElements[0]->getText();
                    if (preg_match('/([0-9,.]+)\s+jobs?/i', $jobText, $matches)) {
                        $jobData['job_count'] = (int)str_replace(',', '', $matches[1]);
                        Log::info("Found job count from search URL: " . $jobData['job_count']);
                    }
                }
                
                // If we still don't have a job count, navigate to the URL to get it
                if ($jobData['job_count'] == 0) {
                    return $this->getJobCountFromSearchPage($driver, $wait, $jobUrlFromSearch);
                }
                
                return $jobData;
            }
            
            // Otherwise, look for job info on the company page
            $jobElements = $driver->findElements(
                WebDriverBy::xpath("//a[contains(@href, '/jobs/search/') or contains(@href, '/jobs?')]")
            );
            
            if (count($jobElements) > 0) {
                foreach ($jobElements as $jobElement) {
                    $jobText = $jobElement->getText();
                    $href = $jobElement->getAttribute('href');
                    
                    // Look for text containing job count (e.g., "See all 6 jobs" or "6 jobs")
                    if (preg_match('/(?:See all )?([0-9,.]+)\s+jobs?/i', $jobText, $matches)) {
                        $jobData['job_count'] = (int)str_replace(',', '', $matches[1]);
                        Log::info("Found job count from link text: " . $jobData['job_count']);
                        
                        // Store the jobs URL
                        if (!empty($href) && (strpos($href, '/jobs/search') !== false || strpos($href, '/jobs?') !== false)) {
                            $jobData['jobs_url'] = $href;
                            Log::info("Stored jobs URL: " . $href);
                        }
                        break;
                    }
                }
            }
            
            // If job count is still 0 or jobs_url is still null, try to create one using company ID
            if ($jobData['job_count'] == 0 || $jobData['jobs_url'] == null) {
                $companyId = $this->extractCompanyId($driver);
                
                if ($companyId) {
                    // Create jobs URL with company ID in the correct format
                    $jobsUrl = "https://www.linkedin.com/jobs/search?geoId=92000000&f_C={$companyId}";
                    $jobData['jobs_url'] = $jobsUrl;
                    Log::info("Created jobs URL from company ID: " . $jobsUrl);
                    
                    // Get job count if needed
                    if ($jobData['job_count'] == 0) {
                        return $this->getJobCountFromSearchPage($driver, $wait, $jobsUrl);
                    }
                }
            }
            
            // If still no jobs URL but we have a company URL, create a fallback URL
            if ($jobData['jobs_url'] == null) {
                $companyUrl = $driver->getCurrentURL();
                if (!empty($companyUrl)) {
                    $jobsUrl = rtrim($companyUrl, '/') . '/jobs/?viewAsMember=true';
                    $jobData['jobs_url'] = $jobsUrl;
                    Log::info("Created fallback jobs URL: " . $jobsUrl);
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error extracting job info: " . $e->getMessage());
        }
        
        return $jobData;
    }
    
    /**
     * Get job count by navigating to jobs search page
     * 
     * @param RemoteWebDriver $driver
     * @param WebDriverWait $wait
     * @param string $jobsUrl
     * @return array Updated job data
     */
    private function getJobCountFromSearchPage($driver, $wait, $jobsUrl)
    {
        $jobData = [
            'job_count' => 0,
            'jobs_url' => $jobsUrl
        ];
        
        $currentUrl = $driver->getCurrentURL();
        
        try {
            // Go to jobs page to check count
            $driver->get($jobsUrl);
            
            // Wait for the page to load
            $wait->until(
                \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('.scaffold-layout__list')
                )
            );
            
            // Look for job count in search results header
            $resultCountElements = $driver->findElements(
                WebDriverBy::xpath("//span[contains(text(), 'results')]")
            );
            
            foreach ($resultCountElements as $element) {
                $text = $element->getText();
                if (preg_match('/([0-9,]+)\s+results?/', $text, $matches)) {
                    $jobData['job_count'] = (int)str_replace(',', '', $matches[1]);
                    Log::info("Found job count from search results: " . $jobData['job_count']);
                    break;
                }
            }
            
            // Go back to company page
            $driver->get($currentUrl);
            
            // Wait for the company page to load again
            $wait->until(
                \Facebook\WebDriver\WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::xpath("//div[contains(@class, 'org-top-card')]")
                )
            );
        } catch (\Exception $e) {
            Log::warning("Error getting job count from search page: " . $e->getMessage());
            // Make sure we go back to the original URL
            $driver->get($currentUrl);
        }
        
        return $jobData;
    }
    
    /**
     * Extract company ID from page
     * 
     * @param RemoteWebDriver $driver
     * @return string|null
     */
    private function extractCompanyId($driver)
    {
        $companyId = null;
        
        // Try to extract numeric company ID from URL
        $companyUrl = $driver->getCurrentURL();
        if (preg_match('/\/company\/(\d+)/', $companyUrl, $matches)) {
            $companyId = $matches[1];
        } 
        // If not found in URL, try from page source
        else {
            $pageSource = $driver->getPageSource();
            if (preg_match('/companyId["\s:=]+(\d+)/', $pageSource, $matches)) {
                $companyId = $matches[1];
            } elseif (preg_match('/normalizedCompanyId["\s:=]+(\d+)/', $pageSource, $matches)) {
                $companyId = $matches[1];
            } elseif (preg_match('/urn:li:fs_miniCompany:(\d+)/', $pageSource, $matches)) {
                $companyId = $matches[1];
            }
        }
        
        return $companyId;
    }
}

