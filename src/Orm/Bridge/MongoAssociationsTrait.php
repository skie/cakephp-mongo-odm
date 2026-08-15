<?php
declare(strict_types=1);

namespace Crustum\Mongo\Orm\Bridge;

use Cake\Datasource\EntityInterface;
use Crustum\Mongo\Orm\Bridge\Proxy\BelongsToManyProxy;
use Crustum\Mongo\Orm\Bridge\Proxy\BelongsToProxy;
use Crustum\Mongo\Orm\Bridge\Proxy\DBRefProxy;
use Crustum\Mongo\Orm\Bridge\Proxy\HasManyProxy;
use Crustum\Mongo\Orm\Bridge\Proxy\HasOneProxy;
use Throwable;

/**
 * Declarative cross-boundary associations + two-phase save for an ORM Table.
 *
 * Add `use MongoAssociationsTrait;` to a `Cake\ORM\Table` to register Mongo
 * bridge associations and drive their two-phase save:
 *
 * ```
 * $this->mongoBelongsTo('Authors', ['property' => 'author']);
 * $this->mongoHasMany('Posts', ['foreignKey' => 'order_id']);
 *
 * $this->saveWithBridge($entity, ['associate' => ['posts']]);
 * $this->patchWithBridge($entity, $data);
 * ```
 *
 * The `mongo*()` methods create a `Crustum\Mongo\Orm\Bridge\Association` plus
 * the matching `Proxy\*` cake association registered on `Table->associations()`
 * so `contain()` and property access behave natively.
 *
 * @see docs/reference/29-orm-mongo-association-bridge.md §6, §9
 */
trait MongoAssociationsTrait
{
    /**
     * Registers a Mongo BelongsTo bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Bridge association options.
     * @return \Crustum\Mongo\Orm\Bridge\Association
     */
    public function mongoBelongsTo(string $name, array $options = []): Association
    {
        $bridge = new BelongsTo($name, $this, $options);
        $this->associations()->add($name, new BelongsToProxy($name, $this, $bridge));

        return $bridge;
    }

    /**
     * Registers a Mongo HasOne bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Bridge association options.
     * @return \Crustum\Mongo\Orm\Bridge\Association
     */
    public function mongoHasOne(string $name, array $options = []): Association
    {
        $bridge = new HasOne($name, $this, $options);
        $this->associations()->add($name, new HasOneProxy($name, $this, $bridge));

        return $bridge;
    }

    /**
     * Registers a Mongo HasMany bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Bridge association options.
     * @return \Crustum\Mongo\Orm\Bridge\Association
     */
    public function mongoHasMany(string $name, array $options = []): Association
    {
        $bridge = new HasMany($name, $this, $options);
        $this->associations()->add($name, new HasManyProxy($name, $this, $bridge));

        return $bridge;
    }

    /**
     * Registers a Mongo BelongsToMany bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Bridge association options.
     * @return \Crustum\Mongo\Orm\Bridge\Association
     */
    public function mongoBelongsToMany(string $name, array $options = []): Association
    {
        $bridge = new BelongsToMany($name, $this, $options);
        $this->associations()->add($name, new BelongsToManyProxy($name, $this, $bridge));

        return $bridge;
    }

    /**
     * Registers a Mongo DBRef bridge association.
     *
     * @param string $name Association alias.
     * @param array<string, mixed> $options Bridge association options.
     * @return \Crustum\Mongo\Orm\Bridge\Association
     */
    public function mongoDbref(string $name, array $options = []): Association
    {
        $bridge = new DBRef($name, $this, $options);
        $this->associations()->add($name, new DBRefProxy($name, $this, $bridge));

        return $bridge;
    }

