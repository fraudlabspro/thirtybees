<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_0($module)
{
    $overrides = [
        'AuthController'     => 'controllers/front/AuthController.php',
        'IdentityController' => 'controllers/front/IdentityController.php',
        'PasswordController' => 'controllers/front/PasswordController.php',
        'AddressController'  => 'controllers/front/AddressController.php',
    ];

    $ok = true;
    foreach ($overrides as $class => $path) {
        $live = _PS_ROOT_DIR_ . '/override/' . $path;

        // skip if this module's override is already installed
        if (file_exists($live) && strpos(file_get_contents($live), 'module: ' . $module->name) !== false) {
            continue;
        }

        try {
            $ok = $module->addOverride($class) && $ok;
        } catch (Exception $e) {
            PrestaShopLogger::addLog('FraudLabs Pro upgrade (' . $class . '): ' . $e->getMessage(), 3);
            $ok = false;
        }
    }

    // only needed if a released version ever registered these hooks
    $module->unregisterHook('actionAuthentication');
    $module->unregisterHook('actionBeforeAuthentication');

    return $ok;
}