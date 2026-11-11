# Contributing

Issues and pull requests go to [github.com/ftshp/connect-php](https://github.com/ftshp/connect-php).

## Before you start

Open an issue before you add or change anything in the public API: a new class, method, constructor argument or generated code shape. The library keeps its API small on purpose, and it is easier to agree on the shape before the code exists. Bug fixes and internal changes do not need an issue first.

A change has to keep the [backward compatibility promise](docs/compatibility.md). One that breaks it waits for a release that may break it, deprecates the old API first where it can, and describes the migration in the changelog.

The scope is the Connect protocol for unary RPCs. Streaming, gRPC and gRPC-Web are out of scope, as the README explains.

## Setup

You need PHP 8.4 or newer with `zlib`, Composer, Go and buf. The conformance suite also needs the `pcntl` and `posix` extensions.

```sh
composer install
make check
```

`make check` runs Composer validation, the dependency analyser, php-cs-fixer, PHPStan at `level: max`, PHPUnit and the Go checks for the generator. `make fix` applies the code style. After a change to the generator or to `tests/proto`, run `make generate` and commit the regenerated files in `tests/Generated`.

Composer's `vendor/` directory switches the `go` command into vendor mode. The Makefile sets `GOFLAGS=-mod=readonly` for that reason, so when you run `go` yourself in a checkout, set it too:

```sh
GOFLAGS=-mod=readonly go test ./...
```

## Pull requests

- Run `make check` and `make conformance` before you open the pull request. CI runs both.
- Add tests for new behaviour and for every bug you fix.
- Keep one change per pull request. Pull requests are squash-merged, and the title becomes the commit subject.
- Write the commit subject in the imperative, capitalised, with no type prefix and no trailing period, in 72 characters or fewer. Add a body only when the reason for the change is not obvious from the diff.

There is no CLA and no DCO sign-off. By opening a pull request you agree that your contribution is licensed under the Apache License 2.0, like the rest of the project.

## Code style

The code runs on PHP 8.4 and newer, with strict types. CI tests every supported PHP version, so a language feature or function that is newer than 8.4 fails there. Classes are `final` and properties `readonly` unless there is a reason otherwise. Comments are for things the code cannot say; the code and its names should explain the rest. The runtime depends only on the PSR HTTP interfaces and `google/protobuf`, so ask in an issue before adding a dependency.
