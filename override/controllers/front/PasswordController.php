<?php
class PasswordController extends PasswordControllerCore
{
    public function setNewPassword(Customer $customer, $password)
    {
        if (Module::isEnabled('fraudlabspro')) {
            $module = Module::getInstanceByName('fraudlabspro');

            if ($module && method_exists($module, 'screenUser')) {
                $phone = $module->getCustomerPhone($customer);

                if ($module->screenUser($customer->email, $customer->firstname, $customer->lastname, $phone) === 'REJECT') {
                    $this->errors[] = Tools::displayError('We were unable to reset your password. Please contact us.');
                    return;
                }
            }
        }

        parent::setNewPassword($customer, $password);
    }
}