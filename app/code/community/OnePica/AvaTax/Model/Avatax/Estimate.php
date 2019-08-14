<?php

/**
 * OnePica_AvaTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0), a
 * copy of which is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 * @copyright  Copyright (c) 2009 One Pica, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

/**
 * The AvaTax Address Estimate model
 *
 * @category   OnePica
 * @package    OnePica_AvaTax
 * @author     OnePica Codemaster <codemaster@onepica.com>
 */
class OnePica_AvaTax_Model_Avatax_Estimate extends OnePica_AvaTax_Model_Avatax_Abstract
{
    /**
     * Length of time in minutes for cached rates
     *
     * @var int
     */
    const CACHE_TTL = 120;

    /**
     * An array of rates that acts as a cache
     * Example: $_rates[$cachekey] = array(
     *     'timestamp' => 1325015952
     *     'summary' => array(
     *         array('name'=>'NY STATE TAX', 'rate'=>4, 'amt'=>6),
     *         array('name'=>'NY CITY TAX', 'rate'=>4.50, 'amt'=>6.75),
     *         array('name'=>'NY SPECIAL TAX', 'rate'=>4.375, 'amt'=>0.56)
     *     ),
     *     'items' => array(
     *         5 => array('rate'=>8.875, 'amt'=>13.31),
     *         'Shipping' => array('rate'=>0, 'amt'=>0)
     *     )
     * )
     *
     * @var array
     */
    protected $_rates = array();

    /**
     * An array of line items
     *
     * @var array
     */
    protected $_lines = array();

    /**
     * An array of line numbers to quote item ids
     *
     * @var array
     */
    protected $_lineToLineId = array();

    /**
     * Product gift pair
     *
     * @var array
     */
    protected $_productGiftPair = array();

    /**
     * Loads any saved rates in session
     */
    protected function _construct()
    {
        $rates = Mage::getSingleton('avatax/session')->getRates();
        if (is_array($rates)) {
            foreach ($rates as $key => $rate) {
                if ($rate['timestamp'] < $this->_getDateModel()->timestamp('-' . self::CACHE_TTL . ' minutes')) {
                    unset($rates[$key]);
                }
            }
            $this->_rates = $rates;
        }
        return parent::_construct();
    }

    /**
     * Estimates tax rate for one item.
     *
     * @param Varien_Object $item
     * @return int
     */
    public function getItemRate($item)
    {
        if ($this->isProductCalculated($item)) {
            return 0;
        } else {
            $key = $this->_getRates($item);
            $id = $item->getId();
            return isset($this->_rates[$key]['items'][$id]['rate']) ? $this->_rates[$key]['items'][$id]['rate'] : 0;
        }
    }

    /**
     * Get item tax
     *
     * @param Mage_Sales_Model_Quote_Item $item
     * @return int
     */
    public function getItemGiftTax($item)
    {
        $key = $this->_getRates($item);
        $id = $item->getId();
        return isset($this->_rates[$key]['items'][$id]['gift_tax']) ? $this->_rates[$key]['items'][$id]['gift_tax'] : 0;
    }

    /**
     * Estimates tax amount for one item. Does not trigger a call if the shipping
     * address has no postal code, or if the postal code is set to "-" (OneStepCheckout)
     *
     * @param Varien_Object $item
     * @return int
     */
    public function getItemTax($item)
    {
        if ($item->getAddress()->getPostcode() && $item->getAddress()->getPostcode() != '-') {
            if ($this->isProductCalculated($item)) {
                $tax = 0;
                foreach ($item->getChildren() as $child) {
                    $child->setAddress($item->getAddress());
                    $tax += $this->getItemTax($child);
                }
                return $tax;
            } else {
                $key = $this->_getRates($item);
                $id = $item->getId();
                return isset($this->_rates[$key]['items'][$id]['amt']) ? $this->_rates[$key]['items'][$id]['amt'] : 0;
            }
        }
        return 0;
    }

