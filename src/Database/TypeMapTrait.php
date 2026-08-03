<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database;

/**
 * Trait TypeMapTrait
 */
trait TypeMapTrait
{
    /**
     * @var \Crustum\Mongo\Database\TypeMap|null
     */
    protected ?TypeMap $_typeMap = null;

    /**
     * Creates a new TypeMap if $typeMap is an array, otherwise exchanges it for the given one.
     *
     * @param \Crustum\Mongo\Database\TypeMap|array $typeMap Creates a TypeMap if array, otherwise sets the given TypeMap
     * @return $this
     */
    public function setTypeMap(TypeMap|array $typeMap)
    {
        $this->_typeMap = is_array($typeMap) ? new TypeMap($typeMap) : $typeMap;

        return $this;
    }

    /**
     * Returns the existing type map.
     *
     * @return \Crustum\Mongo\Database\TypeMap
     */
    public function getTypeMap(): TypeMap
    {
        return $this->_typeMap ??= new TypeMap();
    }

    /**
     * Overwrite the default type mappings for fields
     * in the implementing object.
     *
     * This method is useful if you need to set type mappings that are shared across
     * multiple functions/expressions in a query.
     *
     * To add a default without overwriting existing ones
     * use `getTypeMap()->addDefaults()`
     *
     * @param array<int|string, string> $types The array of types to set.
     * @return $this
     * @see \Crustum\Mongo\Database\TypeMap::setDefaults()
     */
    public function setDefaultTypes(array $types)
    {
        $this->getTypeMap()->setDefaults($types);

        return $this;
    }

    /**
     * Gets default types of current type map.
     *
     * @return array<int|string, string>
     */
    public function getDefaultTypes(): array
    {
        return $this->getTypeMap()->getDefaults();
    }
}
