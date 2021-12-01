<?php
/**
*   AfterPay Netherlands
*
*   Do not copy, modify or distribute this document in any form.
*
*   @author     Matthijs <matthijs@blauwfruit.nl>
*   @copyright  Copyright (c) 2013-2021 blauwfruit (https://blauwfruit.nl)
*   @license    Proprietary Software
*
*/

class AfterpaynlRedirectModuleFrontController extends ModuleFrontController
{

    /**
    *  @return customer extra data form
    */
    public function postProcess()
    {
        include_once(_PS_MODULE_DIR_.'afterpaynl/classes/Afterpay.php');

        $this->context->controller->addCSS(_MODULE_DIR_.$this->module->name.'/views/css/front.css');

        $error = array();
        $fields = array();

        if (!$this->context->cart->id_customer) {
            $error['login'] = $this->module->l('Try login first.', 'redirect');
        }


        $Afterpay = new Afterpay;
        $Afterpay->modus = (Configuration::get('AFTERPAYNL_MODE')) ? 'live' : 'test';
      
        /* Invoice */
        $Customer = new Customer($this->context->cart->id_customer);
        $InvoiceAddress = new Address($this->context->cart->id_address_invoice);
        $InvoiceCountry = new Country($InvoiceAddress->id_country);

        $customer_type = ($InvoiceAddress->company == '') ? 'consumer' : 'business';

        $cart = new Cart($this->context->cart->id);

        $minimum_amount = 5;
        if ($cart->getOrderTotal(true, Cart::BOTH) < $minimum_amount) {
            $error['login'] = sprintf(
                $this->module->l('The minimum order amount is %s', 'redirect'),
                Tools::displayPrice($minimum_amount)
            );
        }

        if (Tools::isSubmit('accept_and_complete_personal_details')) {
            if (Tools::getValue('terms') == false) {
                $error['terms'] = $this->module->l('Accept the terms of Afterpay', 'redirect');
            }

            if (!Tools::getValue('secure_key')==Context::getContext()->customer->secure_key) {
                $error['same_origin'] = $this->module->l('The data is not coming from a trusted source', 'redirect');
            }
            if ($customer_type == 'consumer') {
                if (Tools::getValue('birthday') == false) {
                    if ($Customer->birthday == '0000-00-00') {
                        $error['birthday'] = $this->module->l('Birthday is not given', 'redirect');
                    }
                } else {
                    $Customer->birthday = date('Y-m-d', strtotime(Tools::getValue('birthday')));
                    $Customer->save();
                }
            }

            if (Tools::getValue('id_gender') == false) {
                if ($Customer->id_gender == '0') {
                    $error['id_gender'] = $this->module->l('Gender is not given', 'redirect');
                }
            } else {
                $Customer->id_gender = (Tools::getValue('id_gender')!==false) ? Tools::getValue('id_gender') : false;
                $Customer->save();
            }

            if ($customer_type == 'business') {
                /* Businesses */
                if (Tools::getValue('company') == '') {
                    $error['company'] = $this->module->l('Company name is not given', 'redirect');
                } else {
                    $InvoiceAddress->company = Tools::getValue('company');
                    $InvoiceAddress->update();
                }

                if (Tools::getValue('dni') == '') {
                    $error['dni'] = $this->module->l('CoC-number is not given', 'redirect');
                } else {
                    $InvoiceAddress->dni = preg_replace('/[^0-9]/', '', Tools::getValue('dni'));
                    if (Validate::isDniLite($InvoiceAddress->dni)) {
                        $InvoiceAddress->update();
                    } else {
                        $error['dni'] = $this->module->l('CoC-number is not valid', 'redirect');
                    }
                }

                if (count($error)==0) {
                    $InvoiceAddress->update();
                }
            }

            if (count($error)==0) {
                $Customer->save();
            }
        }
        
        if ($customer_type == 'consumer') {
            $fields['birthday'] = array(
                'field_name' => $this->module->l('Birthday', 'redirect'),
                'field_id' => 'birthday',
                'type' => 'date',
                'value' => $Customer->birthday
            );
        }

        $fields['id_gender'] = array(
            'field_name' => $this->module->l('Gender', 'redirect'),
            'field_id' => 'id_gender',
            'type' => 'radio',
            'options' => array(
                1 => $this->module->l('Mr.', 'redirect'),
                2 => $this->module->l('Mrs.', 'redirect'),
            ),
            'value' => $Customer->id_gender
        );

        if ($customer_type == 'business') {
            $fields['company'] = array(
                'field_name' => $this->module->l('Company name', 'redirect'),
                'field_id' => 'company',
                'type' => 'text',
                'value' => $InvoiceAddress->company
            );
            $fields['dni'] = array(
                'field_name' => $this->module->l('CoC-number', 'redirect'),
                'field_id' => 'dni',
                'type' => 'text',
                'value' => $InvoiceAddress->dni
            );
        }

        if ($InvoiceCountry->iso_code == 'BE' && $InvoiceAddress->company == '') {
            # Beligum consumers
            $terms_link = 'https://www.afterpay.be/be/footer/betalen-met-afterpay/betalingsvoorwaarden';
        } elseif ($InvoiceCountry->iso_code == 'NL' && $InvoiceAddress->company !== '') {
            # Dutch consumers
            $terms_link = 'https://www.afterpay.nl/nl/algemeen/zakelijke-partners/betalingsvoorwaarden-zakelijk';
        } elseif ($InvoiceCountry->iso_code == 'NL' && $InvoiceAddress->company == '') {
            # Dutch business
            $terms_link = 'https://www.afterpay.nl/nl/algemeen/betalen-met-afterpay/betalingsvoorwaarden';
        }
        
        $checkbox_terms = sprintf("<a href='javascript:void();' 
            onclick=\"window.open('%s','AfterPay',
            'width=400,height=425,scrollbars=no,toolbar=no,location=no');
            return false\">%s</a>", $terms_link, $this->module->l('Agree with the terms of Afterpay', 'redirect'));
        