    /**
     * Get tax detail summary
     *
     * @param int|null $addressId
     * @return array
     */
    public function getSummary($addressId = null)
    {
        $summary = null;

        if ($addressId) {
            $timestamp = 0;
            foreach ($this->_rates as $row) {
                if (isset($row['address_id']) && $row['address_id'] == $addressId && $row['timestamp'] > $timestamp) {
                    $summary = $row['summary'];
                    $timestamp = $row['timestamp'];
                }
            }
        }

        if ($summary === null) {
            $requestKey = Mage::getSingleton('avatax/session')->getLastRequestKey();
            $summary = isset($this->_rates[$requestKey]['summary']) ? $this->_rates[$requestKey]['summary'] : array();
        }

        return $summary;
    }

    /**
     * Get rates from Avalara
     *
     * @param Varien_Object $item
     * @return string
     */
    protected function _getRates($item)
    {
        if (self::$_hasError) {
            return 'error';
        }

        $address = $item->getAddress();
        $this->_lines = array();

        //set up request
        $this->_request = new GetTaxRequest();
        $this->_request->setDocType(DocumentType::$SalesOrder);
        $this->_request->setDocCode('quote-' . $address->getId());
        $this->_addGeneralInfo($address);
        $this->_setOriginAddress($address->getStoreId());
        $this->_setDestinationAddress($address);
        $this->_request->setDetailLevel(DetailLevel::$Line);
        $this->_addItemsInCart($item);
        $this->_addShipping($address);
        //Added code for calculating tax for giftwrap items
        $this->_addGwOrderAmount($address);
        $this->_addGwPrintedCardAmount($address);
        //check to see if we can/need to make the request to Avalara
        $requestKey = $this->_genRequestKey();
        $makeRequest = !isset($this->_rates[$requestKey]['items'][$item->getId()]);
        //@startSkipCommitHooks
        $makeRequest &= count($this->_lineToLineId) ? true : false;
        $makeRequest &= $this->_request->getDestinationAddress() == '' ? false : true;
        $makeRequest &= $address->getId() ? true : false;
        $makeRequest &= !isset($this->_rates[$requestKey]['failure']);
        //@finishSkipCommitHooks

        //make request if needed and save results in cache
        if ($makeRequest) {
            $result = $this->_send($address->getQuote()->getStoreId());

            //success
            /** @var GetTaxResult $result */
            if ($result->getResultCode() == SeverityLevel::$Success) {
                $this->_rates[$requestKey] = array(
                    'timestamp' => $this->_getDateModel()->timestamp(),
                    'address_id' => $address->getId(),
                    'summary' => array(),
                    'items' => array()
                );

                foreach ($result->getTaxLines() as $ctl) {
                    $giftLineTax = $this->_getGiftTax($ctl, $result);
                    $id = $this->_lineToLineId[$ctl->getNo()];
                    $this->_rates[$requestKey]['items'][$id] = array(
                        'rate'     => ($ctl->getTax() ? $ctl->getRate() : 0) * 100,
                        'amt'      => $ctl->getTax(),
                        'gift_tax' => $giftLineTax
                    );
                }

                foreach ($result->getTaxSummary() as $row) {
                    $this->_rates[$requestKey]['summary'][] = array(
                        'name' => $row->getTaxName(),
                        'rate' => $row->getRate() * 100,
                        'amt' => $row->getTax()
                    );
                }
            //failure
            } else {
                $this->_rates[$requestKey] = array(
                    'timestamp'  => $this->_getDateModel()->timestamp(),
                    'address_id' => $address->getId(),
                    'summary'    => array(),
                    'items'      => array(),
                    'failure'    => true
                );
                if (Mage::helper('avatax')->fullStopOnError($address->getStoreId())) {
                    $address->getQuote()->setHasError(true);
                }
            }

            Mage::getSingleton('avatax/session')->setRates($this->_rates);
        }

        //return $requestKey so it doesn't have to be calculated again
        return $requestKey;
    }

