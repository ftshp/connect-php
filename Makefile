.PHONY: check fix test stan cs generate

# Composer's vendor/ would otherwise switch the go command into vendor mode.
export GOFLAGS := -mod=readonly

check:
	composer validate --strict
	composer normalize --dry-run
	vendor/bin/composer-dependency-analyser
	vendor/bin/php-cs-fixer check --diff
	vendor/bin/phpstan
	vendor/bin/phpunit
	go vet ./... && test -z "$$(gofmt -l .)" && go test ./...

fix:
	vendor/bin/php-cs-fixer fix

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan

cs:
	vendor/bin/php-cs-fixer check --diff

generate:
	buf generate
