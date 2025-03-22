<?php

namespace App\Console\Commands;

use App\Services\LinkedinCrawlerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CrawlLinkedinData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'linkedin:crawl';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl LinkedIn company and job data';

    /**
     * The LinkedIn crawler service.
     */
    protected $crawlerService;

    /**
     * Create a new command instance.
     *
     * @param LinkedinCrawlerService $crawlerService
     * @return void
     */
    public function __construct(LinkedinCrawlerService $crawlerService)
    {
        parent::__construct();
        $this->crawlerService = $crawlerService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting LinkedIn data crawling process...');

        try {
            // Set a reasonable timeout
            set_time_limit(3600); // 1 hour

            // Start the crawling process
            $success = $this->crawlerService->crawl();

            if ($success) {
                $this->info('LinkedIn data crawling completed successfully.');
                return Command::SUCCESS;
            } else {
                $this->error('LinkedIn data crawling failed. Check logs for more details.');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('Exception during LinkedIn crawling: ' . $e->getMessage());
            Log::error('Exception during LinkedIn crawling: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}