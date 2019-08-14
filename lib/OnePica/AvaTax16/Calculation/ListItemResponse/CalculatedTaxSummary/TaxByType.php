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
namespace OnePica\AvaTax16\Calculation\ListItemResponse\CalculatedTaxSummary;

use OnePica\AvaTax16\Document\Part;

/**
 * Class \OnePica\AvaTax16\Calculation\ListItemResponse\CalculatedTaxSummary\TaxByType
 *
 * @method float getTax()
 * @method setTax(float $value)
 * @method float getJurisdictions()
 * @method setJurisdictions(float $value)
 * @method string getComment()
 * @method setComment(string $value)
 */
class TaxByType extends Part
{
    /**
     * Types of complex properties
     *
     * @var array
     */
    protected $propertyComplexTypes = array(
        'jurisdictions' => array(
            'type' => '\OnePica\AvaTax16\Calculation\ListItemResponse\CalculatedTaxSummary\TaxByType\Details'
        )
    );

    /**
     * Tax
     *
     * @var float
     */
    protected $tax;

    /**
     * jurisdictions
     *
     * @var \OnePica\AvaTax16\Calculation\ListItemResponse\CalculatedTaxSummary\TaxByType\Details
     */
    protected $jurisdictions;

    /**
     * Comment
     *
     * @var string
     */
    protected $comment;
}
