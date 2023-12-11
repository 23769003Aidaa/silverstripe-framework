<?php

namespace SilverStripe\ORM;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use InvalidArgumentException;
use SilverStripe\Dev\Deprecation;
use Traversable;

/**
 * Represents a has_many list linked against a polymorphic relationship.
 */
class PolymorphicHasManyList extends HasManyList
{

    /**
     * Name of foreign key field that references the class name of the relation
     *
     * @var string
     */
    protected $classForeignKey;

    /**
     * Name of the foreign key field that references the relation name, for has_one
     * relations that can handle multiple reciprocal has_many relations.
     */
    protected string $relationForeignKey;

    /**
     * Retrieve the name of the class this (has_many) relation is filtered by
     *
     * @return string
     */
    public function getForeignClass()
    {
        return $this->dataQuery->getQueryParam('Foreign.Class');
    }

    /**
     * Retrieve the name of the has_many relation this list is filtered by
     */
    public function getForeignRelation(): ?string
    {
        return $this->dataQuery->getQueryParam('Foreign.Relation');
    }

    /**
     * Retrieve the name of the has_many relation this list is filtered by
     *
     * @deprecated 5.2.0 Will be replaced with a parameter in the constructor
     */
    public function setForeignRelation(string $relationName): static
    {
        Deprecation::notice('5.2.0', 'Will be replaced with a parameter in the constructor');
        $this->dataQuery->where(["\"{$this->relationForeignKey}\"" => $relationName]);
        $this->dataQuery->setQueryParam('Foreign.Relation', $relationName);
        return $this;
    }

    /**
     * Gets the field name which holds the related (has_many) object class.
     */
    public function getForeignClassKey(): string
    {
        return $this->classForeignKey;
    }

    /**
     * Gets the field name which holds the has_many relation name.
     *
     * Note that this will return a value even if the has_one relation
     * doesn't support multiple reciprocal has_many relations.
     */
    public function getForeignRelationKey(): string
    {
        return $this->relationForeignKey;
    }

    /**
     * Create a new PolymorphicHasManyList relation list.
     *
     * @param string $dataClass The class of the DataObjects that this will list.
     * @param string $foreignField The name of the composite foreign (has_one) relation field. Used
     * to generate the ID, Class, and Relation foreign keys.
     * @param string $foreignClass Name of the class filter this relation is filtered against
     */
    public function __construct($dataClass, $foreignField, $foreignClass)
    {
        // Set both id foreign key (as in HasManyList) and the class foreign key
        parent::__construct($dataClass, "{$foreignField}ID");
        $this->classForeignKey = "{$foreignField}Class";
        $this->relationForeignKey = "{$foreignField}Relation";

        // Ensure underlying DataQuery globally references the class filter
        $this->dataQuery->setQueryParam('Foreign.Class', $foreignClass);

        // For queries with multiple foreign IDs (such as that generated by
        // DataList::relation) the filter must be generalised to filter by subclasses
        $classNames = Convert::raw2sql(ClassInfo::subclassesFor($foreignClass));
        $this->dataQuery->where(sprintf(
            "\"{$this->classForeignKey}\" IN ('%s')",
            implode("', '", $classNames)
        ));
    }

    /**
     * Adds the item to this relation.
     *
     * It does so by setting the relationFilters.
     *
     * @param DataObject|int $item The DataObject to be added, or its ID
     */
    public function add($item)
    {
        if (is_numeric($item)) {
            $item = DataObject::get_by_id($this->dataClass, $item);
        } elseif (!($item instanceof $this->dataClass)) {
            throw new InvalidArgumentException(
                "PolymorphicHasManyList::add() expecting a $this->dataClass object, or ID value"
            );
        }

        $foreignID = $this->getForeignID();

        // Validate foreignID
        if (!$foreignID) {
            user_error(
                "PolymorphicHasManyList::add() can't be called until a foreign ID is set",
                E_USER_WARNING
            );
            return;
        }
        if (is_array($foreignID)) {
            user_error(
                "PolymorphicHasManyList::add() can't be called on a list linked to multiple foreign IDs",
                E_USER_WARNING
            );
            return;
        }

        // set the {$relationName}Class field value
        $foreignKey = $this->foreignKey;
        $classForeignKey = $this->classForeignKey;
        $item->$foreignKey = $foreignID;
        $item->$classForeignKey = $this->getForeignClass();

        // set the {$relationName}Relation field value if appropriate
        $foreignRelation = $this->getForeignRelation();
        if ($foreignRelation) {
            $relationForeignKey = $this->getForeignRelationKey();
            $item->$relationForeignKey = $foreignRelation;
        }

        $item->write();
    }

    /**
     * Remove an item from this relation.
     * Doesn't actually remove the item, it just clears the foreign key value.
     *
     * @param DataObject $item The DataObject to be removed
     */
    public function remove($item)
    {
        if (!($item instanceof $this->dataClass)) {
            throw new InvalidArgumentException(
                "HasManyList::remove() expecting a $this->dataClass object, or ID"
            );
        }

        // Don't remove item with unrelated class key
        $foreignClass = $this->getForeignClass();
        $classNames = ClassInfo::subclassesFor($foreignClass);
        $classForeignKey = $this->classForeignKey;
        $classValueLower = strtolower($item->$classForeignKey ?? '');
        if (!array_key_exists($classValueLower, $classNames ?? [])) {
            return;
        }

        // Don't remove item with unrelated relation key
        $foreignRelation = $this->getForeignRelation();
        $relationForeignKey = $this->getForeignRelationKey();
        if (!$this->relationMatches($item->$relationForeignKey, $foreignRelation)) {
            return;
        }

        // Don't remove item which doesn't belong to this list
        $foreignID = $this->getForeignID();
        $foreignKey = $this->foreignKey;

        if (empty($foreignID)
            || $foreignID == $item->$foreignKey
            || (is_array($foreignID) && in_array($item->$foreignKey, $foreignID ?? []))
        ) {
            // Unset the foreign relation key if appropriate
            if ($foreignRelation) {
                $item->$relationForeignKey = null;
            }

            // Unset the rest of the relation and write the record
            $item->$foreignKey = null;
            $item->$classForeignKey = null;
            $item->write();
        }
    }

    private function relationMatches(?string $actual, ?string $expected): bool
    {
        return (empty($actual) && empty($expected)) || $actual === $expected;
    }
}
