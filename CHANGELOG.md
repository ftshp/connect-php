# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- PSR-18 `Client` for unary Connect calls with the `proto` and `json` codecs, gzip, GET for `NO_SIDE_EFFECTS` methods, timeouts and a response size limit.
- PSR-15 `Server` that serves any number of services from their generated interfaces, with request size limits, timeout parsing and the Connect error format.
- `ConnectException`, `Code` and `ErrorDetail` for errors with typed details.
- `Interceptor` for code that wraps calls on the client and on the server.
- `Context` and `Headers` for request and response headers, trailers and deadlines.
- `protoc-gen-connect-php`, a protoc plugin that generates a service interface and a client, released as binaries for Linux, macOS and Windows.
- Conformance runners for the Connect conformance suite in server and client mode.