    /**
     * Generates a hash key for the exact request
     *
     * @return string
     */
    protected function _genRequestKey()
    {
        $hash = sprintf("%u", crc32(serialize($this->_request)));
        Mage::getSingleton('avatax/session')->setLastRequestKey($hash);
        return $hash;
    }

    /**
     * Adds shipping cost to request as item
     *
     * @param Mage_Sales_Model_Quote_Address
     * @return int
     */
    protected function _addShipping($address)
    {
        $lineNumber = count($this->_lines);
        $storeId = $address->getQuote()->getStore()->getId();
        $taxClass = Mage::helper('tax')->getShippingTaxClass($storeId);
        $shippingAmount = (float) $address->getBaseShippingAmount();

        $line = new Line();
        $line->setNo($lineNumber);
        $shippingSku = Mage::helper('avatax')->getShippingSku($storeId);
        $line->setItemCode($shippingSku ? $shippingSku : 'Shipping');
        $line->setDescription('Shipping costs');
        $line->setTaxCode($taxClass);
        $line->setQty(1);
        $line->setAmount($shippingAmount);
        $line->setDiscounted(false);

        $this->_lines[$lineNumber] = $line;
        $this->_request->setLines($this->_lines);
        $this->_lineToLineId[$lineNumber] = Mage::helper('avatax')->getShippingSku($storeId);
        return $lineNumber;
    }

    /**
     * Adds giftwraporder cost to request as item
     *
     * @param Mage_Sales_Model_Quote_Address $address
     * @return int|bool
     */
    protected function _addGwOrderAmount($address)
    {
        if (!$address->getGwPrice()) {
            return false;
        }
        $lineNumber = count($this->_lines);
        $storeId = $address->getQuote()->getStore()->getId();
        //Add gift wrapping price(for entire order)
        $gwOrderAmount = $address->getGwBasePrice();

        $line = new Line();
        $line->setNo($lineNumber);
        $gwOrderSku = Mage::helper('avatax')->getGwOrderSku($storeId);
        $line->setItemCode($gwOrderSku ? $gwOrderSku : 'GwOrderAmount');
        $line->setDescription('Gift Wrap Order Amount');
        $line->setTaxCode($this->_getGiftTaxClass());
        $line->setQty(1);
        $line->setAmount($gwOrderAmount);
        $line->setDiscounted(false);

        $this->_lines[$lineNumber] = $line;
        $this->_request->setLines($this->_lines);
        $this->_lineToLineId[$lineNumber] = Mage::helper('avatax')->getGwOrderSku($storeId);
        return $lineNumber;
    }

    /**
     * Adds giftwrapitems cost to request as item
     *
     * @param Mage_Sales_Model_Quote_Item $item
     * @return int|bool
     */
    protected function _addGwItemsAmount($item)
    {
        if (!$item->getGwPrice()) {
            return false;
        }
        $lineNumber = count($this->_lines);
        $storeId = $item->getQuote()->getStoreId();
        //Add gift wrapping price(for individual items)
        $gwItemsAmount = $item->getGwBasePrice();

        $line = new Line();
        $line->setNo($lineNumber);
        $gwItemsSku = Mage::helper('avatax')->getGwItemsSku($storeId);
        $line->setItemCode($gwItemsSku ? $gwItemsSku : 'GwItemsAmount');
        $line->setDescription('Gift Wrap Items Amount');
        $line->setTaxCode($this->_getGiftTaxClass());
        $line->setQty(1);
        $line->setAmount($gwItemsAmount);
        $line->setDiscounted(false);

        $this->_lines[$lineNumber] = $line;
        $this->_request->setLines($this->_lines);
        $this->_lineToLineId[$lineNumber] = Mage::helper('avatax')->getGwItemsSku($storeId);

        return $lineNumber;
    }

