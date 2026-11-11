# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- `Headers` is iterable. `foreach ($headers as $name => $values)` yields every header with raw values, including `-bin` names, for which `toArray()` returns base64.
- `ConnectException` takes its metadata as an optional fifth constructor argument, `metadata:`.

### Changed

- Generated interfaces declare `Context $context = new Context()`, the same as the generated client, so code typed against the interface can leave out the context. This breaks server implementations, which PHP now rejects unless they declare the default too. To migrate, add ` = new Context()` after `Context $context` in every method that implements a generated interface.
- The `UnaryRequest` constructor, the `ServiceDescriptor` constructor and `Headers::replace()` are marked `@internal`, like `Context::begin()` already was. They work as before but are outside the backward compatibility promise. `Server::register()` still accepts a descriptor from `ServiceDescriptor::fromInterface()`.
- The client sets the deadline when the call starts and again when an interceptor changes `timeoutMs`, as connect-go does with `context.WithTimeout`. Each attempt of a retry interceptor sends the time left in `Connect-Timeout-Ms`, where it sent the full timeout before, and an attempt that starts after the deadline fails with `deadline_exceeded` without sending a request. A `timeoutMs` longer than 10 digits no longer throws `\InvalidArgumentException`; the client leaves the header out.
- The server sends the metadata of a `ConnectException` that a handler or interceptor throws as response headers, as connect-go does. It skips protocol headers, including the `grpc-` status headers that connect-go treats as protocol headers, names starting with `trailer-`, names and values that `Headers` would reject, and the metadata of an error that a `Client` received from another server.
- The server rejects a `Connect-Protocol-Version` header other than `1` and a GET `connect` parameter other than `v1` with `invalid_argument`, also when `requireProtocolHeader` is false. Requests without them are still accepted unless the option is set.
- The server accepts `Connect-Timeout-Ms: 0` and answers `deadline_exceeded` where it answered `invalid_argument`. A handler whose deadline has passed by the time the interceptors call it is not run, and the call ends with `deadline_exceeded`.
- The server does not compress a response with an encoding that `Accept-Encoding` refuses with `q=0`, in any position among the parameters.
- An error built from a response that is not a Connect error has the status line as its message, such as `HTTP 502 Bad Gateway`. The body is appended only when it is `text/plain` or JSON, so HTML pages from proxies stay out of exception messages and logs.
- The docblocks of `Headers::toArray()` and `Headers::fromPsr()` say that they use the wire form, with base64 values for `-bin` names, and the `Codec` and `Compression` interfaces document which exceptions an implementation throws.

### Deprecated

- `ConnectException::withMetadata()`. Pass `metadata:` to the constructor or change the `Headers` that `metadata()` returns. The method carries the `#[\Deprecated]` attribute, so a call raises `E_USER_DEPRECATED`.

### Fixed

- The copy that `ConnectException::withMetadata()` returns keeps the file, line and trace of the original exception. The copy of an error that a `Client` received is still treated as received, so the server does not forward its metadata.
- Reading `Context::$method` on a `Context` that no call has started throws a `\LogicException` that says so, instead of an `\Error` about an uninitialized property. The property keeps its `MethodDescriptor` type.

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
