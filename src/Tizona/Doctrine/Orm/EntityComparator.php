<?php

namespace Tizona\Doctrine\Orm;

/**
 * Default comparator for properties with natural order.
 *
 * Immutable.
 *
 * @author Alexey Shockov <alexey@shockov.com>
 */
class EntityComparator
{
    const ORDER_ASC  = 'asc';
    const ORDER_DESC = 'desc';

    /**
     * @var \ReflectionClass
     */
    private $class;

    private $properties;

    private $order;

    /**
     * @param ReflectionClass|string $class
     * @param array                  $properties {name: type} pairs. Available types: string, date, number.
     * @param array                  $order
     */
    public function __construct($class, array $properties, array $order = array())
    {
        // TODO More validation?
        if (!is_object($class)) {
            $class = new \ReflectionClass($class);
        }

        $this->class = $class;

        foreach (array_keys($order) as $property) {
            if (!isset($properties[$property])) {
                throw new \InvalidArgumentException('Unknown property.');
            }
        }

        $this->properties = $properties;
        $this->order      = $order;
    }

    /**
     * Compares two entities.
     *
     * @throws \InvalidArgumentException If entities can not be compared.
     *
     * @param mixed $entity1
     * @param mixed $entity2
     *
     * @return int
     */
    public function __invoke($entity1, $entity2)
    {
        if (!$this->class->isInstance($entity1) || !$this->class->isInstance($entity2)) {
            throw new \InvalidArgumentException();
        }

        $result = 0;
        foreach ($this->order as $property => $order) {
            $property1 = $entity1->{'get'.ucfirst($property)}();
            $property2 = $entity2->{'get'.ucfirst($property)}();

            $result = $this->{'compare'.ucfirst($this->properties[$property]).'s'}($property1, $property2);

            if (self::ORDER_DESC == $order) {
                $result = -$result;
            }

            // Unless first success comparision.
            if ($result) {
                break;
            }
        }

        return $result;
    }

    /**
     * Updates Doctrine's query builder for this comparator.
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param string                     $alias
     */
    public function updateQb(\Doctrine\ORM\QueryBuilder $queryBuilder, $alias)
    {
        // Clear ORDER BY, multiple comparators not allowed.
        $queryBuilder->add('orderBy', '');

        foreach ($this->order as $property => $order) {
            $queryBuilder->add('orderBy', $alias.'.'.$property.' '.$order, true);
        }
    }

    /**
     * Default values (NULL) for allowing NULLs.
     */
    private function compareDates(\DateTime $date1 = null, \DateTime $date2 = null)
    {
        if ($date1 == $date2) {
            return 0;
        } else {
            if ($date1 > $date2) {
                return 1;
            } else {
                return -1;
            }
        }
    }

    private function compareStrings($string1 = null, $string2 = null)
    {
        return strcmp($string1, $string2);
    }

    private function compareNumbers($number1 = null, $number2 = null)
    {
        return ($number1 - $number2);
    }
}
