SHELL = /bin/bash
# Single entry point for all dev/test ops of xakki/file-uploader-symfony.
# PHP isn't installed natively — every target runs in Docker (override PHP_IMAGE if needed).

PUID := $(shell id -u)
PGID := $(shell id -g)
PHP_IMAGE ?= lfu-test:latest

# Run as the current user so files created in the container aren't root-owned.
# Repo mounted at /repo; composer caches under /tmp (writable for a home-less uid).
DOCKER_USER = --user $(PUID):$(PGID) -e HOME=/tmp
DOCKER_PHP  = docker run --rm $(DOCKER_USER) -v "$(CURDIR)":/repo \
	-e COMPOSER_HOME=/tmp/c -e COMPOSER_CACHE_DIR=/tmp/cc -w /repo $(PHP_IMAGE)

.DEFAULT_GOAL := help
.PHONY: help install check test phpstan pint pint-fix test-coredev clean

##@ Help
help:  ## Display this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

##@ Install
# composer.lock is gitignored (library), so targets `composer update` to resolve against
# composer.json rather than depend on a possibly-stale local lock.
install:  ## Resolve & install composer deps (published core from Packagist)
	$(DOCKER_PHP) sh -lc 'composer update --no-interaction'

##@ Test & quality
check:  ## Full local gate — mirrors CI (phpunit + phpstan + pint)
	$(DOCKER_PHP) sh -lc 'composer update -q --no-interaction && composer phpunit && composer phpstan && composer cs-check'

test:  ## PHPUnit (installs deps if missing)
	$(DOCKER_PHP) sh -lc 'composer update -q --no-interaction && composer phpunit'

phpstan:  ## PHPStan
	$(DOCKER_PHP) sh -lc 'composer update -q --no-interaction && composer phpstan'

pint:  ## Pint style check
	$(DOCKER_PHP) sh -lc 'composer update -q --no-interaction && composer cs-check'

pint-fix:  ## Pint auto-fix (edits files)
	$(DOCKER_PHP) sh -lc 'composer update -q --no-interaction && composer cs-fix'

##@ Bindings
test-coredev:  ## Full gate against the working-tree core (../file-uploader) instead of the published one
	docker run --rm $(DOCKER_USER) -v "$(dir $(CURDIR))":/work \
	  -e COMPOSER_HOME=/tmp/c -e COMPOSER_CACHE_DIR=/tmp/cc \
	  -w /work/$(notdir $(CURDIR)) $(PHP_IMAGE) sh -lc '\
	    mkdir -p /tmp/b && tar --exclude=vendor --exclude=.git --exclude=composer.lock -cf - . | tar -xf - -C /tmp/b && \
	    cd /tmp/b && \
	    composer config repositories.coredev path /work/file-uploader && \
	    composer require "xakki/file-uploader:*@dev" --no-update -W -q && \
	    composer update --no-interaction && \
	    composer phpunit && composer phpstan && composer cs-check'

##@ Maintenance
clean:  ## Remove installed deps + local lock (in-container as root to clear files left by earlier runs)
	docker run --rm -v "$(CURDIR)":/repo -w /repo $(PHP_IMAGE) rm -rf vendor composer.lock .phpunit.result.cache
