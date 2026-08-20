<?php
declare(strict_types=1);

namespace TestApp\Model\MongoBehavior;

use Crustum\Mongo\ODM\Behavior;
use ReflectionClass;
use ReflectionMethod;
use function lcfirst;
use function str_starts_with;
use function substr;

class Test3Behavior extends Behavior
{
    /**
     * Test for event bindings.
     */
    public function beforeFind(): void
    {
    }

    /**
     * Test finder
     */
    public function findFoo(): void
    {
    }

    /**
     * Test method
     */
    public function doSomething(): void
    {
    }

    /**
     * Test method to ensure it is ignored as a callable method.
     */
    #[\Override]
    public function verifyConfig(): void
    {
        parent::verifyConfig();
    }

    /**
     * implementedEvents
     *
     * This class does pretend to implement beforeFind
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function implementedEvents(): array
    {
        return ['Model.beforeFind' => 'beforeFind'];
    }

    /**
     * implementedFinders
     *
     * @return array<string, string>
     */
    #[\Override]
    public function implementedFinders(): array
    {
        return [];
    }

    /**
     * implementedMethods
     *
     * @return array<string, string>
     */
    #[\Override]
    public function implementedMethods(): array
    {
        return [];
    }

    /**
     * Expose the reflection-derived finder map for testing.
     *
     * Since this is public - it'll show up as callable which is a side-effect
     *
     * @return array<string, string>
     */
    public function testReflectionCache(): array
    {
        $events = [];
        foreach ($this->implementedEvents() as $binding) {
            $events[] = is_array($binding) ? $binding['callable'] : $binding;
        }

        $baseMethods = get_class_methods(Behavior::class);
        $finders = [];
        foreach ((new ReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!in_array($name, $baseMethods, true) && !in_array($name, $events, true) && str_starts_with($name, 'find')) {
                $finders[lcfirst(substr($name, 4))] = $name;
            }
        }

        return $finders;
    }
}
