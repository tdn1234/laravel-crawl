# Variables
PHP_CONTAINER=app
DOCKER_COMPOSE=docker-compose
ARTISAN=php artisan
SELENIUM_URL=https://drive.google.com/uc?id=1AgxlejV0qSmZe2WcFYkwjpdTtUPO3BSb&export=download
SELENIUM_JAR=selenium-server-4.29.0.jar

# Default target
.PHONY: help
help:
    @echo "Available commands:"
    @echo "  make install          Install dependencies and Selenium server"
    @echo "  make up               Start Docker containers"
    @echo "  make down             Stop Docker containers"
    @echo "  make restart          Restart Docker containers"
    @echo "  make migrate          Run database migrations"
    @echo "  make seed             Seed the database"
    @echo "  make test             Run PHPUnit tests"
    @echo "  make serve            Start the Laravel development server"
    @echo "  make logs             View Docker logs"
    @echo "  make crawl            Run the LinkedIn scraping command"

# Install dependencies and Selenium server
.PHONY: install
install:
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) composer install
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) npm install
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) npm run build
    @echo "Downloading Selenium server..."
    @curl -L $(SELENIUM_URL) -o $(SELENIUM_JAR)
    @echo "Starting Selenium server..."
    @java -jar $(SELENIUM_JAR) standalone &
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) $(ARTISAN) migrate

# Docker commands
.PHONY: up
up:
    @$(DOCKER_COMPOSE) up -d

.PHONY: down
down:
    @$(DOCKER_COMPOSE) down

.PHONY: restart
restart:
    @$(DOCKER_COMPOSE) down
    @$(DOCKER_COMPOSE) up -d

# Laravel commands
.PHONY: migrate
migrate:
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) $(ARTISAN) migrate

.PHONY: seed
seed:
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) $(ARTISAN) db:seed

.PHONY: test
test:
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) vendor/bin/phpunit

.PHONY: serve
serve:
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) $(ARTISAN) serve --host=0.0.0.0 --port=8000

# Crawl LinkedIn data
.PHONY: crawl
crawl:
    @$(DOCKER_COMPOSE) exec $(PHP_CONTAINER) $(ARTISAN) linkedin:scrape-companies

# Logs
.PHONY: logs
logs:
    @$(DOCKER_COMPOSE) logs -f