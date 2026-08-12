<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Locator;

use Cake\Core\App;
use Cake\Datasource\FactoryLocator;
use Cake\ORM\Table;
use Crustum\Mongo\ODM\BaseCollection;
use UnexpectedValueException;

/**
 * Collection-aware locator for controllers.
 *
 * Resolves `$this->Authors` / `fetchCollection('Authors')` through the
 * `CollectionLocator` when a matching `App\Model\Collection\*Collection`
 * class exists, falling back to the ORM TableLocator for SQL models.
 *
 * ```php
 * class AppController extends Controller
 * {
 *     use CollectionAwareTrait;
 * }
 * ```
 */
trait CollectionAwareTrait
{
    /**
     * Resolves a collection/table alias to its repository.
     *
     * @param string|null $alias Collection alias.
     * @param array<string, mixed> $options Options for the locator.
     * @return \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Table
     */
    public function fetchCollection(?string $alias = null, array $options = []): BaseCollection|Table
    {
        $alias ??= $this->defaultTable;
        if (!$alias) {
            throw new UnexpectedValueException(
                'You must provide an `$alias` or set the `$defaultTable` property to a non empty string.',
            );
        }

        $className = App::className($alias, 'Model/Collection', 'Collection');
        if ($className !== null && is_subclass_of($className, BaseCollection::class)) {
            return FactoryLocator::get('Collection')->get($alias, $options);
        }

        return $this->getTableLocator()->get($alias, $options);
    }

    /**
     * Magic accessor for collections, tables and components.
     *
     * Resolves any property whose `App\Model\Collection\*Collection` class
     * exists via the CollectionLocator; otherwise defers to the ORM table
     * locator and components, mirroring `Controller::__get()`.
     *
     * @param string $name Property name.
     * @return \Crustum\Mongo\ODM\BaseCollection|\Cake\ORM\Table|\Cake\Controller\Component|null
     */
    public function __get(string $name): mixed
    {
        $className = App::className($name, 'Model/Collection', 'Collection');
        if ($className !== null && is_subclass_of($className, BaseCollection::class)) {
            return FactoryLocator::get('Collection')->get($name);
        }

        return parent::__get($name);
    }
}
