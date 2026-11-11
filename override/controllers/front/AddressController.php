<?php
class AddressController extends AddressControllerCore
{
    protected function processSubmitAddress()
    {
        if (Module::isEnabled('fraudlabspro')) {
            $customer = $this->context->customer;

            $first  = trim((string) Tools::getValue('firstname'));
            $last   = trim((string) Tools::getValue('lastname'));
            $phone  = trim((string) Tools::getValue('phone'));
            $mobile = trim((string) Tools::getValue('phone_mobile'));

            // mirror core: a logged-in customer with an invalid page token is rejected anyway
            $tokenOk = !$customer->isLogged() || $this->isTokenValid();

            if (Validate::isLoadedObject($customer)
                && $tokenOk
                && $first !== '' && $last !== ''
                && $this->flpAddressChanged($first, $last, $phone, $mobile)
            ) {
                $module = Module::getInstanceByName('fraudlabspro');

                if ($module && method_exists($module, 'screenUser')) {
                    $sendPhone = ($mobile !== '') ? $mobile : $phone;

                    if ($module->screenUser($customer->email, $first, $last, $sendPhone) === 'REJECT') {
                        $this->errors[] = Tools::displayError('We were unable to save your address. Please contact us.');

                        if ($this->ajax) {
                            $this->ajaxDie(json_encode([
                                'hasError' => true,
                                'errors'   => $this->errors,
                            ]));
                        }
                        return;
                    }
                }
            }
        }

        parent::processSubmitAddress();
    }

    /**
     * New address: always true. Edited address: true only if a field sent to the API changed.
     */
    protected function flpAddressChanged($first, $last, $phone, $mobile)
    {
        if (!Validate::isLoadedObject($this->_address)) {
            return true;
        }

        return $first  !== trim((string) $this->_address->firstname)
            || $last   !== trim((string) $this->_address->lastname)
            || $phone  !== trim((string) $this->_address->phone)
            || $mobile !== trim((string) $this->_address->phone_mobile);
    }
}