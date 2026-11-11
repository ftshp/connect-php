<?php

declare(strict_types=1);

namespace Ftshp\Connect;

/**
 * Names are case-insensitive and stored in lowercase. Values of names ending in -bin are raw bytes here
 * and unpadded base64 on the wire.
 *
 * @implements \IteratorAggregate<string, list<string>>
 */
final class Headers implements \IteratorAggregate
{
    /** @var array<array-key, list<string>> */
    private array $values = [];

    /**
     * @param array<array-key, string|list<string>> $headers
     */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $values) {
            foreach (\is_array($values) ? $values : [$values] as $value) {
                $this->add((string) $name, $value);
            }
        }
    }

    /**
     * Builds headers from their wire form, as PSR-7 getHeaders() returns it: base64 values of -bin names are
     * decoded, and names and values are not validated. Use the constructor for raw values.
     *
     * @param array<array-key, array<string>> $headers
     */
    public static function fromPsr(array $headers): self
    {
        $result = new self();
        foreach ($headers as $name => $values) {
            $name = strtolower((string) $name);
            foreach ($values as $value) {
                $result->values[$name][] = self::isBinary($name) ? self::decode($value) : $value;
            }
        }

        return $result;
    }

    public function get(string $name): ?string
    {
        return $this->values[strtolower($name)][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function all(string $name): array
    {
        return $this->values[strtolower($name)] ?? [];
    }

    public function set(string $name, string $value): void
    {
        $name = self::validate($name, $value);
        $this->values[$name] = [$value];
    }

    public function add(string $name, string $value): void
    {
        $name = self::validate($name, $value);
        $this->values[$name][] = $value;
    }

    public function remove(string $name): void
    {
        unset($this->values[strtolower($name)]);
    }

    /**
     * @internal
     */
    public function replace(self $other): void
    {
        $this->values = $other->values;
    }

    public function has(string $name): bool
    {
        return isset($this->values[strtolower($name)]);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Returns the wire form, ready for PSR-7 withHeader(): values of -bin names are unpadded base64.
     * Iterate over the headers for raw values.
     *
     * @return array<array-key, list<string>>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->values as $name => $values) {
            $name = (string) $name;
            $result[$name] = self::isBinary($name)
                ? array_map(static fn(string $value): string => rtrim(base64_encode($value), '='), $values)
                : $values;
        }

        return $result;
    }

    /**
     * @return \Traversable<string, list<string>>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        foreach ($this->values as $name => $values) {
            yield (string) $name => $values;
        }
    }

    private static function validate(string $name, string $value): string
    {
        $name = strtolower($name);
        if (preg_match('/^[a-z0-9_.-]+$/', $name) !== 1 || str_starts_with($name, 'connect-')) {
            throw new \InvalidArgumentException(\sprintf('Invalid header name "%s"', $name));
        }
        if (!self::isBinary($name) && preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException(\sprintf('Header "%s" value contains a control character', $name));
        }

        return $name;
    }

    private static function isBinary(string $name): bool
    {
        return str_ends_with($name, '-bin');
    }

    private static function decode(string $value): string
    {
        $padded = str_pad($value, (int) ceil(\strlen($value) / 4) * 4, '=');

        $decoded = base64_decode($padded, true);

        return $decoded === false ? $value : $decoded;
    }
}
