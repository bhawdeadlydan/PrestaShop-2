<?php

if (!defined('_PS_VERSION_'))
    exit;

include_once(_PS_MODULE_DIR_ . 'upg/vendor/autoload.php');

class Upg extends PaymentModule
{
    const URL_SANDBOX = 'https://www.payco-sandbox.de/2.0/';
    const URL_LIVE = 'https://www.pay-co.net/2.0/';

    const UPG_STATUS_STARTED = "UPG_STATUS_STARTED";
    const UPG_STATUS_RETURNED = "UPG_STATUS_RETURNED";
    const UPG_STATUS_ERROR = "UPG_STATUS_ERROR";

    public $active = false;

    private $_postErrors = array();

    public function __construct()
    {
        $this->name = 'upg';
        $this->tab = 'payments_gateways';
        $this->version = '0.0.2';
        $this->author = 'Upg';
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->controllers = array('payment', 'process', 'callback','recover', 'success', 'error', 'mns');
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';


        $this->need_instance = 1;
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Upg Module');
        $this->description = $this->l('Upg module.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }

    public function install()
    {
        if (!parent::install() ||
            !$this->registerHook('payment') ||
            !$this->registerHook('displayPaymentEU') ||
            !$this->registerHook('paymentReturn') ||
            !$this->registerHook('actionPaymentCCAdd') ||
            !$this->registerHook('displayAdminOrderLeft') ||
            !$this->registerHook('displayAdminOrderRight') ||
            !$this->registerHook('actionDispatcher') ||
            !$this->registerHook('displayOrderDetail') ||
            !$this->registerHook('displayPDFInvoice')
        ) {
            return false;
        }

        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'upg_business_fields(
        id_customer INT(10) UNSIGNED NOT NULL,
        companyRegistrationID VARCHAR(30),
        companyVatID VARCHAR(30),
        companyTaxID VARCHAR(30),
        companyRegisterType VARCHAR(50),
        PRIMARY KEY (id_customer)
    )ENGINE=INNODB;');

        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'upg_refund(
        id_refund INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_order_payment INT(11) UNSIGNED NOT NULL,
        refund_description VARCHAR(255) NOT NULL,
        amount decimal(10,2)
    )ENGINE=INNODB;');

        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'upg_transaction(
        id_order INT(10) UNSIGNED NOT NULL,
        cart_id INT(10) UNSIGNED NOT NULL,
        transaction_reference VARCHAR(255),
        autocapture TINYINT(1),
        paymentMethod CHAR(30),
        INDEX `' . _DB_PREFIX_ . 'upg_transaction_id_order` (id_order)
    )ENGINE=INNODB;');


        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'upg_mns_messages(
        id_mns INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        merchantID INT(16) UNSIGNED NOT NULL,
        storeID VARCHAR(255) NOT NULL,
        orderID VARCHAR(255) NOT NULL,
        captureID VARCHAR(255) NOT NULL,
        merchantReference VARCHAR(255) NOT NULL,
        paymentReference VARCHAR(255) NOT NULL,
        userID VARCHAR(255) NOT NULL,
        amount INT(16) UNSIGNED NOT NULL,
        currency VARCHAR(255) NOT NULL,
        transactionStatus VARCHAR(255) NOT NULL,
        orderStatus VARCHAR(255) NOT NULL,
        additionalData TEXT,
        mns_timestamp BIGINT UNSIGNED NOT NULL,
        version VARCHAR(255),
        mns_processed TINYINT(1) DEFAULT 0,
        mns_error_processing TINYINT(1) DEFAULT 0,
        INDEX `' . _DB_PREFIX_ . 'upg_mns_messages_mns_processed` (mns_processed),
        INDEX `' . _DB_PREFIX_ . 'upg_mns_messages_mns_timestamp` (mns_timestamp),
        INDEX `' . _DB_PREFIX_ . 'upg_mns_messages_orderID` (orderID),
        INDEX `' . _DB_PREFIX_ . 'upg_mns_messages_mns_error_processing` (mns_error_processing)
    )ENGINE=INNODB;');

        $this->addModulePaycoStates();

        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        return true;
    }

    public function hookDisplayPaymentEU($params)
    {
        if (!$this->active) {
            return;
        }

        //is config valid
        if (!$this->validConfig()) {
            return;
        }


        $payment_options = array(
            'cta_text' => $this->l('Pay by UPG'),
            'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/bankwire.jpg'),
            'action' => $this->context->link->getModuleLink($this->name, 'validation', array(), true)
        );

        return $payment_options;
    }

    public function hookPayment($params)
    {
        if (!$this->active) {
            return;
        }

        $this->smarty->assign(array(
            'this_path' => $this->_path,
            'this_path_payco' => $this->_path,
            'this_path_ssl' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/'
        ));
        return $this->display(__FILE__, 'payment.tpl');
    }

    public function addModulePaycoStates()
    {
        if (!(Configuration::get(self::UPG_STATUS_STARTED) > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = "Awaiting payment From Payco";
            $OrderState->invoice = false;
            $OrderState->send_email = false;
            $OrderState->module_name = $this->name;
            $OrderState->color = "RoyalBlue";
            $OrderState->unremovable = false;
            $OrderState->hidden = true;
            $OrderState->logable = false;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->add();
            Configuration::updateValue(self::UPG_STATUS_STARTED, $OrderState->id);
        }

        if (!(Configuration::get(self::UPG_STATUS_RETURNED) > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = "Got Return from Payco";
            $OrderState->invoice = false;
            $OrderState->send_email = true;
            $OrderState->module_name = $this->name;
            $OrderState->color = "RoyalBlue";
            $OrderState->unremovable = false;
            $OrderState->hidden = false;
            $OrderState->logable = false;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = true;
            $OrderState->deleted = false;
            $OrderState->template = "upg";
            $OrderState->add();
            Configuration::updateValue(self::UPG_STATUS_RETURNED, $OrderState->id);
        }

        if (!(Configuration::get('PAYCO_MNS_INDUNNING') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = "Payment in Dunning";
            $OrderState->invoice = false;
            $OrderState->send_email = false;
            $OrderState->module_name = $this->name;
            $OrderState->color = "#8f0621";
            $OrderState->unremovable = false;
            $OrderState->hidden = false;
            $OrderState->logable = false;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->add();
            Configuration::updateValue('PAYCO_MNS_INDUNNING', $OrderState->id);
        }

        if (!(Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = "Fraud check pending";
            $OrderState->invoice = false;
            $OrderState->send_email = false;
            $OrderState->module_name = $this->name;
            $OrderState->color = "#8f0621";
            $OrderState->unremovable = false;
            $OrderState->hidden = false;
            $OrderState->logable = false;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->add();
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING', $OrderState->id);
        }

        if (!(Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_CIAPENDING') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = "Cash in advance awaiting payment";
            $OrderState->invoice = false;
            $OrderState->send_email = false;
            $OrderState->module_name = $this->name;
            $OrderState->color = "RoyalBlue";
            $OrderState->unremovable = false;
            $OrderState->hidden = false;
            $OrderState->logable = false;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->add();
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_CIAPENDING', $OrderState->id);
        }

        if (!(Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_INPROGRESS') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = "Order in progress";
            $OrderState->invoice = false;
            $OrderState->send_email = false;
            $OrderState->module_name = $this->name;
            $OrderState->color = "RoyalBlue";
            $OrderState->unremovable = false;
            $OrderState->hidden = false;
            $OrderState->logable = false;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->add();
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_INPROGRESS', $OrderState->id);
        }

        if (!(Configuration::get('PAYCO_PAID_AUTOCAPTURE') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = "Payco order paid by autocapture";
            $OrderState->invoice = true;
            $OrderState->send_email = false;
            $OrderState->module_name = $this->name;
            $OrderState->color = "#32CD32";
            $OrderState->unremovable = false;
            $OrderState->hidden = false;
            $OrderState->logable = false;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->add();
            Configuration::updateValue('PAYCO_PAID_AUTOCAPTURE', $OrderState->id);
            Configuration::updateValue('PAYCO_AUTOCAPTURE_PAID', $OrderState->id);
        }

        if (!(Configuration::get('PAYCO_PAID_NON_AUTOCAPTURE') > 0)) {
            $OrderState = new OrderState(null, Configuration::get('PS_LANG_DEFAULT'));
            $OrderState->name = "Payco order Paid";
            $OrderState->invoice = true;
            $OrderState->send_email = false;
            $OrderState->module_name = $this->name;
            $OrderState->color = "#32CD32";
            $OrderState->unremovable = false;
            $OrderState->hidden = false;
            $OrderState->logable = false;
            $OrderState->delivery = false;
            $OrderState->shipped = false;
            $OrderState->paid = false;
            $OrderState->deleted = false;
            $OrderState->add();
            Configuration::updateValue('PAYCO_PAID_NON_AUTOCAPTURE', $OrderState->id);
            Configuration::updateValue('PAYCO_RESERVE_PAID', $OrderState->id);
        }

    }

    /**
     * Display the payment info in pdf
     * @param $params
     */
    public function hookDisplayPDFInvoice($params)
    {
        /**
         * @var OrderInvoiceCore $orderInvoice
         */
        $orderInvoice = $params['object'];

        $order = $orderInvoice->getOrder();

        if($order->module == $this->name) {
            try {
                $currency = Currency::getCurrency($order->id_currency);
                $transactionId = $this->getTransactionIdFromOrderReference($order->reference);

                $statusRequest = new \Upg\Library\Request\GetTransactionStatus($this->getConfig($currency['iso_code']));
                $statusRequest->setOrderID($transactionId);

                $apiEndPoint = new \Upg\Library\Api\GetTransactionStatus($this->getConfig($currency['iso_code']), $statusRequest);
                $result = $apiEndPoint->sendRequest();

                $additionalData = $result->getData('additionalData');

                $this->context->smarty->assign(array(
                    'order' => $order,
                    'paymentMethod' => strtoupper(trim($additionalData['paymentMethod'])),
                    'transactionStatus' => $result->getData('transactionStatus'),
                    'additionalData' => $result->getData('additionalData')
                ));

                return $this->display(__FILE__, 'views/templates/hook/displayPDFInvoice.tpl');
            }catch (Exception $e) {
                Logger::addLog(
                    $this->l('Payco - Status lookup failed for pdf').' '.$order->reference.' '.$e->getMessage(),
                    1,
                    null,
                    null,
                    null, true
                );
            }
        }

        return '';

    }

    public function hookDisplayOrderDetail($params)
    {
        /**
         * @var OrderCore $order
         */
        $order = $params['order'];
        if($order->module == $this->name) {
            //get the payco payment details
            try {
                $currency = Currency::getCurrency($order->id_currency);
                $transactionId = $this->getTransactionIdFromOrderReference($order->reference);

                $statusRequest = new \Upg\Library\Request\GetTransactionStatus($this->getConfig($currency['iso_code']));
                $statusRequest->setOrderID($transactionId);

                $apiEndPoint = new \Upg\Library\Api\GetTransactionStatus($this->getConfig($currency['iso_code']), $statusRequest);
                $result = $apiEndPoint->sendRequest();

                $additionalData = $result->getData('additionalData');

                $this->context->smarty->assign(array(
                    'order' => $order,
                    'paymentMethod' => strtoupper(trim($additionalData['paymentMethod'])),
                    'transactionStatus' => $result->getData('transactionStatus'),
                    'additionalData' => $result->getData('additionalData')
                ));

                return $this->display(__FILE__, 'views/templates/front/order_details.tpl');
            }catch (Exception $e) {
                Logger::addLog(
                    $this->l('Payco - Status lookup failed for front end').' '.$order->reference.' '.$e->getMessage(),
                    1,
                    null,
                    null,
                    null, true
                );

            }
        }

        return '';

    }

    public function hookDisplayAdminOrderRight($params)
    {
        /**
         * @var OrderCore $order
         */
        $order = new Order($params['id_order']);
        if($order->module == $this->name) {
            $currency = Currency::getCurrency($order->id_currency);
            $statusRequest = new \Upg\Library\Request\GetTransactionStatus($this->getConfig($currency['iso_code']));
            $transactionId = $this->getTransactionIdFromOrderReference($order->reference);
            $statusRequest->setOrderID($transactionId);

            try{

                $apiEndPoint = new \Upg\Library\Api\GetTransactionStatus($this->getConfig($currency['iso_code']), $statusRequest);
                $result = $apiEndPoint->sendRequest();

                $additionalData = $result->getData('additionalData');
                $additionalDataFormatted = array();

                foreach($additionalData as $key=>$value) {
                    $label = ucwords(trim(preg_replace('/([A-Z])/', ' $1', $key)));
                    $additionalDataFormatted[$label] = $value;
                }

                $this->context->smarty->assign(array(
                    'transactionId' => $transactionId,
                    'order' => $order,
                    'result' => $result,
                    'transactionStatus' => $result->getData('transactionStatus'),
                    'additionalData' => $additionalDataFormatted
                ));

                return $this->display(__FILE__, 'views/templates/admin/status.tpl');

            }catch (Exception $e) {

                Logger::addLog(
                    $this->l('Upg - Status lookup failed').' '.$order->reference.' '.$e->getMessage(),
                    1,
                    null,
                    null,
                    null, true
                );

            }

        }

        return '';
    }

    public function hookDisplayAdminOrderLeft($params)
    {
        /**
         * @var OrderCore $order
         */
        $order = new Order($params['id_order']);
        if($order->module == $this->name) {
            $captures = $order->getOrderPayments();
            $refunds = array();

            $current_index = AdminController::$currentIndex;

            //get the refunds that have happend
            $sql = 'SELECT upg_refund.* , order_payment.transaction_id FROM '._DB_PREFIX_.'upg_refund AS upg_refund
            INNER JOIN '._DB_PREFIX_.'order_payment AS order_payment
            ON order_payment.id_order_payment = upg_refund.id_order_payment
            WHERE order_payment.order_reference = "'.pSQL($order->reference).'";';

            if ($results = Db::getInstance()->ExecuteS($sql)) {
                foreach ($results as $row) {
                    $refunds[] = $row;
                }
            }

            if(empty($captures)) {
                $captures = false;
            }

            $this->context->smarty->assign('current_index', $current_index);

            $this->context->smarty->assign(array(
                'order' => $order,
                'captures' => $captures,
                'refunds' => $refunds,
            ));

            return $this->display(__FILE__, 'views/templates/admin/refund.tpl');
        }

        return '';
    }

    public function hookActionPaymentCCAdd($params)
    {
        /**
         * @var OrderPaymentCore $payment
         */
        $payment = $params['paymentCC'];

        $checkSql = 'SELECT id_order FROM '._DB_PREFIX_.'orders WHERE reference = "'.pSQL($payment->order_reference).'"';
        $orderId = Db::getInstance()->getValue($checkSql);

        $paymentTransactionId = $payment->transaction_id;

        $order = new Order($orderId);

        if ($order->module == $this->name &&
            $payment->amount > 0 &&
            $orderId > 0
            ) {

            $order = new Order($orderId);
            $currency = Currency::getCurrency($order->id_currency);

            if(empty($paymentTransactionId)) {
                $checkSql = 'SELECT COUNT(id_order_payment) AS payments FROM '._DB_PREFIX_.'order_payment WHERE order_reference = "'.pSQL($payment->order_reference).'"';

                $count = Db::getInstance()->getValue($checkSql);
                $count++;

                $paymentTransactionId = $order->reference.':'.time().':'.$count;
                $payment->transaction_id = $paymentTransactionId;
                $payment->save();
            }

            //generate the request
            $request = new \Upg\Library\Request\Capture($this->getConfig($currency['iso_code']));
            $transactionId = $this->getTransactionIdFromOrderReference($order->reference);
            $request->setOrderID($transactionId)
                ->setCaptureID($paymentTransactionId)
                ->setAmount(new \Upg\Library\Request\Objects\Amount((int)($payment->amount * 100)));

            try{
                $apiEndPoint = new \Upg\Library\Api\Capture($this->getConfig($currency['iso_code']), $request);
                $result = $apiEndPoint->sendRequest();

                $responseCode = $result->getData('resultCode');

                if($responseCode != 0) {
                    Logger::addLog(
                        $this->l('Payco - Issue with Capture').' '.$order->reference.' '.$responseCode.':'.$result->getData('message'),
                        1,
                        null,
                        null,
                        null, true
                    );
                    $this->context->cookie->__set('upg_capture_error', $this->l('An error occurred during capture. ').' '.$result->getData('message'));
                    $payment->delete();
                }

            }catch (Exception $e) {
                $this->context->cookie->__set('upg_capture_error', $this->l('An error occurred during capture.').' '.$e->getMessage());

                Logger::addLog(
                    $this->l('Payco - Capture failed').' '.$order->reference.' '.$e->getMessage(),
                    1,
                    null,
                    null,
                    null, true
                );

                $payment->delete();
            }
        }

        return true;
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active)
            return;

        $state = $params['objOrder']->getCurrentState();
        /**
         * @var OrderCore $order
         */
        $order = $params['objOrder'];

        $this->smarty->assign(array(
            'state' => $state,
            'orderReference' => $order->reference,
        ));

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    public function getTransactionIdFromOrderId($orderId)
    {
        $orderId = intval($orderId);
        if($orderId > 0) {
            $checkSql = 'SELECT transaction_reference FROM ' . _DB_PREFIX_ . 'upg_transaction WHERE id_order = ' . $orderId;
            return Db::getInstance()->getValue($checkSql);
        }
        return 0;
    }

    public function getOrderRefFromTransactionId($transactionId)
    {
        $checkSql = 'SELECT orders.reference
        FROM '._DB_PREFIX_.'orders AS orders INNER JOIN '._DB_PREFIX_.'upg_transaction AS paycotransaction ON orders.id_order = paycotransaction.id_order
        WHERE paycotransaction.transaction_reference = "'.pSQL($transactionId).'"';
        return Db::getInstance()->getValue($checkSql);
    }

    public function getTransactionIdFromOrderReference($orderReference)
    {
        $checkSql = 'SELECT paycotransaction.transaction_reference
        FROM '._DB_PREFIX_.'orders AS orders INNER JOIN '._DB_PREFIX_.'upg_transaction AS paycotransaction ON orders.id_order = paycotransaction.id_order
        WHERE orders.reference = "'.pSQL($orderReference).'"';
        return Db::getInstance()->getValue($checkSql);
    }

    public function hookActionDispatcher($params)
    {
        $controllerClass = $params['controller_class'];
        $errorFlag = false;

        $captureMessage = '';
        if($this->context->cookie->__isset('upg_capture_error')) {
            $captureMessage = $this->context->cookie->__get('upg_capture_error');
            $this->context->cookie->__unset('upg_capture_error');
        }

        if($controllerClass == 'AdminOrdersController') {

            if(!empty($captureMessage)) {
                $this->context->controller->errors[] = $captureMessage;
            }

            if (Tools::isSubmit('submitPaycoRefund')) {

                $captureId = Tools::getValue('capture_id');
                $refundDescription = Db::getInstance()->escape(Tools::getValue('refund_description'));
                $amount = str_replace(',', '.', Tools::getValue('amount'));

                if (!$captureId) {
                    $this->context->controller->errors[] = $this->l('Please Select a Capture');
                    $errorFlag = true;
                }

                if (!$refundDescription) {
                    $this->context->controller->errors[] = $this->l('Please provide an Refund Description');
                    $errorFlag = true;
                }

                if (!$amount) {
                    $this->context->controller->errors[] = $this->l('Please provide an amount');
                    $errorFlag = true;
                }

                /**
                 * @var OrderPaymentCore $payment
                 */
                $payment = new OrderPaymentCore($captureId);
                $transactionReference = $payment->transaction_id;

                $checkSql = 'SELECT id_order FROM '._DB_PREFIX_.'orders WHERE reference = "'.pSQL($payment->order_reference).'"';
                $orderId = Db::getInstance()->getValue($checkSql);

                /**
                 * @var OrderCore $order;
                 */
                $order = new Order($orderId);
                $currency = Currency::getCurrency($order->id_currency);


                if(!$transactionReference) {
                    $this->context->controller->errors[] = $this->l('Order reference must be set, did you select an capture from the drop down list');
                    $errorFlag = true;
                }

                //check if refund amount is grater then the capture
                if($amount > $payment->amount) {
                    $this->context->controller->errors[] = $this->l('Order must not exceed the payment');
                    $errorFlag = true;
                }

                if(!$errorFlag) {
                    $refundRequest = new \Upg\Library\Request\Refund($this->getConfig($currency['iso_code']));

                    $transactionId = $this->getTransactionIdFromOrderId($orderId);
                    $refundRequest->setOrderID($transactionId)
                        ->setCaptureID($transactionReference)
                        ->setRefundDescription($refundDescription)
                        ->setAmount(new \Upg\Library\Request\Objects\Amount((int)($amount * 100)));

                    try {
                        $apiEndPoint = new \Upg\Library\Api\Refund($this->getConfig($currency['iso_code']), $refundRequest);
                        $result = $apiEndPoint->sendRequest();

                        $queryData = array(
                            'id_order_payment' => $payment->id,
                            'refund_description' => $refundDescription,
                            'amount' => Db::getInstance()->escape($amount),
                        );

                        Db::getInstance()->insert('upg_refund', $queryData);

                    }catch (Exception $e){
                        $this->context->controller->errors[] = $this->l($e->getMessage());

                        Logger::addLog(
                            $this->l('Payco - Refund failed').' '.$order->reference.' '.$e->getMessage(),
                            1,
                            null,
                            null,
                            null, true
                        );
                    }
                }
            }
        }

        return false;
    }

    public function convertCartToOrder($cartId, $transactionReference)
    {
        $cart = new CartCore($cartId);
        $currency = Currency::getCurrency($cart->id_currency);
        $customer = new CustomerCore($cart->id_customer);
        $mailVars = array();

        //ok get the deatails for the email
        try{

            $statusRequest = new \Upg\Library\Request\GetTransactionStatus($this->getConfig($currency['iso_code']));
            $statusRequest->setOrderID($transactionReference);

            $apiEndPoint = new \Upg\Library\Api\GetTransactionStatus($this->getConfig($currency['iso_code']), $statusRequest);
            $result = $apiEndPoint->sendRequest();

            $additionalData = $result->getData('additionalData');

            $this->context->smarty->assign(array(
                'paymentMethod' => strtoupper(trim($additionalData['paymentMethod'])),
                'transactionStatus' => $result->getData('transactionStatus'),
                'additionalData' => $additionalData
            ));

            $mailVars['{payco_html}'] = $this->display(__FILE__, 'views/templates/front/email.tpl');
            $mailVars['{payco_txt}'] = $this->display(__FILE__, 'views/templates/front/emailtxt.tpl');

            $paymentName = $this->getPaymentName(strtoupper(trim($additionalData['paymentMethod'])));

        }catch (Exception $e) {
            Logger::addLog(
                $this->l('Payco - order status check failed').' '.$transactionReference.' '.$e->getMessage(),
                1,
                null,
                null,
                null, true
            );

            $paymentName = $this->displayName;
        }

        $this->validateOrder((int)$cart->id, Configuration::get(self::UPG_STATUS_RETURNED), 0, $paymentName, null, $mailVars, null, true, $customer->secure_key);

        return $this->currentOrder;
    }

    private function getPaymentName($methodName = '')
    {
        switch($methodName) {
            case 'DD':
                return $this->l('Direct Debit');
            case 'CC':
                return $this->l('Credit Card');
            case 'CC3D':
                return $this->l('Credit Card with 3D secure');
            case 'PREPAID':
                return $this->l('Prepaid');
            case 'PAYPAL':
                return $this->l('PayPal');
            case 'SU':
                return $this->l('Sofortüberweisung');
            case 'BILL':
                return $this->l('Bill payment without payment guarantee');
            case 'BILL_SECURE':
                return $this->l('Bill payment with payment guarantee');
            case 'COD':
                return $this->l('Cash on delivery');
            case 'IDEAL':
                return $this->l('IDEAL');
            case 'INSTALLMENT':
                return $this->l('Installment');
            case 'PAYCO_WALLET':
                return $this->l('Payco Wallet');
            case 'DUMMY':
                return $this->l('Dummy');
            default:
                return $this->displayName;
        }
    }

    public function checkCartHasBeenUsed(CartCore $cart)
    {
        $checkSql = 'SELECT transaction_reference FROM ' . _DB_PREFIX_ . 'upg_transaction WHERE cart_id = ' . $cart->id;
        $reference = Db::getInstance()->getValue($checkSql);

        if(!empty($reference)){
            $newCart = $cart->duplicate();
            return $newCart['cart'];
        }

        return $cart;
    }

    public function populateOrder(CartCore $cart, array $postData = array())
    {
        $languageCore = Language::getLanguage($cart->id_lang);
        $language = $this->getUserLanguage($languageCore);
        $currency = Currency::getCurrency($cart->id_currency);

        $request = new \Upg\Library\Request\CreateTransaction($this->getConfig($currency['iso_code']));
        $customer = new CustomerCore($cart->id_customer);

        //$this->validateOrder((int)$cart->id, Configuration::get(self::UPG_STATUS_STARTED), 0, $this->displayName, null, array(), null, true, $customer->secure_key);
        //$order = new Order($this->currentOrder);

        $autocapture = (Configuration::get('PAYCO_AUTOCATURE') > 0 ? true : false);
        $riskClass = Configuration::get('PAYCO_DEFAULT_USER_RISK_CLASS');
        if (empty($riskClass)) {
            $riskClass = \Upg\Library\Risk\RiskClass::RISK_CLASS_DEFAULT;
        }
        $riskClass = intval($riskClass);

        $transactionType = \Upg\Library\Request\CreateTransaction::USER_TYPE_PRIVATE;

        if (!empty($invoiceAddress->company) && Configuration::get('PAYCO_B2B_ENABLED')) {
            $transactionType = \Upg\Library\Request\CreateTransaction::USER_TYPE_BUSINESS;
        }

        $orderId = $cart->id.':'.time();

        $request->setOrderID($orderId)
            ->setUserID($cart->id_customer)
            ->setIntegrationType(\Upg\Library\Request\CreateTransaction::INTEGRATION_TYPE_HOSTED_AFTER)
            ->setAutoCapture($autocapture)
            ->setContext(\Upg\Library\Request\CreateTransaction::CONTEXT_ONLINE)
            ->setUserType($transactionType)
            ->setUserRiskClass($riskClass)
            ->setUserData($this->getPaycoUserObject($customer, $cart))
            ->setBillingAddress($this->getPaycoBillingAddress($cart))
            ->setShippingAddress($this->getPaycoDeliveryAddress($cart))
            ->setLocale($language);

        //add in extended data
        if ($transactionType == \Upg\Library\Request\CreateTransaction::USER_TYPE_BUSINESS) {
            $company = new \Upg\Library\Request\Objects\Company();
            $company->setCompanyName($invoiceAddress->company);

            //companyRegistrationID
            if (array_key_exists('companyRegistrationID', $postData)) {
                $company->setCompanyRegistrationID($postData['companyRegistrationID']);
            }

            if (array_key_exists('companyVatID', $postData)) {
                $company->setCompanyVatID($postData['companyVatID']);
            }

            if (array_key_exists('companyTaxID', $postData)) {
                $company->setCompanyTaxID($postData['companyTaxID']);
            }

            if (array_key_exists('companyRegisterType', $postData)) {
                $company->setCompanyRegisterType($postData['companyRegisterType']);
            }

            $request->setCompanyData($company);
        }

        //add in the cart and amount and fix any rounding
        $total = $cart->getOrderTotal(true, Cart::BOTH) * 100;
        $total = intval('0'.$total);
        $request->setAmount(new \Upg\Library\Request\Objects\Amount($total));
        $this->getPaycoProducts($cart, $request);

        //now send the request
        try {
            $apiEndPoint = new \Upg\Library\Api\CreateTransaction($this->getConfig($currency['iso_code']), $request);
            $result = $apiEndPoint->sendRequest();

            $queryData = array(
                'transaction_reference' => $orderId,
                'cart_id' => $cart->id,
                'autocapture' => ($autocapture ? 1:0),
            );

            Db::getInstance()->insert('upg_transaction', $queryData);

            return $result->getData('redirectUrl');
        }catch (Exception $e) {
            Logger::addLog(
                $this->l('Payco - create transaction failed').' '.$cart->id.' '.$e->getMessage(),
                1,
                null,
                null,
                null, true
            );
            throw $e;
        }
    }

    private function getPaycoProducts(CartCore $cart, \Upg\Library\Request\CreateTransaction $request)
    {
        foreach ($cart->getProducts() as $product) {
            $itemTotal = $product["total_wt"] * 100;
            $itemTotal = intval('0'.$itemTotal);
            $amount = new \Upg\Library\Request\Objects\Amount();
            $amount->setAmount($itemTotal);

            $item = new \Upg\Library\Request\Objects\BasketItem();

            $item->setBasketItemText($product["name"])
                ->setBasketItemCount($product["quantity"])
                ->setBasketItemAmount($amount);

            $request->addBasketItem($item);
        }
    }

    private function getUserLanguage(array $language)
    {
        $prestashopCode = $language['iso_code'];
        $prestashopCode = trim(strtoupper($prestashopCode));

        switch($prestashopCode) {
            case \Upg\Library\Locale\Codes::LOCALE_EN:
            case \Upg\Library\Locale\Codes::LOCALE_DE:
            case \Upg\Library\Locale\Codes::LOCALE_ES:
            case \Upg\Library\Locale\Codes::LOCALE_FI:
            case \Upg\Library\Locale\Codes::LOCALE_FR:
            case \Upg\Library\Locale\Codes::LOCALE_NL:
            case \Upg\Library\Locale\Codes::LOCALE_IT:
            case \Upg\Library\Locale\Codes::LOCALE_RU:
            case \Upg\Library\Locale\Codes::LOCALE_TU:
                return $prestashopCode;
                break;
            default:
                return Configuration::get('PAYCO_LOCALE');
                break;
        }

        return Configuration::get('PAYCO_LOCALE');
    }

    private function getPaycoBillingAddress(CartCore $cart)
    {
        $address = new Address((int)$cart->id_address_invoice);
        $country = new Country((int)$address->id_country);
        $upgAddress = new Upg\Library\Request\Objects\Address();

        $street = $address->address1 . " " . $address->address2;

        $upgAddress->setStreet($street)
            ->setZip($address->postcode)
            ->setCity($address->city)
            ->setCountry(strtoupper($country->iso_code));

        return $upgAddress;
    }

    private function getPaycoDeliveryAddress(CartCore $cart)
    {
        $address = new AddressCore((int)$cart->id_address_delivery);
        $country = new Country((int)$address->id_country);
        $upgAddress = new Upg\Library\Request\Objects\Address();

        $street = $address->address1 . " " . $address->address2;
        $upgAddress->setStreet($street)
            ->setZip($address->postcode)
            ->setCity($address->city)
            ->setCountry(strtoupper($country->iso_code));

        return $upgAddress;
    }

    private function getPaycoUserObject(CustomerCore $user, CartCore $cart)
    {
        $userGender = new GenderCore($user->id_gender);
        $gender = null;
        switch($userGender->type) {
            case 0:
                $gender = \Upg\Library\Request\Objects\Person::SALUTATIONMALE;
                break;
            case 1:
                $gender = \Upg\Library\Request\Objects\Person::SALUTATIONFEMALE;
                break;
        }

        $invoice = new AddressCore((int)$cart->id_address_invoice);


        $paycoUser = new \Upg\Library\Request\Objects\Person();
        $paycoUser->setSalutation($gender)
            ->setName($invoice->firstname)
            ->setSurname($invoice->lastname)
            ->setEmail($user->email);

        if((!empty($user->birthday)) && ($user->birthday != '0000-00-00')) {
            list($year,$month,$day) = explode('-', $user->birthday);
            $birthday = new DateTime();
            $birthday->setDate($year, $month, $day);
            $paycoUser->setDateOfBirth($birthday);
        }

        return $paycoUser;
    }

    private function validConfig()
    {
        $merchantId = Configuration::get('PAYCO_MERCHANT_ID');
        $storeId= Configuration::get('PAYCO_STORE_ID');
        $password = Configuration::get('PAYCO_PASSWORD');

        if(empty($merchantId) || empty($storeId) || empty($password)) {
            return false;
        }

        return true;
    }


    protected function _postValidation()
    {
        $this->_postErrors = array();

        if (Tools::isSubmit('btnSubmit'))
        {
            if (!Tools::getValue('PAYCO_MERCHANT_ID')) {
                $this->_postErrors[] = $this->l('Merchant ID required');
            }
            if (!Tools::getValue('PAYCO_STORE_ID')) {
                $this->_postErrors[] = $this->l('Store ID required');
            }
            if (!Tools::getValue('PAYCO_PASSWORD')) {
                $this->_postErrors[] = $this->l('Merchant Key Required');
            }
            if (!Tools::getValue('PAYCO_MODE')) {
                $this->_postErrors[] = $this->l('Mode must be selected');
            }
            if (!Tools::getValue('PAYCO_LOCALE')) {
                $this->_postErrors[] = $this->l('Locale must be selected');
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit'))
        {
            Configuration::updateValue('PAYCO_MERCHANT_ID', Tools::getValue('PAYCO_MERCHANT_ID'));
            Configuration::updateValue('PAYCO_STORE_ID', Tools::getValue('PAYCO_STORE_ID'));
            Configuration::updateValue('PAYCO_PASSWORD', Tools::getValue('PAYCO_PASSWORD'));
            Configuration::updateValue('PAYCO_LOG_LOCATION', Tools::getValue('PAYCO_LOG_LOCATION'));
            Configuration::updateValue('PAYCO_LOG_API_LOCATION', Tools::getValue('PAYCO_LOG_API_LOCATION'));
            Configuration::updateValue('PAYCO_LOGLEVEL', Tools::getValue('PAYCO_LOGLEVEL'));
            Configuration::updateValue('PAYCO_MODE', Tools::getValue('PAYCO_MODE'));
            Configuration::updateValue('PAYCO_LOCALE', Tools::getValue('PAYCO_LOCALE'));
            Configuration::updateValue('PAYCO_DEFAULT_USER_RISK_CLASS', Tools::getValue('PAYCO_DEFAULT_USER_RISK_CLASS'));
            Configuration::updateValue('PAYCO_AUTOCATURE', Tools::getValue('PAYCO_AUTOCATURE'));
            Configuration::updateValue('PAYCO_B2B_ENABLED', Tools::getValue('PAYCO_B2B_ENABLED'));
            Configuration::updateValue('PAYCO_AUTOCAPTURE_PAID', Tools::getValue('PAYCO_AUTOCAPTURE_PAID'));
            Configuration::updateValue('PAYCO_RESERVE_PAID', Tools::getValue('PAYCO_RESERVE_PAID'));
            Configuration::updateValue('PAYCO_RESERVE_PAIDPENDING', Tools::getValue('PAYCO_RESERVE_PAIDPENDING'));
            Configuration::updateValue('PAYCO_MNS_PAYMENTFAILED', Tools::getValue('PAYCO_MNS_PAYMENTFAILED'));
            Configuration::updateValue('PAYCO_MNS_CHARGEBACK', Tools::getValue('PAYCO_MNS_CHARGEBACK'));
            Configuration::updateValue('PAYCO_MNS_CLEARED', Tools::getValue('PAYCO_MNS_CLEARED'));
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_ACKNOWLEDGEPENDING', Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_ACKNOWLEDGEPENDING'));
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING', Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING'));
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_CIAPENDING', Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_CIAPENDING'));
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_MERCHANTPENDING', Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_MERCHANTPENDING'));
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_INPROGRESS', Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_INPROGRESS'));
            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_DONE', Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_DONE'));

            Configuration::updateValue('PAYCO_MNS_INDUNNING', Tools::getValue('PAYCO_MNS_INDUNNING'));

            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING', Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING'));

            Configuration::updateValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING', Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING'));

            $currencyOption = array();
            //ps_currency //ps_currency_shop
            $currencyQuery = "SELECT * FROM "._DB_PREFIX_."currency WHERE active=1";

            if ($results = Db::getInstance()->ExecuteS($currencyQuery)) {
                foreach ($results as $row) {
                    $optionName = 'PAYCO_STORE_ID_'.$row['iso_code'];

                    Configuration::updateValue($optionName, Tools::getValue($optionName));
                }
            }

        }
        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
    }

    public function getCompanyRegisterTypeOptions()
    {
        return array(
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_HRA,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_HRB,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_FN,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_PARTR,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_GENR,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_VERR,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_LUA,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_LUB,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_LUC,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_LUD,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_LUE,
            \Upg\Library\Request\Objects\Company::COMPANY_TYPE_LUF,
        );
    }

    public function getContent()
    {
        $this->_html = '';

        if (Tools::isSubmit('btnSubmit'))
        {
            $this->_postValidation();
            if (!count($this->_postErrors))
                $this->_postProcess();
            else
                foreach ($this->_postErrors as $err)
                    $this->_html .= $this->displayError($err);
        }

        //$this->_html .= $this->_displayCheque();
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function renderForm()
    {
        $modeOptions = array(
            array(
                'id_option' => 'URL_SANDBOX',
                'name' => 'Sandbox'
            ),
            array(
                'id_option' => 'URL_LIVE',
                'name' => 'Live'
            )
        );

        $localeOptions = array(
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_EN,
                'name' => 'English'
            ),
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_DE,
                'name' => 'German'
            ),
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_ES,
                'name' => 'Spanish'
            ),
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_FI,
                'name' => 'Finish'
            ),
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_FR,
                'name' => 'French'
            ),
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_NL,
                'name' => 'Dutch'
            ),
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_IT,
                'name' => 'Italian'
            ),
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_RU,
                'name' => 'Russian'
            ),
            array(
                'id_option' => \Upg\Library\Locale\Codes::LOCALE_TU,
                'name' => 'Turkish'
            ),
        );

        $riskClassOptions = array(
            array(
                'id_option' => \Upg\Library\Risk\RiskClass::RISK_CLASS_TRUSTED,
                'name' => 'Trusted'
            ),
            array(
                'id_option' => \Upg\Library\Risk\RiskClass::RISK_CLASS_DEFAULT,
                'name' => 'Default'
            ),
            array(
                'id_option' => \Upg\Library\Risk\RiskClass::RISK_CLASS_HIGH,
                'name' => 'High'
            )
        );

        $autocaptureOptions = array(
            array(
                'id_option' => 0,
                'name' => 'Disabled'
            ),
            array(
                'id_option' => 1,
                'name' => 'Enabled'
            ),
        );

        $b2bEnabledOptions = array(
            array(
                'id_option' => 0,
                'name' => 'Disabled'
            ),
            array(
                'id_option' => 1,
                'name' => 'Enabled'
            ),
        );

        $logLevelOptions = array(
            array(
                'id_option' => 'emergency',
                'name' => $this->l("Emergency")
            ),
            array(
                'id_option' => 'alert',
                'name' => $this->l("Alert")
            ),
            array(
                'id_option' => 'critical',
                'name' => $this->l("Critical")
            ),
            array(
                'id_option' => 'error',
                'name' => $this->l("Error")
            ),
            array(
                'id_option' => 'warning',
                'name' => $this->l("Warning")
            ),
            array(
                'id_option' => 'notice',
                'name' => $this->l("Notice")
            ),
            array(
                'id_option' => 'info',
                'name' => $this->l("Info")
            ),
            array(
                'id_option' => 'debug',
                'name' => $this->l("Debug")
            ),
        );

        $statuses = OrderStateCore::getOrderStates($this->context->language->id);

        /**
         * array(
        'type' => 'text',
        'label' => $this->l('Store ID to Currency mapping'),
        'desc' => $this->l('pipe seporated list in the following format storeid1=EUR|storeid2=CHF|storeid3=GBP.'),
        'name' => 'PAYCO_STORE_ID_CURRENCY',
        'required' => true
        )
         */
        $currencyOption = array();
        //ps_currency //ps_currency_shop
        $currencyQuery = "SELECT * FROM "._DB_PREFIX_."currency WHERE active=1";

        if ($results = Db::getInstance()->ExecuteS($currencyQuery)) {
            foreach ($results as $row) {
                $currencyOption[] = array(
                    'type' => 'text',
                    'label' => $this->l('Store ID : ').$row['iso_code'],
                    'desc' => $this->l('Store ID : ').$row['iso_code'],
                    'name' => 'PAYCO_STORE_ID_'.$row['iso_code'],
                    'required' => true
                );
            }
        }


        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Payco Configuration'),
                    'icon' => 'icon-credit-card'
                ),
                'input' => array_merge(
                    array(
                        array(
                            'type' => 'text',
                            'label' => $this->l('Merchant ID'),
                            'name' => 'PAYCO_MERCHANT_ID',
                            'required' => true
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Store ID'),
                            'desc' => $this->l('Default store ID.'),
                            'name' => 'PAYCO_STORE_ID',
                            'required' => true
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Key'),
                            'desc' => $this->l('Your Merchant Key.'),
                            'name' => 'PAYCO_PASSWORD',
                            'required' => true
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Log Location'),
                            'desc' => $this->l('Log Location.'),
                            'name' => 'PAYCO_LOG_LOCATION',
                            'required' => false
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('API Log Location'),
                            'desc' => $this->l('API Log Location.'),
                            'name' => 'PAYCO_LOG_API_LOCATION',
                            'required' => false
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Log Level:'),
                            'name' => 'PAYCO_LOGLEVEL',
                            'required' => false,
                            'options' => array(
                                'query' => $logLevelOptions,
                                'id' => 'id_option',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Mode:'),
                            'name' => 'PAYCO_MODE',
                            'required' => true,
                            'options' => array(
                                'query' => $modeOptions,
                                'id' => 'id_option',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Default Locale:'),
                            'name' => 'PAYCO_LOCALE',
                            'required' => true,
                            'options' => array(
                                'query' => $localeOptions,
                                'id' => 'id_option',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Default User Risk Class:'),
                            'name' => 'PAYCO_DEFAULT_USER_RISK_CLASS',
                            'required' => true,
                            'options' => array(
                                'query' => $riskClassOptions,
                                'id' => 'id_option',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Auto capture:'),
                            'name' => 'PAYCO_AUTOCATURE',
                            'required' => true,
                            'options' => array(
                                'query' => $autocaptureOptions,
                                'id' => 'id_option',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('B2B Enabled:'),
                            'name' => 'PAYCO_B2B_ENABLED',
                            'desc' => $this->l('If enabled and user enters a company name they will be asked for more data before going to payment page.'),
                            'required' => true,
                            'options' => array(
                                'query' => $b2bEnabledOptions,
                                'id' => 'id_option',
                                'name' => 'name',
                            )
                        ),
                        //$statuses
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for Autocapture for Paid orders:'),
                            'name' => 'PAYCO_AUTOCAPTURE_PAID',
                            'desc' => $this->l('What status to set orders when MNS paid notification.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for Non-Autocapture Paid orders:'),
                            'name' => 'PAYCO_RESERVE_PAID',
                            'desc' => $this->l('What status to set orders when MNS orderStatus paid notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for Pay pending orders:'),
                            'name' => 'PAYCO_RESERVE_PAIDPENDING',
                            'desc' => $this->l('What status to set orders when MNS orderStatus paidpending notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for Payment failed orders:'),
                            'name' => 'PAYCO_MNS_PAYMENTFAILED',
                            'desc' => $this->l('What status to set orders when MNS orderStatus paymentfailed notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for orders with charge back:'),
                            'name' => 'PAYCO_MNS_CHARGEBACK',
                            'desc' => $this->l('What status to set orders when MNS orderStatus chargeback notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for cleared orders:'),
                            'name' => 'PAYCO_MNS_CLEARED',
                            'desc' => $this->l('What status to set orders when MNS orderStatus cleared notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for cleared In Dunning order:'),
                            'name' => 'PAYCO_MNS_INDUNNING',
                            'desc' => $this->l('What status to set orders when MNS orderStatus INDUNNING notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for Acknowledge pending orders:'),
                            'name' => 'PAYCO_MNS_TRANSACTION_STATUS_ACKNOWLEDGEPENDING',
                            'desc' => $this->l('What status to set orders when MNS transaction status ACKNOWLEDGEPENDING notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for fraud check pending orders:'),
                            'name' => 'PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING',
                            'desc' => $this->l('What status to set orders when MNS transaction status FRAUDPENDING notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for CIA pending orders:'),
                            'name' => 'PAYCO_MNS_TRANSACTION_STATUS_CIAPENDING',
                            'desc' => $this->l('What status to set orders when MNS transaction status CIAPending notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        //PAYCO_MNS_TRANSACTION_STATUS_MERCHANTPENDING
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for Merchant Pending orders:'),
                            'name' => 'PAYCO_MNS_TRANSACTION_STATUS_MERCHANTPENDING',
                            'desc' => $this->l('What status to set orders when MNS transaction status MERCHANTPENDING notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),

                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for in progress orders:'),
                            'name' => 'PAYCO_MNS_TRANSACTION_STATUS_INPROGRESS',
                            'desc' => $this->l('What status to set orders when MNS transaction status INPROGRESS notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                        array(
                            'type' => 'select',
                            'label' => $this->l('Status for done orders orders:'),
                            'name' => 'PAYCO_MNS_TRANSACTION_STATUS_DONE',
                            'desc' => $this->l('What status to set orders when MNS transaction status DONE notification is sent.'),
                            'required' => true,
                            'options' => array(
                                'query' => $statuses,
                                'id' => 'id_order_state',
                                'name' => 'name',
                            )
                        ),
                    ),
                    $currencyOption
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        $settings = array(
            'PAYCO_MERCHANT_ID' => Tools::getValue('PAYCO_MERCHANT_ID', Configuration::get('PAYCO_MERCHANT_ID')),
            'PAYCO_STORE_ID' => Tools::getValue('PAYCO_STORE_ID', Configuration::get('PAYCO_STORE_ID')),
            'PAYCO_PASSWORD' => Tools::getValue('PAYCO_PASSWORD', Configuration::get('PAYCO_PASSWORD')),
            'PAYCO_LOG_LOCATION' => Tools::getValue('PAYCO_LOG_LOCATION', Configuration::get('PAYCO_LOG_LOCATION')),
            'PAYCO_LOG_API_LOCATION' => Tools::getValue('PAYCO_LOG_API_LOCATION', Configuration::get('PAYCO_LOG_API_LOCATION')),
            'PAYCO_LOGLEVEL' => Tools::getValue('PAYCO_LOGLEVEL', Configuration::get('PAYCO_LOGLEVEL')),
            'PAYCO_MODE' => Tools::getValue('PAYCO_MODE', Configuration::get('PAYCO_MODE')),
            'PAYCO_LOCALE' => Tools::getValue('PAYCO_LOCALE', Configuration::get('PAYCO_LOCALE')),
            'PAYCO_DEFAULT_USER_RISK_CLASS' => Tools::getValue('PAYCO_DEFAULT_USER_RISK_CLASS', Configuration::get('PAYCO_DEFAULT_USER_RISK_CLASS')),
            'PAYCO_AUTOCATURE' => Tools::getValue('PAYCO_AUTOCATURE', Configuration::get('PAYCO_AUTOCATURE')),
            'PAYCO_B2B_ENABLED' => Tools::getValue('PAYCO_B2B_ENABLED', Configuration::get('PAYCO_B2B_ENABLED')),
            'PAYCO_AUTOCAPTURE_PAID' => Tools::getValue('PAYCO_AUTOCAPTURE_PAID', Configuration::get('PAYCO_AUTOCAPTURE_PAID')),
            'PAYCO_RESERVE_PAID' => Tools::getValue('PAYCO_RESERVE_PAID', Configuration::get('PAYCO_RESERVE_PAID')),
            'PAYCO_RESERVE_PAIDPENDING' => Tools::getValue('PAYCO_RESERVE_PAID', Configuration::get('PAYCO_RESERVE_PAIDPENDING')),
            'PAYCO_MNS_PAYMENTFAILED' => Tools::getValue('PAYCO_MNS_PAYMENTFAILED', Configuration::get('PAYCO_MNS_PAYMENTFAILED')),
            'PAYCO_MNS_CHARGEBACK' => Tools::getValue('PAYCO_MNS_CHARGEBACK', Configuration::get('PAYCO_MNS_CHARGEBACK')),
            'PAYCO_MNS_CLEARED' => Tools::getValue('PAYCO_MNS_CLEARED', Configuration::get('PAYCO_MNS_CLEARED')),
            'PAYCO_MNS_TRANSACTION_STATUS_ACKNOWLEDGEPENDING' => Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_ACKNOWLEDGEPENDING', Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_ACKNOWLEDGEPENDING')),

            'PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING' => Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING', Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING')),

            'PAYCO_MNS_TRANSACTION_STATUS_CIAPENDING' => Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_CIAPENDING', Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_CIAPENDING')),

            'PAYCO_MNS_TRANSACTION_STATUS_MERCHANTPENDING' => Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_MERCHANTPENDING', Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_MERCHANTPENDING')),

            'PAYCO_MNS_TRANSACTION_STATUS_INPROGRESS' => Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_INPROGRESS', Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_INPROGRESS')),

            'PAYCO_MNS_TRANSACTION_STATUS_DONE' => Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_DONE', Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_DONE')),

            'PAYCO_MNS_INDUNNING' => Tools::getValue('PAYCO_MNS_INDUNNING', Configuration::get('PAYCO_MNS_INDUNNING')),

            'PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING' => Tools::getValue('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING', Configuration::get('PAYCO_MNS_TRANSACTION_STATUS_FRAUDPENDING')),
        );

        $currencyOption = array();
        //ps_currency //ps_currency_shop
        $currencyQuery = "SELECT * FROM "._DB_PREFIX_."currency WHERE active=1";

        if ($results = Db::getInstance()->ExecuteS($currencyQuery)) {
            foreach ($results as $row) {
                $optionName = 'PAYCO_STORE_ID_'.$row['iso_code'];

                $settings[$optionName] = Tools::getValue($optionName, Configuration::get($optionName));
            }
        }

        return $settings;
    }

    /**
     * @param string $currencyCode ISO currency code for the transaction
     * @return \Upg\Library\Config
     */
    public function getConfig($currencyCode = '')
    {
        $data = array();

        if(Configuration::get('PAYCO_MERCHANT_ID')) {
            $data['merchantID'] = Configuration::get('PAYCO_MERCHANT_ID');
        }

        if(Configuration::get('PAYCO_PASSWORD')) {
            $data['merchantPassword'] = Configuration::get('PAYCO_PASSWORD');
        }

        $storeId = Configuration::get('PAYCO_STORE_ID');
        if(!empty($currencyCode)) {
            $storeIdOption = 'PAYCO_STORE_ID_'.strtoupper(trim($currencyCode));
            if (Configuration::get($storeIdOption)) {
                $storeId = Configuration::get($storeIdOption);
            }
        }

        $data['storeID'] = $storeId;

        $url = self::URL_SANDBOX;

        if(Configuration::get('PAYCO_MODE') == 'URL_LIVE') {
            $url = self::URL_LIVE;
        }

        if(Configuration::get('PAYCO_LOCALE')) {
            $data['defaultLocale'] = Configuration::get('PAYCO_LOCALE');
        }

        //PAYCO_LOG_API_LOCATION
        if(Configuration::get('PAYCO_LOG_API_LOCATION')) {
            $data['logLocationMain'] = Configuration::get('PAYCO_LOG_API_LOCATION');
            $data['logLocationRequest'] = Configuration::get('PAYCO_LOG_API_LOCATION');
            $data['logLevel'] = Configuration::get('PAYCO_LOGLEVEL');
            $data['logEnabled'] = true;
        }

        $data['baseUrl'] = $url;

        $config = new \Upg\Library\Config($data);

        return $config;
    }
}