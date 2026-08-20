<?php
declare(strict_types=1);

namespace Crustum\Mongo\ODM\Association;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\ODM\Association;

/**
 * Helper class for cascading deletes in associations.
 *
 * Applies the association finder and conditions when building the delete /
 * nullify filter, and honors `cascadeCallbacks` by deleting each related
 * document through the collection (firing events) instead of a bulk delete.
 *
 * @internal
 * @ported-from \Cake\ORM\Association\DependentDeleteHelper
 */
class DependentDeleteHelper
{
    /**
     * Cascade a delete to remove dependent records.
     *
     * This method does nothing if the association is not dependent.
     *
     * @param \Crustum\Mongo\ODM\Association $association The association callbacks are being cascaded on.
     * @param \Cake\Datasource\EntityInterface $document The entity that started the cascaded delete.
     * @param array<string, mixed> $options The options for the original delete.
     * @return bool Success.
     */
    public function cascadeDelete(Association $association, EntityInterface $document, array $options = []): bool
    {
        if (!$association->getDependent()) {
            return true;
        }

        $collection = $association->getTarget();

        $foreignKey = array_map(
            $collection->aliasField(...),
            array_values(array_filter((array)$association->getForeignKey(), is_string(...))),
        );
        $bindingKey = (array)$association->getBindingKey();
        $bindingValue = $document->extract($bindingKey);
        if (in_array(null, $bindingValue, true)) {
            return true;
        }

        $conditions = array_combine($foreignKey, $bindingValue);

        if ($association->getCascadeCallbacks()) {
            foreach ($association->find()->where($conditions)->toArray() as $related) {
                $success = $collection->delete($related, $options);
                if (!$success) {
                    return false;
                }
            }

            return true;
        }

        $association->deleteAll($conditions);

        return true;
    }
}
