<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Ftshp\Connect\Attribute\Procedure;
use Ftshp\Connect\Attribute\Service;
use Google\Protobuf\Internal\Message;

final readonly class ServiceDescriptor
{
    /**
     * @internal use fromInterface()
     *
     * @param class-string $interface
     * @param array<string, MethodDescriptor<Message, Message>> $methods keyed by proto method name
     */
    public function __construct(
        public string $name,
        public string $interface,
        public array $methods,
    ) {}

    /**
     * @param class-string $interface
     */
    public static function fromInterface(string $interface): self
    {
        /** @var array<class-string, self> $cache */
        static $cache = [];

        return $cache[$interface] ??= self::reflect(new \ReflectionClass($interface));
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function reflect(\ReflectionClass $class): self
    {
        if (!$class->isInterface()) {
            throw new \InvalidArgumentException(\sprintf('%s is not an interface', $class->name));
        }
        $service = ($class->getAttributes(Service::class)[0] ?? null)?->newInstance()
            ?? throw new \InvalidArgumentException(\sprintf('%s has no #[Service] attribute', $class->name));

        $methods = [];
        foreach ($class->getMethods() as $method) {
            $procedure = ($method->getAttributes(Procedure::class)[0] ?? null)?->newInstance()
                ?? throw new \InvalidArgumentException(\sprintf('%s::%s() has no #[Procedure] attribute', $class->name, $method->name));
            $parameters = $method->getParameters();
            if (\count($parameters) !== 2 || self::className($parameters[1]->getType()) !== Context::class) {
                throw new \InvalidArgumentException(\sprintf('%s::%s() must take a message and a %s', $class->name, $method->name, Context::class));
            }

            $methods[$procedure->name] = new MethodDescriptor(
                $service->name,
                $procedure->name,
                $method->name,
                self::messageClass($parameters[0]->getType(), $method),
                self::messageClass($method->getReturnType(), $method),
                $procedure->idempotency,
            );
        }

        return new self($service->name, $class->name, $methods);
    }

    /**
     * @return class-string<Message>
     */
    private static function messageClass(?\ReflectionType $type, \ReflectionMethod $method): string
    {
        $class = self::className($type);
        if ($class === null || !is_subclass_of($class, Message::class)) {
            throw new \InvalidArgumentException(\sprintf('%s::%s() must take and return protobuf messages', $method->class, $method->name));
        }

        return $class;
    }

    private static function className(?\ReflectionType $type): ?string
    {
        return $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
    }
}
