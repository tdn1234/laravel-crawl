<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LinkedIn\LinkedinSeleniumService;

class ScrapeLinkedInCompanies extends Command
{
    protected $signature = 'linkedin:scrape-companies';
    protected $description = 'Scrape LinkedIn companies and their jobs';

    protected $linkedinService;

    public function __construct(LinkedinSeleniumService $linkedinService)
    {
        parent::__construct();
        $this->linkedinService = $linkedinService;
    }

    public function handle()
    {
        $this->info('Starting LinkedIn company scraper...');
        
        try {
            $this->linkedinService->init();
            $companiesCount = $this->linkedinService->crawlCompanies();
            
            $this->info("Successfully scraped $companiesCount companies");
        } catch (\Exception $e) {
            $this->error('Error during scraping: ' . $e->getMessage());
        } finally {
            $this->linkedinService->close();
        }
        
        return 0;
    }
}