        $fields['terms'] = array(
            'field_name' => $checkbox_terms,
            'field_id' => 'terms',
            'type' => 'checkbox',
            'value' => (Tools::getValue('terms')==true) ? 'checked' : ''
        );

        $order = array();
        $order['ordernumber'] = $this->context->cart->id;
        $order['billtoaddress']['isocountrycode'] = $InvoiceCountry->iso_code;
        $order['billtoaddress']['city'] = $InvoiceAddress->city;
        $order['billtoaddress']['housenumber'] = $InvoiceAddress->address2;
        if (preg_match('/[a-zA-Z]$/', trim($InvoiceAddress->address2), $appendix)) {
            $order['billtoaddress']['housenumber'] = str_replace($appendix[0], '', trim($InvoiceAddress->address2));
            $order['billtoaddress']['housenumberaddition'] = $appendix[0];
        }
        $order['billtoaddress']['postalcode'] = $InvoiceAddress->postcode;
        $order['billtoaddress']['streetname'] = $InvoiceAddress->address1;
        $DeliveryAddress = new Address($this->context->cart->id_address_delivery);
        $ShippingCountry = new Country($DeliveryAddress->id_country);
      
        // Personal
        $order['billtoaddress']['referenceperson']['dob'] = ($customer_type == 'business')
            ? '1970-01-01T00:00:00+01:00'
            :  $Customer->birthday . "T00:00:00";
        $order['billtoaddress']['referenceperson']['email'] = $Customer->email;
        $order['billtoaddress']['referenceperson']['gender'] = ($Customer->id_gender == 1) ? 'M' : 'V';
        $bill_ref_initials = Tools::strtoupper(Tools::substr($InvoiceAddress->firstname, 0, 1));
        $order['billtoaddress']['referenceperson']['initials'] = $bill_ref_initials;
        $order['billtoaddress']['referenceperson']['isolanguage'] = $ShippingCountry->iso_code;
        $order['billtoaddress']['referenceperson']['lastname'] = $InvoiceAddress->lastname;
        $order['billtoaddress']['referenceperson']['phonenumber'] = $InvoiceAddress->phone!==''
            ? $InvoiceAddress->phone : $InvoiceAddress->phone_mobile;
        $order['shiptoaddress']['city'] = $DeliveryAddress->city;
        $order['shiptoaddress']['housenumber'] = $DeliveryAddress->address2;
        if (preg_match('/[a-zA-Z]$/', $DeliveryAddress->address2, $appendix)) {
            $order['shiptoaddress']['housenumberaddition'] = $appendix[0];
        }
        $order['shiptoaddress']['isocountrycode'] = $ShippingCountry->iso_code;
        $order['shiptoaddress']['postalcode'] = $DeliveryAddress->postcode;
        $order['shiptoaddress']['streetname'] = $DeliveryAddress->address1;
        $order['shiptoaddress']['referenceperson']['dob'] = ($customer_type == 'business')
            ? '1970-01-01T00:00:00'
            : $Customer->birthday . "T00:00:00+01:00";
        $order['shiptoaddress']['referenceperson']['email'] = $Customer->email;
        $order['shiptoaddress']['referenceperson']['gender'] = ($Customer->id_gender == 1) ? 'M' : 'V';
        $ship_ref_initials = Tools::strtoupper(Tools::substr($DeliveryAddress->firstname, 0, 1));
        $order['shiptoaddress']['referenceperson']['initials'] = $ship_ref_initials;
        $order['shiptoaddress']['referenceperson']['isolanguage'] = $ShippingCountry->iso_code;
        $order['shiptoaddress']['referenceperson']['lastname'] = $DeliveryAddress->lastname;
        $order['shiptoaddress']['referenceperson']['phonenumber'] = $DeliveryAddress->phone!==''
            ? $DeliveryAddress->phone : $DeliveryAddress->phone_mobile;
        // Business
        $order['company']['cocnumber'] = $InvoiceAddress->dni;
        $order['company']['companyname'] = $InvoiceAddress->company;
        $order['currency'] = 'EUR';
        $order['ipaddress'] = ($_SERVER['REMOTE_ADDR']=='::1')
            ? "".rand(0, 256).".".rand(0, 256).".".rand(0, 256).".".rand(0, 256).""  // Made up for localhost/testing
            : $_SERVER['REMOTE_ADDR'];

