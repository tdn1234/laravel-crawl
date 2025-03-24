<?php

namespace App\Console\Commands;

use App\Services\LinkedinSeleniumService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScrapeLinkedinWithSelenium extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'linkedin:selenium-scrape';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape LinkedIn data using Selenium with authenticated account';

    /**
     * Execute the console command.
     */
    public function handle()
    {        
        $this->info('Starting LinkedIn scraping with Selenium...');
        
        $crawler = new LinkedinSeleniumService();
        
        try {
            // Initialize connection to remote Selenium
            $this->info('Connecting to Selenium on host machine...');
            $crawler->init();
            
            // Prompt user to ensure they're logged in
            $this->info('Please ensure you are logged into LinkedIn in your browser');
            $this->info('Press ENTER when ready...');
            fgets(STDIN);
            
            // Continue with crawling
            $this->info('Crawling company data...');
            $companyCount = $crawler->crawlCompanies();
            
            $this->info("Scraping completed successfully. Processed $companyCount companies.");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error during LinkedIn scraping: ' . $e->getMessage());
            Log::error('LinkedIn scraping error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return Command::FAILURE;
        } finally {
            // Always close the browser
            try {
                $crawler->close();
            } catch (\Exception $e) {
                // Ignore any errors during close
            }
        }
    }
}