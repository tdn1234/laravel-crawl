<?php
namespace App\Services\LinkedIn\Extractors;

use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Facades\Log;

class JobExtractor
{
    /**
     * Extract job data from job details page
     * 
     * @param RemoteWebDriver $driver
     * @return array Job data
     */
    public function extractJobData($driver)
    {
        $jobData = [
            'title' => '',
            'location' => '',
            'url' => $driver->getCurrentURL(),
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
           
            $titleElements = $driver->findElements(
                WebDriverBy::xpath("//h1[contains(@class, 'job-title') or contains(@class, 'jobs-unified-top-card__job-title')]")
            );
            
            if (count($titleElements) > 0) {
                $jobData['title'] = trim($titleElements[0]->getText());
                // Clean up the title text (remove "with verification" if present)
                $jobData['title'] = preg_replace('/\s+with verification$/', '', $jobData['title']);
                Log::info("Job title: " . $jobData['title']);
            } else {
                // Try alternative selector
                $titleElements = $driver->findElements(
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
            $locationElements = $driver->findElements(
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
            
            // Extract posted date
            $jobData['posted_date'] = $this->extractPostedDate($driver);
            
            // Get job description
            $descriptionElements = $driver->findElements(
                WebDriverBy::xpath("//div[contains(@class, 'show-more-less-html') or contains(@class, 'jobs-description')]")
            );
            
            if (count($descriptionElements) > 0) {
                $jobData['description'] = trim($descriptionElements[0]->getText());
                Log::info("Found job description: " . substr($jobData['description'], 0, 50) . "...");
            }
            
            // Get applicant count
            $jobData['applicant_count'] = $this->extractApplicantCount($driver);
            
            // Get employment type and seniority level
            $criteriaData = $this->extractJobCriteria($driver);
            $jobData = array_merge($jobData, $criteriaData);
            
        } catch (\Exception $e) {
            Log::warning("Error extracting job data: " . $e->getMessage());
        }
        
        return $jobData;
    }
    
    /**
     * Extract posted date
     * 
     * @param RemoteWebDriver $driver
     * @return string Date in Y-m-d format
     */
    private function extractPostedDate($driver)
    {
        $postedDate = now()->format('Y-m-d');
        
        $dateElements = $driver->findElements(
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
                
                $postedDate = $now->format('Y-m-d');
                Log::info("Job posted date: " . $postedDate);
                break;
            }
        }
        
        return $postedDate;
    }
    
    /**
     * Extract applicant count
     * 
     * @param RemoteWebDriver $driver
     * @return int
     */
    private function extractApplicantCount($driver)
    {
        $applicantCount = 0;
        
        $applicantElements = $driver->findElements(
            WebDriverBy::xpath("//span[contains(text(), 'applicant') or contains(text(), 'application')]")
        );
        
        foreach ($applicantElements as $element) {
            $text = $element->getText();
            if (preg_match('/([0-9,]+)/', $text, $matches)) {
                $applicantCount = (int)str_replace(',', '', $matches[1]);
                Log::info("Job applicant count: " . $applicantCount);
                break;
            }
        }
        
        return $applicantCount;
    }
    
    /**
     * Extract job criteria (employment type, seniority level)
     * 
     * @param RemoteWebDriver $driver
     * @return array
     */
    private function extractJobCriteria($driver)
    {
        $criteriaData = [
            'employment_type' => '',
            'seniority_level' => '',
            'time_type' => 'full_time'
        ];
        
        $criteriaElements = $driver->findElements(
            WebDriverBy::xpath("//li[contains(@class, 'job-criteria__item')]")
        );
        
        foreach ($criteriaElements as $element) {
            try {
                $labelElement = $element->findElement(WebDriverBy::xpath(".//h3"));
                $valueElement = $element->findElement(WebDriverBy::xpath(".//span"));
                
                $label = trim($labelElement->getText());
                $value = trim($valueElement->getText());
                
                if (stripos($label, 'employment type') !== false) {
                    $criteriaData['employment_type'] = $value;
                    Log::info("Job employment type: " . $value);
                    
                    // Set time_type based on employment type
                    if (stripos($value, 'part-time') !== false || stripos($value, 'part time') !== false) {
                        $criteriaData['time_type'] = 'part_time';
                    } else if (stripos($value, 'full-time') !== false || stripos($value, 'full time') !== false) {
                        $criteriaData['time_type'] = 'full_time';
                    }
                } else if (stripos($label, 'seniority level') !== false) {
                    $criteriaData['seniority_level'] = $value;
                    Log::info("Job seniority level: " . $value);
                }
            } catch (\Exception $e) {
                // Ignore errors in processing individual criteria
                continue;
            }
        }
        
        return $criteriaData;
    }
}
