<?php
namespace App\Services\LinkedIn\Drivers;

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverWait;

class WebDriverFactory
{
    protected $chromeProfilePath;
    protected $hubUrl;
    
    public function __construct(string $chromeProfilePath = '/home/tdn/.config/google-chrome/Default', string $hubUrl = 'http://host.docker.internal:4444/wd/hub')
    {
        $this->chromeProfilePath = $chromeProfilePath;
        $this->hubUrl = $hubUrl;
    }
    
    /**
     * Create and configure WebDriver
     * 
     * @return array [RemoteWebDriver, WebDriverWait]
     */
    public function createDriver()
    {
        $options = new ChromeOptions();
        
        // For debugging, it's often easier to see the browser
        $options->addArguments([
            '--start-maximized',
            '--disable-notifications',
            '--disable-infobars'
        ]);

        // Use existing Chrome profile to maintain authentication
        $options->addArguments([
            'user-data-dir=' . $this->chromeProfilePath,
            'profile-directory=Default'
        ]);
        
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
            
        // Create driver
        $driver = RemoteWebDriver::create(
            $this->hubUrl, 
            $capabilities
        );
        
        // Create a wait object with 20 second timeout
        $wait = new WebDriverWait($driver, 20);
        
        return [$driver, $wait];
    }
}