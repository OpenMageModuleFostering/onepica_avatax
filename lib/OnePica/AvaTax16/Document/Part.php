<?php
/**
 * OnePica
 * NOTICE OF LICENSE
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to codemaster@onepica.com so we can send you a copy immediately.
 *
 * @category  OnePica
 * @package   OnePica_AvaTax16
 * @copyright Copyright (c) 2016 One Pica, Inc. (http://www.onepica.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace OnePica\AvaTax16\Document;

/**
 * Class \OnePica\AvaTax16\Document\Part
 */
class Part
{
    /**
     * Required properties
     *
     * @var array
     */
    protected $requiredProperties = array();

    /**
     * Excluded properties (will be ignored during toArray function)
     *
     * @var array
     */
    protected $excludedProperties = array();

    /**
     * Types of complex properties
     *
     * @var array
     */
    protected $propertyComplexTypes = array();

    /**
     * Properties get and set methods
     */
    public function __call($name, $arguments)
    {
        $action = substr($name, 0, 3);
        switch ($action) {
            case 'get':
                $property = lcfirst(substr($name, 3));
                if (property_exists($this, $property)) {
                    return $this->{$property};
                } else {
                    $this->throwWrongMethodErrorException($name);
                }
                break;
            case 'set':
                $property = lcfirst(substr($name, 3));
                if (property_exists($this, $property)) {
                    $this->{$property} = $arguments[0];
                } else {
                    $this->throwWrongMethodErrorException($name);
                }
                break;
            default :
                $this->throwWrongMethodErrorException($name);
        }
    }

    /**
     * Throw Wrong Method Error Exception
     *
     * @param string $methodName
     * @throws \OnePica\AvaTax16\Exception
     */
    protected function throwWrongMethodErrorException($methodName)
    {
        $trace = debug_backtrace();
        $errorMessage = 'Undefined method  '
                      . $methodName
                      . ' in '
                      . $trace[0]['file']
                      . ' on line '
                      . $trace[0]['line'];
        throw new \OnePica\AvaTax16\Exception($errorMessage);
    }

    /**
     * Checks if document part is valid
     *
     * @return bool
     */
    public function isValid()
    {
        foreach ($this as $key => $value) {
            if (in_array($key, $this->requiredProperties) && (null === $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert object data to array
     *
     * @return array
     * @throws \OnePica\AvaTax16\Exception
     */
    public function toArray()
    {
        if (!$this->isValid()) {
            throw new \OnePica\AvaTax16\Exception("Not valid data in " . get_class($this));
        }
        $result = array();
        foreach ($this as $key => $value) {
            if (in_array($key, $this->excludedProperties)
                || in_array($key, array('requiredProperties', 'excludedProperties', 'propertyComplexTypes'))
                || (null === $value)) {
                // skip property
                continue;
            }
            $name = $key;
            $result[$name] = $this->proceedToArrayItem($value);
        }
        return $result;
    }

    /**
     * Convert object data to array
     *
     * @param \OnePica\AvaTax16\Document\Part|array|string $item
     * @return array|string
     */
    protected function proceedToArrayItem($item)
    {
        $result = null;
        $itemType = ($item instanceof Part) ? 'documentPart' :
                ((is_array($item)) ? 'array' : 'simple');

        switch ($itemType) {
            case 'documentPart':
                $result = $item->toArray();
                break;
            case 'array':
                foreach ($item as $key => $value) {
                    $result[$key] = $this->proceedToArrayItem($value);
                }
                break;
            case 'simple':
                $result = (string) $item;
                break;
        }

        return $result;
    }

    /**
     * Fill data from object
     *
     * @param \StdClass|array $data
     * @return $this
     */
    public function fillData($data)
    {
        foreach ($data as $key => $value) {
            $propName = $key;
            $method = 'set' . ucfirst($key);
            if (!property_exists($this, $propName)) {
                // skip unknown property received from response to prevent error
                continue;
            }
            if (isset($this->propertyComplexTypes[$propName])) {
                $propertyType = $this->propertyComplexTypes[$propName]['type'];
                if (isset($this->propertyComplexTypes[$propName]['isArrayOf'])) {
                    $items = array();
                    if (count($value) > 0) {
                        foreach ($value as $itemKey => $itemData) {
                            $item = $this->createItemAndFillData($propertyType, $itemData);
                            $items[$itemKey] = $item;
                        }
                    }
                    $this->$method($items);
                } else {
                    $item = $value ? $this->createItemAndFillData($propertyType, $value) : null;
                    $this->$method($item);
                }
            } else {
                $this->$method($value);
            }
        }
        return $this;
    }

    /**
     * Create item object and fill data in it
     *
     * @param string $itemClassName
     * @param \StdClass|array $data
     * @return object $item
     */
    protected function createItemAndFillData($itemClassName, $data)
    {
        $item = new $itemClassName();
        $item->fillData($data);
        return $item;
    }
}
