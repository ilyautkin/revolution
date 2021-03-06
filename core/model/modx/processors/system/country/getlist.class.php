<?php
/*
 * This file is part of MODX Revolution.
 *
 * Copyright (c) MODX, LLC. All Rights Reserved.
 *
 * For complete copyright and license information, see the COPYRIGHT and LICENSE
 * files found in the top-level directory of this distribution.
 */

/**
 * Gets a list of country codes
 *
 * @package modx
 * @subpackage processors.system.country
 */
class modCountryGetListProcessor extends modProcessor
{
    public function checkPermissions()
    {
        return $this->modx->hasPermission('countries');
    }

    public function process()
    {
        $countryList = $this->getCountryList();

        $countries = array();
        foreach ($countryList as $iso => $country) {
            $countries[] = array(
                'iso' => strtoupper($iso),
                'country' => $country,
                'value' => $country, // Deprecated (available for BC)
            );
        }

        return $this->outputArray($countries);
    }

    public function getCountryList()
    {
        $_country_lang = array();
        include $this->modx->getOption('core_path').'lexicon/country/en.inc.php';
        $ml = $this->modx->getOption('manager_language', $_SESSION, $this->modx->getOption('cultureKey', null, 'en'));
        if ($ml != 'en' && file_exists($this->modx->getOption('core_path') . 'lexicon/country/' . $ml . '.inc.php')) {
            include $this->modx->getOption('core_path') . 'lexicon/country/' . $ml . '.inc.php';
        }
        asort($_country_lang);
        $search = $this->getProperty('query', '');
        if (!empty($search)) {
            foreach ($_country_lang as $key => $value) {
                if (!stristr($value, $search)) {
                    unset($_country_lang[$key]);
                }
            }
            return $_country_lang;
        }
        $iso = $this->getProperty('iso', '');
        if (!empty($iso)) {
            foreach ($_country_lang as $key => $value) {
                if ($key != strtolower($iso)) {
                    unset($_country_lang[$key]);
                }
            }
            return $_country_lang;
        }

        return $_country_lang;
    }
}

return 'modCountryGetListProcessor';
