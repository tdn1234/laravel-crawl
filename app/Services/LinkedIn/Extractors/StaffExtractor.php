<?php
namespace App\Services\LinkedIn\Extractors;

use Facebook\WebDriver\WebDriverBy;

class StaffExtractor
{
    /**
     * Extract staff data from profile card element
     * 
     * @param RemoteWebElement $element
     * @return array Staff data
     */
    public function extractStaffData($element)
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
}

