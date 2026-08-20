<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Id;

use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * Sequential counter identifier generator.
 *
 * Generates human-friendly sequential integer ids by atomically `$inc`-ing a
 * counter document in a dedicated counter collection. `_id` of the counter
 * document defaults to `$key` (typically the target collection name), the
 * counter value lives in `$counterField`.
 *
 * @inspired-by \Doctrine\ODM\MongoDB\Id\IncrementGenerator
 */
class IncrementGenerator implements IdGeneratorInterface
{
    /**
     * The counter collection.
     *
     * @var \MongoDB\Collection
     */
    protected Collection $counterCollection;

    /**
     * The counter key (the counter document `_id`).
     *
     * @var string
     */
    protected string $key;

    /**
     * The field holding the counter value.
     *
     * @var string
     */
    protected string $counterField;

    /**
     * The initial counter value for a fresh counter document.
     *
     * @var int
     */
    protected int $initial;

    /**
     * Constructor
     *
     * @param \MongoDB\Collection $counterCollection The counter collection
     * @param string $key The counter key, defaults to the counter collection name
     * @param string $counterField The counter field name
     * @param int $initial The initial counter value
     */
    public function __construct(
        Collection $counterCollection,
        string $key,
        string $counterField = 'value',
        int $initial = 0,
    ) {
        $this->counterCollection = $counterCollection;
        $this->key = $key;
        $this->counterField = $counterField;
        $this->initial = $initial;
    }

    /**
     * Returns the next sequential id.
     *
     * Seeds the counter document (if missing) then atomically increments it
     * and returns the new value.
     *
     * @param object|array<string, mixed>|null $document The document being inserted (unused).
     * @return int The next counter value.
     */
    public function generate(array|object|null $document = null): int
    {
        $this->seed();

        $result = $this->counterCollection->findOneAndUpdate(
            ['_id' => $this->key],
            ['$inc' => [$this->counterField => 1]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );

        if ($result === null) {
            return $this->initial + 1;
        }

        $result = (array)$result;

        return (int)($result[$this->counterField] ?? $this->initial + 1);
    }

    /**
     * Ensures the counter document exists, seeded with the initial value.
     *
     * Seeding and incrementing are separate operations because MongoDB does
     * not allow `$inc` and `$setOnInsert` to touch the same path in one update.
     *
     * @return void
     */
    protected function seed(): void
    {
        $this->counterCollection->updateOne(
            ['_id' => $this->key],
            ['$setOnInsert' => [$this->counterField => $this->initial]],
            ['upsert' => true],
        );
    }
}
