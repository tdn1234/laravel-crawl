<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyStaff;
use App\Models\Industry;
use App\Models\JobDetail;
use App\Models\JobType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class LinkedinCrawlerService
{
    protected $baseUrl;
    protected $searchUrl;
    protected $headers;

    public function __construct()
    {
        $this->baseUrl = 'https://www.linkedin.com';
        $this->searchUrl = "https://www.linkedin.com/search/results/companies/?companyHqGeo=%5B%22104514075%22%2C%22106693272%22%2C%22101282230%22%5D&companySize=%5B%22D%22%2C%22E%22%2C%22F%22%5D&industryCompanyVertical=%5B%2227%22%2C%2296%22%2C%221285%22%2C%226%22%2C%2225%22%2C%2255%22%2C%2254%22%2C%221%22%2C%22117%22%2C%22135%22%2C%22146%22%2C%22147%22%2C%2223%22%2C%2226%22%2C%2253%22%2C%227%22%2C%22709%22%2C%22901%22%5D&keywords=a&origin=FACETED_SEARCH&sid=u!U";
        
        // Set headers to mimic a browser
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Cache-Control' => 'max-age=0',
        ];
    }

    /**
     * Start the crawling process.
     * Note: For a real implementation, you'd need to handle authentication
     */
    public function crawl()
    {
        try {
            // In a real implementation, you would need to handle LinkedIn authentication
            // This is a simplified example assuming you're authenticated

            // Fetch the search results page
            Log::info('Starting LinkedIn crawl process');
            $html = $this->fetchUrl($this->searchUrl);
            
            if (!$html) {
                Log::error('Failed to fetch LinkedIn search results');
                return false;
            }

            // Parse the company listings
            $companies = $this->parseCompanyListings($html);
            
            foreach ($companies as $companyData) {
                // Process and save each company
                $this->processCompanyData($companyData);
                
                // Add some delay to avoid being blocked
                sleep(rand(2, 5));
            }
            
            Log::info('LinkedIn crawl process completed successfully');
            return true;
        } catch (\Exception $e) {
            Log::error('Error in LinkedIn crawler: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch URL content
     */
    protected function fetchUrl($url)
    {
        try {
            // You might need to adjust this based on your specific setup
            // In a real scenario, you'd need to handle cookies and sessions
            $response = Http::withHeaders($this->headers)->get($url);
            
            if ($response->successful()) {
                return $response->body();
            }
            
            Log::warning("Failed to fetch URL: {$url}, Status: {$response->status()}");
            return null;
        } catch (\Exception $e) {
            Log::error("Exception when fetching URL: {$url}, Error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Parse company listings from the search results page
     */
    protected function parseCompanyListings($html)
    {
        $crawler = new Crawler($html);
        $companies = [];

        // Note: These selectors are examples and will need to be adjusted based on actual LinkedIn HTML structure
        // LinkedIn frequently changes their HTML structure to prevent scraping
        try {
            // Find company listing elements
            $crawler->filter('.search-result__result-link').each(function (Crawler $node) use (&$companies) {
                $companyUrl = $node->attr('href');
                $companyName = trim($node->filter('.search-result__title-text')->text());
                
                // Basic data from the search results
                $companyData = [
                    'name' => $companyName,
                    'url' => $this->baseUrl . $companyUrl,
                ];
                
                $companies[] = $companyData;
            });
        } catch (\Exception $e) {
            Log::error('Error parsing company listings: ' . $e->getMessage());
        }

        return $companies;
    }

    /**
     * Process and save company data
     */
    protected function processCompanyData($companyData)
    {
        try {
            // Fetch detailed company page
            $html = $this->fetchUrl($companyData['url']);
            
            if (!$html) {
                Log::warning("Couldn't fetch details for company: {$companyData['name']}");
                return;
            }
            
            $crawler = new Crawler($html);
            
            // Extract company details
            // Note: These selectors are examples and will need to be adjusted
            $industryName = $crawler->filter('.company-industries')->count() ? 
                            trim($crawler->filter('.company-industries')->text()) : 
                            'Unknown Industry';
            
            $employeeCount = $crawler->filter('.company-employee-count')->count() ? 
                             $this->extractNumberFromText($crawler->filter('.company-employee-count')->text()) : 
                             null;
            
            // Extract job count - this might be on a separate page
            $jobCount = 0;
            if ($crawler->filter('.company-jobs-link')->count()) {
                // In a real implementation, you might need to visit the jobs page
                $jobCount = $this->extractNumberFromText($crawler->filter('.company-jobs-link')->text());
            }
            
            // Find or create industry
            $industry = Industry::firstOrCreate(
                ['industry_name' => $industryName]
            );
            
            // Create or update company
            $company = Company::updateOrCreate(
                ['company_name' => $companyData['name']],
                [
                    'industry_id' => $industry->id,
                    'employee_number' => $employeeCount,
                    'open_jobs' => $jobCount
                ]
            );
            
            // Get company staff
            $this->processCompanyStaff($company, $companyData['url']);
            
            // Get job listings
            $this->processJobs($company, $companyData['url']);
            
            Log::info("Processed company: {$companyData['name']}");
        } catch (\Exception $e) {
            Log::error("Error processing company {$companyData['name']}: " . $e->getMessage());
        }
    }

    /**
     * Process and save company staff data
     */
    protected function processCompanyStaff($company, $companyUrl)
    {
        try {
            // In a real implementation, you'd visit the "People" tab
            $peopleUrl = $companyUrl . '/people/';
            $html = $this->fetchUrl($peopleUrl);
            
            if (!$html) {
                return;
            }
            
            $crawler = new Crawler($html);
            
            // Parse staff list
            $crawler->filter('.org-people-profile-card').each(function (Crawler $node) use ($company) {
                $name = $node->filter('.org-people-profile-card__profile-title')->text();
                $profileLink = $node->filter('a.app-aware-link')->attr('href');
                
                CompanyStaff::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'full_name' => trim($name)
                    ],
                    [
                        'contact_link' => $profileLink
                    ]
                );
            });
        } catch (\Exception $e) {
            Log::error("Error processing staff for company ID {$company->id}: " . $e->getMessage());
        }
    }

    /**
     * Process and save job listings
     */
    protected function processJobs($company, $companyUrl)
    {
        try {
            // In a real implementation, you'd visit the "Jobs" tab
            $jobsUrl = $companyUrl . '/jobs/';
            $html = $this->fetchUrl($jobsUrl);
            
            if (!$html) {
                return;
            }
            
            $crawler = new Crawler($html);
            
            // Parse job listings
            $crawler->filter('.job-card-container').each(function (Crawler $node) use ($company) {
                $jobName = $node->filter('.job-card-list__title')->text();
                $location = $node->filter('.job-card-container__metadata-item')->text();
                $jobUrl = $node->filter('.job-card-list__title')->attr('href');
                $applicantCount = 0;
                
                if ($node->filter('.job-card-container__applicant-count')->count()) {
                    $applicantCount = $this->extractNumberFromText(
                        $node->filter('.job-card-container__applicant-count')->text()
                    );
                }
                
                // Determine job type - this might require visiting the job detail page
                $jobTypeText = 'Unknown';
                $timeType = 'full_time'; // Default
                
                // Get or create job type
                $jobType = JobType::firstOrCreate(['job_type' => $jobTypeText]);
                
                // Create or update job
                JobDetail::updateOrCreate(
                    [
                        'job_name' => trim($jobName),
                        'job_type_id' => $jobType->id
                    ],
                    [
                        'location' => trim($location),
                        'open_date' => now(), // You might extract this from the page
                        'time_type' => $timeType,
                        'number_of_applicants' => $applicantCount,
                        'job_description_link' => $this->baseUrl . $jobUrl
                    ]
                );
            });
        } catch (\Exception $e) {
            Log::error("Error processing jobs for company ID {$company->id}: " . $e->getMessage());
        }
    }

    /**
     * Helper function to extract numbers from text
     */
    protected function extractNumberFromText($text)
    {
        preg_match('/\d+/', $text, $matches);
        return $matches[0] ?? 0;
    }
}