    /**
     * Patches an entity without ORM-marshalling Mongo bridge properties.
     *
     * Strips cross-association properties from the form data, patches the SQL
     * side via `patchEntity()`, then re-applies the stripped values as dirty
     * entity properties (mirrors zulucare `patchEntityWithFiles()`).
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to patch.
     * @param array<string, mixed> $data Form data.
     * @param array<string, mixed> $options patchEntity() options.
     * @return \Cake\Datasource\EntityInterface
     */
    public function patchWithBridge(EntityInterface $entity, array $data, array $options = []): EntityInterface
    {
        $bridgeData = [];
        foreach ($this->getBridgeAssociations() as $association) {
            $property = $association->getProperty();
            if (array_key_exists($property, $data)) {
                $bridgeData[$property] = $data[$property];
                unset($data[$property]);
            }
        }

        $options += ['associated' => []];
        $entity = $this->patchEntity($entity, $data, $options);
        foreach ($bridgeData as $property => $value) {
            $entity->set($property, $value);
            $entity->setDirty($property, true);
        }

        return $entity;
    }

    /**
     * Two-phase save: SQL first, then Mongo bridge writes.
     *
     * 1. Saves the ORM entity inside a transaction (no associated marshalling).
     * 2. Collects dirty cross-association properties.
     * 3. Persists each to its target Mongo collection.
     * 4. Rolls back the SQL save on Mongo failure (best effort) and reports
     *    errors on the entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to save.
     * @param array<string, mixed> $options Save options. `associate` lists the
     *   bridge properties to persist (defaults to all dirty ones).
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function saveWithBridge(EntityInterface $entity, array $options = []): EntityInterface|false
    {
        $isNew = $entity->isNew();
        $options['atomic'] = false;
        $options['associated'] = false;

        $bridgeDirty = [];
        foreach ($this->getBridgeAssociations() as $association) {
            $property = $association->getProperty();
            if (!$entity->isDirty($property)) {
                continue;
            }

            $value = $entity->get($property);
            if ($value === null) {
                continue;
            }

            if ($value === []) {
                continue;
            }

            $bridgeDirty[$property] = $value;
        }

        $saved = $this->getConnection()->transactional(function () use ($entity, $options, $bridgeDirty) {
            $savedEntity = $this->save($entity, $options);
            if (!$savedEntity) {
                return false;
            }

            $errors = [];
            foreach ($this->getBridgeAssociations() as $association) {
                $property = $association->getProperty();
                if (!array_key_exists($property, $bridgeDirty)) {
                    continue;
                }

                try {
                    $association->save($entity, $bridgeDirty[$property]);
                } catch (Throwable $e) {
                    $errors[$property] = $e->getMessage();
                }
            }

            if ($errors !== []) {
                $entity->setErrors($errors);

                return false;
            }

            return $savedEntity;
        });

        if ($isNew && !$saved) {
            $entity->setNew(true);
        }

        return $saved;
    }

    /**
     * Deletes the SQL row and cascades to dependent Mongo documents.
     *
     * Runs inside a SQL transaction; each bridge association with
     * `dependent` enabled cascades its target documents (doc 29 §10).
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to delete.
     * @param array<string, mixed> $options Delete options.
     * @return bool Whether the delete succeeded.
     */
    public function deleteWithBridge(EntityInterface $entity, array $options = []): bool
    {
        $options['atomic'] = false;

        return $this->getConnection()->transactional(function () use ($entity, $options) {
            $errors = [];
            foreach ($this->getBridgeAssociations() as $association) {
                if (!$association->getDependent()) {
                    continue;
                }

                if (!$association->cascadeDelete($entity, $options)) {
                    $errors[] = $association->getProperty();
                }
            }

            if ($errors !== []) {
                $entity->setError('_bridge', sprintf(
                    'Cascade delete failed for: %s',
                    implode(', ', $errors),
                ));

                return false;
            }

            return $this->delete($entity, $options);
        });
    }

    /**
     * Lists the bridge associations registered on this table.
     *
     * A bridge association is a `Proxy\*` cake association exposing
     * `getBridge()`.
     *
     * @return list<\Crustum\Mongo\Orm\Bridge\Association>
     */
    protected function getBridgeAssociations(): array
    {
        $associations = [];
        foreach ($this->associations() as $association) {
            if (
                method_exists($association, 'getBridge')
                && $association->getBridge() instanceof Association
            ) {
                $associations[] = $association->getBridge();
            }
        }

        return $associations;
    }
}