        /* Cart here */
        $cart = new Cart((int)$this->context->cart->id);
        foreach ($cart->getProducts(false, false) as $cart_product) {
            switch ((int)$cart_product['rate']) {
                /* Our options are: 1 = high, 2 = low, 3 zero, 4 no tax */
                case 21:
                    $tax_category = 1;
                    break;
                case 6:
                    $tax_category = 2;
                    break;
                case 0:
                    $tax_category = 4;
                    break;
            }
            $Afterpay->createOrderLine(
                $cart_product['reference'],
                $cart_product['name'],
                $cart_product['quantity'],
                (int)(Tools::ps_round($cart_product['price_wt'], 2)*100),
                $tax_category
            );
        }

        if ($cart->gift==1) {
            $Afterpay->createOrderLine(
                $this->module->l('GIFT_WRAP', 'redirect'),
                $this->module->l('Gif wrapping', 'redirect'),
                1,
                (int)($cart->getGiftWrappingPrice()*100),
                1
            );
        }

        if ($cart->getTotalShippingCost()!==0) {
            $Afterpay->createOrderLine(
                $this->module->l('SHIPPING_COST', 'redirect'),
                $this->module->l('Shipping Cost', 'redirect'),
                1,
                (int)(Tools::ps_round($cart->getTotalShippingCost(), 2)*100),
                1
            );
        }

        if (count($cart->getCartRules())) {
            foreach ($cart->getCartRules() as $rule) {
                $description = ($rule['code']!=='') ? $rule['code'] : $rule['name'];
                $Afterpay->createOrderLine(
                    $rule['description'],
                    $description,
                    1,
                    (int)-(Tools::ps_round($rule['value_real'], 2)*100),
                    1
                );
            }
        }

        if (Tools::ps_round($cart->getOrderTotal(true, Cart::BOTH)*100)!==$Afterpay->total_order_amount) {
            $Afterpay->createOrderLine(
                $this->module->l('ROUND_UP', 'redirect'),
                $this->module->l('Rounding difference', 'redirect'),
                1,
                Tools::ps_round($cart->getOrderTotal(true, Cart::BOTH)*100)-$Afterpay->total_order_amount,
                3
            );
        }

        $authorization = array();
        if ($InvoiceCountry->iso_code == 'NL' && $InvoiceAddress->company == '') {
            $Afterpay->setOrder($order, 'B2C');
            $authorization['merchantid'] = Configuration::get('AFTERPAYNL_MERCHANT_ID_B2C');
            $authorization['portfolioid'] = Configuration::get('AFTERPAYNL_PORTOFOLIO_ID_B2C');
            $authorization['password'] = Configuration::get('AFTERPAYNL_PASSWORD_B2C');
        } elseif ($InvoiceCountry->iso_code == 'NL' && $InvoiceAddress->company !== '') {
            $Afterpay->setOrder($order, 'B2B');
            $authorization['merchantid'] = Configuration::get('AFTERPAYNL_MERCHANT_ID_B2B');
            $authorization['portfolioid'] = Configuration::get('AFTERPAYNL_PORTOFOLIO_ID_B2B');
            $authorization['password'] = Configuration::get('AFTERPAYNL_PASSWORD_B2B');
        } elseif ($InvoiceCountry->iso_code == 'BE' && $InvoiceAddress->company == '') {
            $Afterpay->setOrder($order, 'B2C');
            $authorization['merchantid'] = Configuration::get('AFTERPAYNL_MERCHANT_ID_B2C_BE');
            $authorization['portfolioid'] = Configuration::get('AFTERPAYNL_PORTOFOLIO_ID_B2C_BE');
            $authorization['password'] = Configuration::get('AFTERPAYNL_PASSWORD_B2C_BE');
        } else {
            $error[] = $this->module->l('This payment method is not available for your situation.', 'redirect');
        }

