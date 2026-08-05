<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Crustum\Mongo\ODM\Association;
use Crustum\Mongo\ODM\Document;
use InvalidArgumentException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * Base class for associations stored inside the source document.
 *
 * Embedded associations never issue a secondary query and always use the
 * `embed` loading strategy.
 *
 * @see cake60/src/ORM/Association/HasOne.php
 * @see cake60/src/ORM/Association/HasMany.php
 */
abstract class Embedded extends Association
{
    /** The only supported strategy for embedded associations. */
    /**
     * @var array<string>
     */
    protected array $validStrategies = [self::STRATEGY_EMBED];

    /**
     * Embedded associations always hydrate in the root document.
     *
     * @return string
     */
    public function getStrategy(): string
    {
        return self::STRATEGY_EMBED;
    }

    /**
     * Rejects non-embedded strategies.
     *
     * @param string $strategy Strategy name.
     * @return $this
     * @throws \InvalidArgumentException If the strategy is not `embed`.
     */
    public function setStrategy(string $strategy): static
    {
        if ($strategy !== self::STRATEGY_EMBED) {
            throw new InvalidArgumentException('Embedded associations only support the embed strategy.');
        }

        return $this;
    }

    /**
     * Converts BSON containers into PHP arrays for hydration.
     *
     * @param mixed $value Value to normalize.
     * @return mixed
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return $value;
    }

    /**
     * Hydrates one embedded document.
     *
     * @param array<string, mixed> $data Embedded data.
     * @param array<string, mixed> $options Hydration options.
     * @return \Crustum\Mongo\ODM\Document
     */
    protected function document(array $data, array $options): Document
    {
        $class = $this->getDocumentClass();
        $document = new $class($data, $options + ['markClean' => true, 'markNew' => false]);
        if (!$document instanceof Document) {
            throw new InvalidArgumentException('Embedded entity class must extend Document.');
        }

        return $document;
    }
}
