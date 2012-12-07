<?php
/**
 * Title Hold Logic Class
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2007.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  ILS_Logic
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Luke O'Sullivan <l.osullivan@swansea.ac.uk>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/system_classes#index_interface Wiki
 */
namespace VuFind\ILS\Logic;
use VuFind\Config\Reader as ConfigReader, VuFind\Crypt\HMAC,
    VuFind\ILS\Connection as ILSConnection;

/**
 * Title Hold Logic Class
 *
 * @category VuFind2
 * @package  ILS_Logic
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Luke O'Sullivan <l.osullivan@swansea.ac.uk>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/system_classes#index_interface Wiki
 */
class TitleHolds
{
    protected $account;
    protected $catalog;
    protected $config;
    protected $hideHoldings = array();

    /**
     * Constructor
     *
     * @param \VuFind\Auth\Manager $account Auth manager object
     * @param ILSConnection        $ils     A catalog connection
     */
    public function __construct(\VuFind\Auth\Manager $account, ILSConnection $ils)
    {
        $this->account = $account;
        $this->config = ConfigReader::getConfig();

        if (isset($this->config->Record->hide_holdings)) {
            foreach ($this->config->Record->hide_holdings as $current) {
                $this->hideHoldings[] = $current;
            }
        }

        $this->catalog = $ils;
    }

    /**
     * Public method for getting title level holds
     *
     * @param string $id A Bib ID
     *
     * @return string|bool URL to place hold, or false if hold option unavailable
     */
    public function getHold($id)
    {
        // Get Holdings Data
        if ($this->catalog) {
            $mode = ILSConnection::getTitleHoldsMode();
            if ($mode == "disabled") {
                 return false;
            } else if ($mode == "driver") {
                $patron = $this->account->storedCatalogLogin();
                if (!$patron) {
                    return false;
                }
                return $this->driverHold($id, $patron);
            } else {
                return $this->generateHold($id, $mode);
            }
        }
        return false;
    }

    /**
     * Protected method for driver defined title holds
     *
     * @param string $id     A Bib ID
     * @param array  $patron An Array of patron data
     *
     * @return mixed A url on success, boolean false on failure
     */
    protected function driverHold($id, $patron)
    {
        // Get Hold Details
        $checkHolds = $this->catalog->checkFunction("Holds");
        $data = array(
            'id' => $id,
            'level' => "title"
        );

        if ($checkHolds != false) {
            $valid = $this->catalog->checkRequestIsValid($id, $data, $patron);
            if ($valid) {
                return $this->getHoldDetails($data, $checkHolds['HMACKeys']);
            }
        }
        return false;
    }

    /**
     * Protected method for vufind (i.e. User) defined holds
     *
     * @param string $id   A Bib ID
     * @param string $type The holds mode to be applied from:
     * (disabled, always, availability, driver)
     *
     * @return mixed A url on success, boolean false on failure
     */
    protected function generateHold($id, $type)
    {
        $any_available = false;
        $addlink = false;

        $data = array(
            'id' => $id,
            'level' => "title"
        );

        // Are holds allows?
        $checkHolds = $this->catalog->checkFunction("Holds");

        if ($checkHolds != false) {

            if ($type == "always") {
                 $addlink = true;
            } elseif ($type == "availability") {

                $holdings = $this->catalog->getHolding($id);
                foreach ($holdings as $holding) {
                    if ($holding['availability']
                        && !in_array($holding['location'], $this->hideHoldings)
                    ) {
                        $any_available = true;
                    }
                }
                $addlink = !$any_available;
            }

            if ($addlink) {
                if ($checkHolds['function'] == "getHoldLink") {
                    /* Return opac link */
                    return $this->catalog->getHoldLink($id, $data);
                } else {
                    /* Return non-opac link */
                    return $this->getHoldDetails($data, $checkHolds['HMACKeys']);
                }
            }
        }
        return false;
    }

    /**
     * Get Hold Link
     *
     * Supplies the form details required to place a hold
     *
     * @param array $data     An array of item data
     * @param array $HMACKeys An array of keys to hash
     *
     * @return array          Details for generating URL
     */
    protected function getHoldDetails($data, $HMACKeys)
    {
        // Generate HMAC
        $HMACkey = HMAC::generate($HMACKeys, $data);

        // Add Params
        foreach ($data as $key => $param) {
            $needle = in_array($key, $HMACKeys);
            if ($needle) {
                $queryString[] = $key. "=" .urlencode($param);
            }
        }

        //Add HMAC
        $queryString[] = "hashKey=" . urlencode($HMACkey);
        $queryString = implode('&', $queryString);

        // Build Params
        return array(
            'action' => 'Hold', 'record' => $data['id'], 'query' => $queryString,
            'anchor' => '#tabnav'
        );
    }
}