        if (count($error)==0 && Tools::isSubmit('accept_and_complete_personal_details')) {
            $Afterpay->doRequest($authorization, $Afterpay->modus);
            $log_data = array(
                'AfterPay::order' => $Afterpay->order,
                'AfterPay::order_lines' => $Afterpay->order_lines,
                'AfterPay::order_type' => $Afterpay->order_type,
                'AfterPay::order_type_name' => $Afterpay->order_type_name,
                'AfterPay::order_type_function' => $Afterpay->order_type_function,
                'AfterPay::order_request' => $Afterpay->order_request,
                'AfterPay::order_result' => $Afterpay->order_result,
            );
            
            $this->log($Customer->lastname, $log_data);

            if ($Afterpay->order_result->return->resultId == 0) {
                // Joy

                /**
                 *  @todo   if the shop allows it, use `Configuration::get('AFTERPAYNL_OS_ID');` it enables the shop
                 *          to manage its own AfterPay order state.
                 */
                $totalOrderAmount = (float)$Afterpay->order->totalOrderAmount/100;
                $cart = new Cart((int)$this->context->cart->id);
                $this->module->validateOrder(
                    $cart->id,
                    Configuration::get('PS_OS_PAYMENT'),
                    $totalOrderAmount,
                    $this->module->displayName,
                    null,
                    array(),
                    (int)Context::getContext()->currency->id,
                    false,
                    Context::getContext()->customer->secure_key
                );

                $cart_id = $cart->id;
                $module_id = $this->module->id;
                $order_id = Order::getOrderByCartId((int)$cart->id);
                $secure_key = Context::getContext()->customer->secure_key;
                Tools::redirect('index.php?controller=order-confirmation&id_cart='.
                    $cart_id.'&id_module='.$module_id.'&id_order='.$order_id.'&key='.$secure_key);
            } else {
                // Trouble

                $this->context->smarty->assign('path', '
                    <a href="'.$this->context->link->getPageLink('order', null, null, 'step=3').'">'.
                        $this->module->l('Payment', 'redirect').'</a>
                    <span class="navigation-pipe">&gt;</span>'.$this->module->l('Error', 'redirect'));
                                   
                $return_link = ($Afterpay->order_result->return->resultId == 3)
                    ? $this->context->link->getPageLink('order', null, null, 'step=5')
                    : $this->context->link->getPageLink('order', null, null, 'step=3');

                $technical_error = array(
                    array('message' => 'Technische fout'),
                    array('description' => 'Er heeft zich een technische fout voorgedaan, neem contact op met ons'),
                );

                $message = isset($Afterpay->order_result->return->messages)
                    ? $Afterpay->order_result->return->messages
                    : $technical_error;

                /**
                 *  @todo "the already placed" situation, hereunder the condition:
                 */
                // if ($Afterpay->order_result->return->failures->failure == 'field.ordernumber.exists') {
                // }

                $this->context->smarty->assign(array(
                    'return_link' => $return_link,
                    'afterpay_result' => array(
                        'id' => $Afterpay->order_result->return->resultId,
                        'message' => $message
                    )
                ));
                return $this->setTemplate('afterpay_error.tpl');
            }
        }

        if (Tools::getValue('action') == 'error') {
            return $this->displayError('An error occurred while trying to redirect the customer');
        } else {
            $this->context->smarty->assign('path', '
                <a href="'.$this->context->link->getPageLink('order', null, null, 'step=3').'">'.
                    $this->module->l('Payment', 'redirect').'</a>
                <span class="navigation-pipe">&gt;</span>'.$this->module->l('Complete with Afterpay', 'redirect'));
        
            $this->context->smarty->assign(array(
                'error' => $error,
                'customer_type' => $customer_type,
                'fields' => $fields,
                'cart_id' => Context::getContext()->cart->id,
                'secure_key' => Context::getContext()->customer->secure_key,
                'request_uri' => $this->context->link->getModuleLink('afterpaynl', 'redirect'),
                'cancel' => $this->context->link->getPageLink('order', null, null, 'step=5'),
                'company' => $InvoiceAddress->company
            ));
            return $this->setTemplate('redirect.tpl');
        }
    }

    protected function displayError($message, $description = false)
    {
        /**
         * Create the breadcrumb for your ModuleFrontController.
         */
        $this->context->smarty->assign('path', '
            <a href="'.$this->context->link->getPageLink('order', null, null, 'step=3').'">'.
                $this->module->l('Payment', 'redirect').'</a>
            <span class="navigation-pipe">&gt;</span>'.$this->module->l('Error', 'redirect'));

        /**
         * Set error message and description for the template.
         */
        array_push($this->errors, $this->module->l($message), $description);

        return $this->setTemplate('error.tpl');
    }

    /**
     *  Log!
     *  @param  message
     */
    public function log($name = 'AfterPay', $message = 'No message given')
    {
        $name = preg_replace('/[^a-zA-Z0-9]/', '_', $name);
        $message = print_r($message, true);
        file_put_contents(_PS_ROOT_DIR_.'/log/afterpaynl_log_' . time() . '_' . $name . '.txt', $message);
    }
}
