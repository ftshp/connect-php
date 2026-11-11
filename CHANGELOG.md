# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0.0, a minor release can contain breaking changes and a patch release contains only backward compatible fixes.

## [Unreleased]

## [0.2.0] - 2026-11-11

This release has one breaking change. Generated interfaces declare `Context $context = new Context()`, the same as the generated client, so code typed against the interface can leave out the context. PHP rejects a server implementation that keeps `Context $context` without the default. To upgrade, regenerate the code and add ` = new Context()` after `Context $context` in every method that implements a generated interface. The other entries keep code written for 0.1 working. Some of them change what goes over the wire so that it matches the Connect protocol and connect-go, as described under Changed.

### Added

- Support for PHP 8.4. CI runs the tests and the conformance suite on PHP 8.4 and 8.5, each with the protobuf C extension and with the pure PHP runtime.
- `composer.json` suggests `ext-protobuf`, whose version should match `google/protobuf`.
- `Headers` is iterable. `foreach ($headers as $name => $values)` yields every header with raw values, including `-bin` names, for which `toArray()` returns base64.
- `ConnectException` takes its metadata as an optional fifth constructor argument, `metadata:`.
- The plugin accepts proto files that use editions, up to edition 2024. protoc and buf refused to run it on them before.
- The leading comment of a service becomes the docblock of the generated interface and client, and `option deprecated = true` on a service marks both `@deprecated`.
- Release builds of the plugin write their version into the header of generated files, as `// versions: protoc-gen-connect-php v0.2.0`. Builds without a release tag leave the line out.
- The README documents the naming rules for generated code, which later releases keep, the minimum protoc-gen-php version (protobuf 36.0) and the `ftshp/connect` constraint that packages with generated code need.
- `docs/compatibility.md` states the backward compatibility promise. It covers public classes and methods with their parameter names, the generated code and the runtime API it calls, and wire defaults such as the 4 MiB message limits and the empty message of `unknown` errors. It leaves out `Ftshp\Connect\Internal`, `@internal` members and the position of optional `Client` and `Server` constructor arguments, and lists `Interceptor`, `Codec`, `Compression` and the generated interfaces as the interfaces applications may implement.
- The README and `docs/compatibility.md` explain that Guzzle's default `decode_content: true` lets curl inflate a gzip response before the size limit applies, and the README example creates the Guzzle client with `decode_content: false`.

### Changed

- Generated interfaces default the context parameter to `new Context()`. This is the breaking change described above.
- The `UnaryRequest` constructor, the `ServiceDescriptor` constructor and `Headers::replace()` are marked `@internal`, like `Context::begin()` already was. They work as before but are outside the [backward compatibility promise](docs/compatibility.md). `Server::register()` still accepts a descriptor from `ServiceDescriptor::fromInterface()`.
- The client sets the deadline when the call starts and again when an interceptor changes `timeoutMs`, as connect-go does with `context.WithTimeout`. Each attempt of a retry interceptor sends the time left in `Connect-Timeout-Ms`, where it sent the full timeout before, and an attempt that starts after the deadline fails with `deadline_exceeded` without sending a request. A `timeoutMs` longer than 10 digits no longer throws `\InvalidArgumentException`; the client leaves the header out.
- The server sends the metadata of a `ConnectException` that a handler or interceptor throws as response headers, as connect-go does. It skips protocol headers, including the `grpc-` status headers that connect-go treats as protocol headers, names starting with `trailer-`, names and values that `Headers` would reject, and the metadata of an error that a `Client` received from another server.
- The server rejects a `Connect-Protocol-Version` header other than `1` and a GET `connect` parameter other than `v1` with `invalid_argument`, also when `requireProtocolHeader` is false. Requests without them are still accepted unless the option is set.
- The server accepts `Connect-Timeout-Ms: 0` and answers `deadline_exceeded` where it answered `invalid_argument`. A handler whose deadline has passed by the time the interceptors call it is not run, and the call ends with `deadline_exceeded`.
- The server does not compress a response with an encoding that `Accept-Encoding` refuses with `q=0`, in any position among the parameters.
- An error built from a response that is not a Connect error has the status line as its message, such as `HTTP 502 Bad Gateway`. The body is appended only when it is `text/plain` or JSON, so HTML pages from proxies stay out of exception messages and logs.
- Proto comments copied into generated docblocks have `@` written as `\@` and `*/` as `{@*}`, as protoc-gen-php does, so a comment such as `@return int` no longer turns into a PHPDoc tag that static analysis reads.
- The generated client creates each `MethodDescriptor` in a static variable of the method itself and has no private `xMethod()` helpers, so a service can have RPCs such as `Get` and `GetMethod` side by side.
- The docblocks of `Headers::toArray()` and `Headers::fromPsr()` say that they use the wire form, with base64 values for `-bin` names, and the `Codec` and `Compression` interfaces document which exceptions an implementation throws.

### Deprecated

- `ConnectException::withMetadata()`. Pass `metadata:` to the constructor or change the `Headers` that `metadata()` returns. The method carries the `#[\Deprecated]` attribute, so a call raises `E_USER_DEPRECATED`.

### Fixed

- The plugin fails with an error when `<Service>Interface` or `<Service>Client` has the same name, ignoring case, as a message, an enum or another service class in the same namespace. protoc failed on the duplicate file when both were in one run, and separate runs overwrote one class with the other.
- The plugin fails with an error on an RPC whose name starts with `__`, where it generated a method that PHP rejects or treats as magic, such as `__construct`.
- The copy that `ConnectException::withMetadata()` returns keeps the file, line and trace of the original exception. The copy of an error that a `Client` received is still treated as received, so the server does not forward its metadata.
- Reading `Context::$method` on a `Context` that no call has started throws a `\LogicException` that says so, instead of an `\Error` about an uninitialized property. The property keeps its `MethodDescriptor` type.

## [0.1.0] - 2026-11-02

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

[Unreleased]: https://github.com/ftshp/connect-php/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/ftshp/connect-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/ftshp/connect-php/releases/tag/v0.1.0
