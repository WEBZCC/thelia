<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (version_compare(\PHP_VERSION, '7.2', '<')) {
    exit(sprintf(
        "Thelia needs at least php 7.2, but you are using php %s. Please upgrade before using Thelia.\n",
        \PHP_VERSION
    ));
}

/*
 * Thelia essential definitions
 */

if (!defined('DS')) {
    define('DS', \DIRECTORY_SEPARATOR);
}

if (!defined('THELIA_ROOT')) {
    define('THELIA_ROOT', rtrim(realpath(dirname(__DIR__)), DS).DS);
}

if (!defined('THELIA_LIB')) {
    define('THELIA_LIB', THELIA_ROOT.'core'.DS.'lib'.DS.'Thelia'.DS);
}

if (!defined('THELIA_VENDOR')) {
    define('THELIA_VENDOR', THELIA_ROOT.'vendor'.DS);
}

if (!defined('THELIA_LOCAL_DIR')) {
    define('THELIA_LOCAL_DIR', THELIA_ROOT.'local'.DS);
}

if (!defined('THELIA_CONF_DIR')) {
    define('THELIA_CONF_DIR', THELIA_LOCAL_DIR.'config'.DS);
}

if (!defined('THELIA_MODULE_DIR')) {
    define('THELIA_MODULE_DIR', THELIA_LOCAL_DIR.'modules'.DS);
}

if (!defined('THELIA_WEB_DIR')) {
    define('THELIA_WEB_DIR', THELIA_ROOT.'web'.DS);
}

if (!defined('THELIA_CACHE_DIR')) {
    define('THELIA_CACHE_DIR', THELIA_ROOT.'var'.DS.'cache'.DS);
}

if (!defined('THELIA_LOG_DIR')) {
    define('THELIA_LOG_DIR', THELIA_ROOT.'var'.DS.'log'.DS);
}

if (!defined('THELIA_SESSION_DIR')) {
    define('THELIA_SESSION_DIR', THELIA_ROOT.'var'.DS.'session'.DS);
}

if (!defined('THELIA_TEMPLATE_DIR')) {
    define('THELIA_TEMPLATE_DIR', THELIA_ROOT.'templates'.DS);
}

if (!defined('THELIA_FRONT_ASSETS_BUILD_DIR_NAME')) {
    define('THELIA_FRONT_ASSETS_BUILD_DIR_NAME', 'dist');
}

if (!defined('THELIA_FRONT_ASSETS_PUBLIC_DIR')) {
    define('THELIA_FRONT_ASSETS_PUBLIC_DIR', THELIA_WEB_DIR.THELIA_FRONT_ASSETS_BUILD_DIR_NAME.DS);
}

if (!defined('THELIA_FRONT_ASSETS_MANIFEST_PATH')) {
    define('THELIA_FRONT_ASSETS_MANIFEST_PATH', THELIA_FRONT_ASSETS_PUBLIC_DIR.'manifest.json');
}

if (!defined('THELIA_FRONT_ASSETS_ENTRYPOINTS_PATH')) {
    define('THELIA_FRONT_ASSETS_ENTRYPOINTS_PATH', THELIA_FRONT_ASSETS_PUBLIC_DIR.'entrypoints.json');
}

if (!defined('THELIA_SETUP_DIRECTORY')) {
    define('THELIA_SETUP_DIRECTORY', THELIA_ROOT.'setup'.DS);
}

if (!defined('THELIA_SETUP_WIZARD_DIRECTORY')) {
    define('THELIA_SETUP_WIZARD_DIRECTORY', THELIA_ROOT.'web'.DS.'install'.DS);
}

// this will be used in our Propel model builders
if (!defined('TheliaMain_BUILD_MODEL_PATH')) {
    define('TheliaMain_BUILD_MODEL_PATH', THELIA_CACHE_DIR.'propel'.DS.'model'.DS);
}

// this will be used in our Propel model builders
if (!defined('TheliaMain_BUILD_DATABASE_PATH')) {
    define('TheliaMain_BUILD_DATABASE_PATH', THELIA_CACHE_DIR.'propel'.DS.'database'.DS);
}

if (!file_exists(THELIA_CONF_DIR.'database.yml') && !defined('THELIA_INSTALL_MODE')) {
    $sapi = \PHP_SAPI;
    if (substr($sapi, 0, 3) == 'cli') {
        define('THELIA_INSTALL_MODE', true);
    } elseif (file_exists(THELIA_ROOT.DS.'web'.DS.'install')) {
        $request = \Thelia\Core\HttpFoundation\Request::createFromGlobals();
        header('Location: '.$request->getUriForPath('/install'));
    } else {
        header($_SERVER['SERVER_PROTOCOL'].' 500 Thelia is not installed', true, 500);
        exit(sprintf(
            "Thelia is not installed. <a href=\"%s\" target=\"_blank\">More information</a>\n",
            'http://doc.thelia.net/en/documentation/installation/index.html#using-cli-tools'
        ));
    }
}
