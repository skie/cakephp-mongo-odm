<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM;

use Cake\Datasource\ResultSetInterface;
use Cake\ORM\DtoMapper;
use Closure;
use InvalidArgumentException;
use MongoDB\Model\BSONDocument;

/**
 * Creates hydrated ODM result sets from Mongo rows.
 *
 * Aligned with the Elastica-style thin result set: hydration is row-by-row and
 * lazy, `__debugInfo` on the result set reports count + items, and DTO
 * projection is routed through cached hydrator closures.
 *
 * Unlike cake60's SQL `ResultSetFactory`, there is no `Alias__field` column
 * nesting: Mongo rows are already nested. Association deconstruction
 * (1-1 / 1-N / N-N) is handled by `EagerLoader::loadExternal()`, not here.
 *
 * @see cake60/src/ORM/ResultSetFactory.php (API surface only)
 * @see elastic-search/src/ResultSet.php (simpler sibling)
 * @template T of array|\Crustum\Mongo\ODM\Document
 */
final class ResultSetFactory
{
    /**
     * The entity class used for hydrated rows.
     *
     * @var class-string<\Crustum\Mongo\ODM\Document>
     */
    private readonly string $documentClass;

    /**
     * The result set class to wrap hydrated rows in.
     *
     * @var class-string<\Cake\Datasource\ResultSetInterface<array-key, mixed>>
     */
    protected string $resultSetClass = ResultSet::class;

    /**
     * Cached DtoMapper instance.
     *
     * @var \Cake\ORM\DtoMapper|null
     */
    protected ?DtoMapper $dtoMapper = null;

    /**
     * Cached DTO hydrator closures by class name.
     *
     * @var array<class-string, \Closure(array<string, mixed>): object>
     */
    protected static array $dtoHydrators = [];

    /**
     * Constructor.
     *
     * @param class-string<\Crustum\Mongo\ODM\Document> $documentClass Entity class for hydration.
     */
    public function __construct(string $documentClass = Document::class)
    {
        $this->documentClass = $documentClass;
    }

    /**
     * Creates a result set instance.
     *
     * @param iterable<array-key, mixed> $results
     * @param array{hydrate?: bool, source?: string|null} $options
     * @return \Cake\Datasource\ResultSetInterface<array-key, mixed>
     */
    public function createResultSet(iterable $results, array $options = []): ResultSetInterface
    {
        $options += ['hydrate' => true, 'source' => null];

        if (!$options['hydrate']) {
            $rows = [];
            foreach ($results as $key => $row) {
                $rows[$key] = $row;
            }

            return new $this->resultSetClass($rows);
        }

        $hydrated = [];
        foreach ($results as $key => $row) {
            $hydrated[$key] = $this->hydrateValue($row, $options['source']);
        }

        return new $this->resultSetClass($hydrated);
    }

    /**
     * Hydrates associative embedded rows while retaining scalar and list fields.
     *
     * @param mixed $value The value to hydrate.
     * @param string|null $source The source alias for hydrated documents.
     * @return mixed The hydrated value.
     */
    private function hydrateValue(mixed $value, ?string $source): mixed
    {
        if ($value instanceof Document) {
            return $value;
        }

        if ($value instanceof BSONDocument) {
            $value = $value->getArrayCopy();
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->hydrateValue($item, $source);
            }

            return $value;
        }

        $data = [];
        foreach ($value as $key => $item) {
            $data[$key] = $this->hydrateValue($item, $source);
        }

        $documentClass = $this->documentClass;

        return new $documentClass($data, [
            'markClean' => true,
            'markNew' => false,
            'source' => $source,
        ]);
    }

    /**
     * Hydrate a row into a DTO.
     *
     * Supports two patterns:
     * - Static `createFromArray($data, $nested)` factory method (cakephp-dto style)
     * - Constructor with named parameters (DtoMapper reflection)
     *
     * @param array<string, mixed> $row Nested array data
     * @param class-string $dtoClass DTO class name
     * @return object
     */
    public function hydrateDto(array $row, string $dtoClass): object
    {
        return $this->getDtoHydrator($dtoClass)($row);
    }

    /**
     * Get a cached hydrator closure for a DTO class.
     *
     * @param class-string $dtoClass DTO class name
     * @return \Closure(array<string, mixed>): object
     */
    public function getDtoHydrator(string $dtoClass): Closure
    {
        if (!isset(self::$dtoHydrators[$dtoClass])) {
            if (method_exists($dtoClass, 'createFromArray')) {
                self::$dtoHydrators[$dtoClass] = (static fn(array $row): object => $dtoClass::createFromArray($row, true));
            } else {
                $mapper = $this->getDtoMapper();
                self::$dtoHydrators[$dtoClass] = (static fn(array $row): object => $mapper->map($row, $dtoClass));
            }
        }

        return self::$dtoHydrators[$dtoClass];
    }

    /**
     * Clears the DTO hydrator cache.
     *
     * @return void
     */
    public static function clearDtoHydratorCache(): void
    {
        self::$dtoHydrators = [];
    }

    /**
     * Get or create the DtoMapper instance.
     *
     * @return \Cake\ORM\DtoMapper
     */
    public function getDtoMapper(): DtoMapper
    {
        return $this->dtoMapper ??= new DtoMapper();
    }

    /**
     * Sets the result set class to use.
     *
     * @param class-string $resultSetClass Class name.
     * @return $this
     */
    public function setResultSetClass(string $resultSetClass): static
    {
        if (!is_subclass_of($resultSetClass, ResultSetInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ResultSet class `%s`. It must implement `%s`',
                $resultSetClass,
                ResultSetInterface::class,
            ));
        }

        $this->resultSetClass = $resultSetClass;

        return $this;
    }

    /**
     * Gets the result set class to use.
     *
     * @return class-string<\Cake\Datasource\ResultSetInterface<array-key, mixed>>
     */
    public function getResultSetClass(): string
    {
        return $this->resultSetClass;
    }
}
