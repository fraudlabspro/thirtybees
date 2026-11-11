<?php
class AuthController extends AuthControllerCore
{
    /**
     * True only when FraudLabs Pro says REJECT. Any failure lets the user through.
     */
    protected function flpIsRejected($email, $firstName, $lastName, $phone = '')
    {
        if (!Module::isEnabled('fraudlabspro')) {
            return false;
        }

        $module = Module::getInstanceByName('fraudlabspro');
        if (!$module || !method_exists($module, 'screenUser')) {
            return false;
        }

        return $module->screenUser($email, $firstName, $lastName, $phone) === 'REJECT';
    }

    protected function flpDeny($message)
    {
        $this->errors[] = $message;

        if ($this->ajax) {
            die(json_encode(['hasError' => true, 'errors' => $this->errors]));
        }
    }

    protected function processSubmitLogin()
    {
        $email  = Tools::convertEmailToIdn(trim((string) Tools::getValue('email')));
        $passwd = trim((string) Tools::getValue('passwd'));

        if (Validate::isEmail($email) && $passwd !== '') {
            $customer = new Customer();
            $auth = $customer->getByEmail($email, $passwd);

            // only screen customers whose credentials are correct
            if (Validate::isLoadedObject($auth) && $auth->active) {
                $phone = Module::getInstanceByName('fraudlabspro')->getCustomerPhone($auth);

                if ($this->flpIsRejected($auth->email, $auth->firstname, $auth->lastname, $phone)) {
                    $this->flpDeny(Tools::displayError('Authentication failed.'));
                    return;
                }
            }
        }

        parent::processSubmitLogin();
    }

    protected function processSubmitAccount()
    {
        $email = Tools::convertEmailToIdn(trim((string) Tools::getValue('email')));
        $first = trim((string) Tools::getValue('customer_firstname'));
        $last  = trim((string) Tools::getValue('customer_lastname'));
        if ($first === '') {
            $first = trim((string) Tools::getValue('firstname'));
        }
        if ($last === '') {
            $last = trim((string) Tools::getValue('lastname'));
        }

        $isGuest = Tools::isSubmit('submitGuestAccount');

        // skip when core will reject the form anyway (invalid email, missing names, existing account)
        if (Validate::isEmail($email) && $first !== '' && $last !== ''
            && ($isGuest || !Customer::customerExists($email))
        ) {
            $phone = trim((string) Tools::getValue('phone_mobile'));
            if ($phone === '') {
                $phone = trim((string) Tools::getValue('phone'));
            }

            if ($this->flpIsRejected($email, $first, $last, $phone)) {
                $this->flpDeny(Tools::displayError('We were unable to create your account. Please contact us.'));
                return;
            }
        }

        parent::processSubmitAccount();
    }
}