     /**
     * Adds giftwrap printed card cost to request as item
     *
     * @param Mage_Sales_Model_Quote
     * @return int|bool
     */
    protected function _addGwPrintedCardAmount($address)
    {
        if (!$address->getGwPrintedCardPrice()) {
            return false;
        }
        $lineNumber = count($this->_lines);
        $storeId = $address->getQuote()->getStore()->getId();
        //Add printed card price
        $gwPrintedCardAmount = $address->getGwPrintedCardBasePrice();

        $line = new Line();
        $line->setNo($lineNumber);
        $gwPrintedCardSku = Mage::helper('avatax')->getGwPrintedCardSku($storeId);
        $line->setItemCode($gwPrintedCardSku ? $gwPrintedCardSku : 'GwPrintedCardAmount');
        $line->setDescription('Gift Wrap Printed Card Amount');
        $line->setTaxCode($this->_getGiftTaxClass());
        $line->setQty(1);
        $line->setAmount($gwPrintedCardAmount);
        $line->setDiscounted(false);

        $this->_lines[$lineNumber] = $line;
        $this->_request->setLines($this->_lines);
        $this->_lineToLineId[$lineNumber] = Mage::helper('avatax')->getGwPrintedCardSku($storeId);
        return $lineNumber;
    }

    /**
     * Adds all items in the cart to the request
     *
     * @param Mage_Sales_Model_Quote_item|Mage_Sales_Model_Quote_Address_item $item
     * @return int
     */
    protected function _addItemsInCart($item)
    {
        if ($item->getAddress() instanceof Mage_Sales_Model_Quote_Address) {
            $items = $item->getAddress()->getAllItems();
        } elseif ($item->getQuote() instanceof Mage_Sales_Model_Quote) {
            $items = $item->getQuote()->getAllItems();
        } else {
            $items = array();
        }

        if (count($items) > 0) {
            $this->_initProductCollection($items);
            $this->_initTaxClassCollection($item->getAddress());
            foreach ($items as $item) {
                $productLineNumber = $this->_newLine($item);
                if (is_int($productLineNumber)) {
                    $this->_productGiftPair[$productLineNumber] = $this->_addGwItemsAmount($item);
                }
            }
            $this->_request->setLines($this->_lines);
        }

        return count($this->_lines);
    }

    /**
     * Makes a Line object from a product item object
     *
     * @param Varien_Object|Mage_Sales_Model_Quote_Item $item
     * @return int|bool
     */
    protected function _newLine($item)
    {
        if ($this->isProductCalculated($item)) {
            return false;
        }
        $product = $this->_getProductByProductId($item->getProductId());
        $taxClass = $this->_getTaxClassByProduct($product);
        $price = $item->getBaseRowTotal() - $item->getBaseDiscountAmount();
        $lineNumber = count($this->_lines);
        $line = new Line();
        $line->setNo($lineNumber);
        $line->setItemCode(substr($item->getSku(), 0, 50));
        $line->setDescription($item->getName());
        $line->setQty($item->getQty());
        $line->setAmount($price);
        $line->setDiscounted($item->getDiscountAmount() ? true : false);
        if ($taxClass) {
            $line->setTaxCode($taxClass);
        }
        $ref1Value = $this->_getRefValueByProductAndNumber($product, 1, $item->getStoreId());
        if ($ref1Value) {
            $line->setRef1($ref1Value);
        }
        $ref2Value = $this->_getRefValueByProductAndNumber($product, 2, $item->getStoreId());
        if ($ref2Value) {
            $line->setRef2($ref2Value);
        }

        $this->_lines[$lineNumber] = $line;
        $this->_lineToLineId[$lineNumber] = $item->getId();
        return $lineNumber;
    }

    /**
     * Retrieve gift tax from line
     *
     * @param Line $ctl
     * @param GetTaxResult $result
     * @return null|float
     */
    protected function _getGiftTax($ctl, $result)
    {
        $giftLineTax = null;
        if (isset($this->_productGiftPair[$ctl->getNo()]) && is_int($this->_productGiftPair[$ctl->getNo()])) {
            $giftNo = $this->_productGiftPair[$ctl->getNo()];
            $giftLineTax = $result->getTaxLine($giftNo)->getTax();
        }

        return $giftLineTax;
    }
}
