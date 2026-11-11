# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Changed

- Generated interfaces declare `Context $context = new Context()`, the same as the generated client, so code typed against the interface can leave out the context. This breaks server implementations, which PHP now rejects unless they declare the default too. To migrate, add ` = new Context()` after `Context $context` in every method that implements a generated interface.

## 0.1.0 - 2026-11-02

### Added

- Client: a PSR-18 `Client` for unary Connect calls with GET for `NO_SIDE_EFFECTS` methods, timeouts and a response size limit.
- Server: a PSR-15 `Server` that serves any number of services from their generated interfaces, with request size limits and timeout parsing.
- Generated code and the plugin: `protoc-gen-connect-php` generates a service interface and a client, released as binaries for Linux, macOS and Windows.
- Codecs and compression: the `proto` and `json` codecs and gzip.
- Interceptors: `Interceptor` wraps calls on the client and on the server, with `Context` and `Headers` for headers, trailers and deadlines.
- Errors and details: `ConnectException`, `Code` and `ErrorDetail` for errors in the Connect format with typed details.
- Conformance: runners for the Connect conformance suite in server and client mode. The server passes 172 of 172 cases and the client 180 of 184.
- Documentation: a README and guides for migrating from TwirPHP and for Symfony.

### Known limitations

- Only unary calls are supported, there is no streaming.
- gRPC and gRPC-Web are not supported.
- A synchronous PSR-18 client cannot cancel a call that is in flight.
- Four `cancel-after-close-send` conformance cases fail for that reason.
