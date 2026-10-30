.PHONY: check fix test stan cs generate

check:
	composer validate --strict
	vendor/bin/composer-dependency-analyser
	vendor/bin/php-cs-fixer check --diff
	vendor/bin/phpstan
	vendor/bin/phpunit
	@if [ -f go.mod ]; then go test ./...; fi

fix:
	vendor/bin/php-cs-fixer fix

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan

cs:
	vendor/bin/php-cs-fixer check --diff

generate:
