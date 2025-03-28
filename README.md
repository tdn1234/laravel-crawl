# Installation and Usage Guide

## Prerequisites

Before running the application, ensure the following are installed and configured on your system:

- **Google Chrome**: Install the latest version of Google Chrome.
- **ChromeDriver**: Ensure that the version of ChromeDriver matches your installed version of Google Chrome. You can download ChromeDriver from [here](https://chromedriver.chromium.org/downloads).
- **Java**: Install Java (required to run the Selenium server). You can verify the installation by running:
    ```bash
    java -version
    ```
- **Selenium WebDriver Extension**: Install the Selenium WebDriver extension in your Chrome browser to allow the application to interact with it.

## Installation Steps

1. Clone the repository:
     ```bash
     git clone git@github.com:tdn1234/laravel-crawl.git
     cd laravel-crawl
     ```

2. Run the install command to set up the application:
     ```bash
     make install
     ```

     This command will:
     - Install PHP dependencies using Composer.
     - Install Node.js dependencies using NPM.
     - Build frontend assets.
     - Download and start the Selenium server.
     - Run database migrations.

## Crawling Data

Once the installation is complete, you can start crawling LinkedIn data using the following command:
```bash
make crawl
```

This command will:
- Execute the Laravel command `php artisan linkedin:scrape-companies` inside the Docker container.
- Use Selenium to scrape company data from LinkedIn.

## Notes

- Ensure that the Selenium server is running before executing the `make crawl` command. The `make install` command starts the Selenium server automatically.
- If you encounter any issues with ChromeDriver or Selenium, verify that the versions of Chrome, ChromeDriver, and Selenium are compatible.

## Example Workflow

1. Install the application:
     ```bash
     make install
     ```

2. Start crawling LinkedIn data:
     ```bash
     make crawl
     ```

     This will scrape company data and store it in your database.