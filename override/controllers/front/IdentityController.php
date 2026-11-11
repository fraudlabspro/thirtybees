<?php
class IdentityController extends IdentityControllerCore
{
    public function postProcess()
    {
        if (Tools::isSubmit('submitIdentity') && Module::isEnabled('fraudlabspro')) {
            $customer = $this->context->customer;

            $email = Tools::convertEmailToIdn(trim((string) Tools::getValue('email')));
            $first = trim((string) Tools::getValue('firstname'));
            $last  = trim((string) Tools::getValue('lastname'));

            $emailChanged  = strcasecmp($email, (string) $customer->email) !== 0;
            $nameChanged   = $first !== (string) $customer->firstname || $last !== (string) $customer->lastname;
            $passwdChanged = (string) Tools::getValue('passwd') !== '';

            // only screen when something meaningful changed, and skip forms core will reject anyway
            if (Validate::isLoadedObject($customer)
                && Validate::isEmail($email) && $first !== '' && $last !== ''
                && (string) Tools::getValue('old_passwd') !== ''
                && ($emailChanged || $nameChanged || $passwdChanged)
            ) {
                $module = Module::getInstanceByName('fraudlabspro');

                if ($module && method_exists($module, 'screenUser')) {
                    $phone = $module->getCustomerPhone($customer);

                    if ($module->screenUser($email, $first, $last, $phone) === 'REJECT') {
                        $this->errors[] = Tools::displayError('We were unable to update your account. Please contact us.');
                        return;
                    }
                }
            }
        }

        parent::postProcess();
    }
}