<?php
/**
 * Mondido
 *
 * PHP version 5.6
 *
 * @category Mondido
 * @package  Mondido_Mondido
 * @author   Andreas Karlsson <andreas@kodbruket.se>
 * @license  MIT License https://opensource.org/licenses/MIT
 * @link     https://www.mondido.com
 */

namespace Mondido\Mondido\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Customer\Model\AddressFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Message\ManagerInterface;
use Mondido\Mondido\Model\Api\Transaction;

/**
 * Checkout predispatch observer
 *
 * @category Mondido
 * @package  Mondido_Mondido
 * @author   Andreas Karlsson <andreas@kodbruket.se>
 * @license  MIT License https://opensource.org/licenses/MIT
 * @link     https://www.mondido.com
 */
class CheckoutPredispatchObserver implements ObserverInterface
{
    protected $transaction;
    protected $messageManager;
    protected $addressFactory;
    protected $scopeConfig;

    /**
     * Constructor
     *
     * @param \Mondido\Mondido\Model\Api\Transaction             $transaction    Transaction API model
     * @param \Magento\Framework\Message\ManagerInterface        $messageManager Message manager
     * @param \Magento\Customer\Model\AddressFactory             $addressFactory Cusomter address factory
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig    Scope config
     *
     * @return void
     */
    public function __construct(
        Transaction $transaction,
        ManagerInterface $messageManager,
        AddressFactory $addressFactory,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->transaction = $transaction;
        $this->messageManager = $messageManager;
        $this->addressFactory = $addressFactory;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Execute
     *
     * @param \Magento\Framework\Event\Observer $observer Observer object
     *
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $quote = $observer->getEvent()->getControllerAction()->getOnepage()->getQuote();

        $customer = $quote->getCustomer();

        $allowedCountries = explode(',', $this->scopeConfig->getValue('general/country/allow'));
        $defaultCountry = $this->scopeConfig->getValue('general/country/default');

        $forceDefaultCountry = true;

        if ($customer->getId()) {
            $addressId = $customer->getDefaultShipping();
            $address = $this->addressFactory->create()->load($addressId)->getDataModel();

            $quote->getShippingAddress()->importCustomerAddressData($address);
            $quote->getBillingAddress()->importCustomerAddressData($address);

            if (in_array($address->getCountryId(), $allowedCountries)) {
                $forceDefaultCountry = false;
            }
        }

        $shippingAddress = $quote->getShippingAddress('shipping');

        if ($forceDefaultCountry == true) {
            if (!$shippingAddress->getCountryId()) {
                $shippingAddress->setCountryId($defaultCountry)->save();
            }
        }

        if (!$shippingAddress->getShippingMethod()) {
            $shippingAddress->setCollectShippingRates(true)
                ->collectShippingRates()
                ->setShippingMethod('flatrate_flatrate');
            $quote->collectTotals()->save();
        }

        if ($quote->getId()) {
            if (!$quote->getMondidoTransaction()) {
                $response = $this->transaction->create($quote);
            } else {
                $response = $this->transaction->update($quote);
            }

            if ($response) {
                $data = json_decode($response);

                if (property_exists($data, 'id')) {
                    $quote->setMondidoTransaction($response);
                    $quote->save();
                } else {
                    $message = sprintf(
                        __("Mondido returned error code %d: %s (%s)"),
                        $data->code,
                        $data->description,
                        $data->name
                    );

                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $request = $objectManager->get('Magento\Framework\App\Request\Http');
                    $urlInterface = $objectManager->get('Magento\Framework\UrlInterface');
                    $url = $urlInterface->getUrl('checkout/cart');

                    $this->messageManager->addError(__($message));

                    $observer->getControllerAction()
                         ->getResponse()
                         ->setRedirect($url);
                }
            }
        }
    }
}
