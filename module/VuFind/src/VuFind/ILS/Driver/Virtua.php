<?php

/**
 * VTLS Virtua Driver
 *
 * PHP version 8
 *
 * Copyright (C) University of Southern Queensland 2008.
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
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Greg Pendlebury <vufind-tech@lists.sourceforge.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */

namespace VuFind\ILS\Driver;

use VuFind\Date\DateException;
use VuFind\Exception\ILS as ILSException;

use function count;
use function in_array;
use function is_array;
use function sprintf;
use function strlen;

/**
 * VTLS Virtua Driver
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Greg Pendlebury <vufind-tech@lists.sourceforge.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */
class Virtua extends AbstractBase implements \VuFindHttp\HttpServiceAwareInterface
{
    use \VuFindHttp\HttpServiceAwareTrait;

    /**
     * Oracle connection
     *
     * @var \VuFind\Connection\Oracle
     */
    protected $db;

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        if (empty($this->config)) {
            throw new ILSException('Configuration needs to be set.');
        }

        // Define Database Name
        $tns = '(DESCRIPTION=' .
                 '(ADDRESS_LIST=' .
                   '(ADDRESS=' .
                     '(PROTOCOL=TCP)' .
                     '(HOST=' . $this->config['Catalog']['host'] . ')' .
                     '(PORT=' . $this->config['Catalog']['port'] . ')' .
                   ')' .
                 ')' .
                 '(CONNECT_DATA=' .
                   '(SERVICE_NAME=' . $this->config['Catalog']['service'] . ')' .
                 ')' .
               ')';
        $this->db = new \VuFind\Connection\Oracle(
            $this->config['Catalog']['user'],
            $this->config['Catalog']['password'],
            $tns
        );
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @throws ILSException
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
        $holding = [];

        // Strip off the prefix from vtls exports
        $db_id = str_replace('vtls', '', $id);

        // Build SQL Statement
        $sql = 'SELECT d.itemid AS item_id, c.due_date, s.name AS status, ' .
                      's.status_code, l.name AS location, ' .
                      'SUBSTR(d.location, 0, 1) as camp_id, ' .
                      "DECODE(h.req_num, null, 'N', 'Y') AS reserve, " .
                      'b.call_number AS bib_call_num, ' .
                      'i.call_number AS item_call_num ' .
               'FROM dbadmin.itemdetl2 d, dbadmin.location l, ' .
                    'dbadmin.statdetl sd, dbadmin.item_status s, ' .
                    'dbadmin.circdetl c, dbadmin.bibliographic_fields b, ' .
                    'dbadmin.item_call_number i, ' .

                    '(SELECT d1.itemid, MAX(h1.request_control_number) AS req_num ' .
                    ' FROM   dbadmin.itemdetl2 d1, dbadmin.hlrcdetl h1 ' .
                    ' WHERE (d1.itemid = h1.itemid ' .
                    '    OR (d1.bibid  = h1.bibid ' .
                    '        AND ' .
                    '        h1.itemid is null)) ' .
                    '   AND  d1.bibid  = :bib_id ' .
                    ' GROUP BY d1.itemid ' .
                    ') h ' .

               'WHERE d.location = l.location_id ' .
               'AND   d.itemid   = sd.itemid (+) ' .
               'AND   sd.stat    = s.status_code (+) ' .
               'AND   d.itemid   = c.itemid (+) ' .
               'AND   d.itemid   = h.itemid (+) ' .
               'AND   d.itemid   = i.itemid (+) ' .
               'AND   d.bibid    = b.bib_id ' .
               'AND   d.bibid    = :bib_id';

        // Bind our bib_id and execute
        $fields = ['bib_id:string' => $db_id];
        $result = $this->db->simpleSelect($sql, $fields);

        // If there are no results, lets try again because it has no items
        if (count($result) == 0) {
            $sql = 'SELECT b.call_number ' .
                   'FROM dbadmin.bibliographic_fields b ' .
                   'WHERE b.bib_id = :bib_id';
            $result = $this->db->simpleSelect($sql, $fields);

            if (count($result) > 0) {
                $new_holding = [
                    'id'           => $id,
                    'availability' => false,
                    'reserve'      => 'Y',
                    'status'       => null,
                    'location'     => 'Toowoomba',
                    'campus'       => 'Toowoomba',
                    'callnumber'   => $result[0]['CALL_NUMBER'],
                    ];

                switch ($result[0]['CALL_NUMBER']) {
                    case 'ELECTRONIC RESOURCE':
                        $new_holding['availability'] = true;
                        $new_holding['status']       = null;
                        $new_holding['location']     = 'Online';
                        $new_holding['reserve']      = 'N';
                        $holding[] = $new_holding;
                        return $holding;
                        break;
                    case 'ON ORDER':
                        $new_holding['status']       = 'ON ORDER';
                        $new_holding['location']     = 'Pending...';
                        $holding[] = $new_holding;
                        return $holding;
                        break;
                    case 'ORDER CANCELLED':
                        $new_holding['status']       = 'ORDER CANCELLED';
                        $new_holding['location']     = 'None';
                        $holding[] = $new_holding;
                        return $holding;
                        break;
                    case 'MISSING':
                        $new_holding['status']       = 'MISSING';
                        $new_holding['location']     = 'Unknown';
                        $holding[] = $new_holding;
                        return $holding;
                        break;

                    default:
                        // Still haven't found it. Let's check if it has a serials
                        // holding location
                        $call_number = $result[0]['CALL_NUMBER'];
                        $sql = 'SELECT l.name, ' .
                            'SUBSTR(l.location_id, 0, 1) as camp_id ' .
                            'FROM dbadmin.holdlink h, location l ' .
                            'WHERE h.location = l.location_id ' .
                            'AND h.bibid = :bib_id';
                        $result = $this->db->simpleSelect($sql, $fields);

                        if (count($result) > 0) {
                            foreach ($result as $r) {
                                $tmp_holding = $new_holding;
                                // TODO: create a configuration file mechanism for
                                // specifying locations so we can eliminate these
                                // hard-coded USQ-specific values.
                                switch ($r['CAMP_ID']) {
                                    case 4:
                                        $campus = 'Fraser Coast';
                                        break;
                                    case 5:
                                        $campus = 'Springfield';
                                        break;
                                    default:
                                        $campus = 'Toowoomba';
                                        break;
                                }

                                $tmp_holding['status']     = 'Not For Loan';
                                $tmp_holding['location']   = $r['NAME'];
                                $tmp_holding['reserve']    = 'N';
                                $tmp_holding['campus']     = $campus;
                                $tmp_holding['callnumber'] = $call_number;
                                $holding[] = $tmp_holding;
                            }
                            return $holding;
                        } else {
                            // Still haven't found anything? Return nothing then...
                            return $holding;
                        }
                        break;
                }
            } else {
                // Still haven't found anything? Return nothing then...
                return $holding;
            }
        }

        // Build Holdings Array
        foreach ($result as $row) {
            // TODO: create a configuration file mechanism for specifying locations
            // so we can eliminate these hard-coded USQ-specific values.
            switch ($row['CAMP_ID']) {
                case 4:
                    $campus = 'Fraser Coast';
                    break;
                case 5:
                    $campus = 'Springfield';
                    break;
                default:
                    $campus = 'Toowoomba';
                    break;
            }

            // If it has a due date... not available
            if ($row['DUE_DATE'] != null) {
                $available = false;
            } else {
                // All these statuses are also unavailable
                // TODO: make these configurable through Virtua.ini.
                switch ($row['STATUS_CODE']) {
                    case '5402':  // '24 hour hold'
                    case '4401':  // 'At Repair'
                    case '5400':  // 'Being Processed'
                    case '2101':  // 'Damaged Item'
                    case '7400':  // 'Fraser Coast only'
                    case '5700':  // 'IN TRANSIT'
                    case '7700':  // 'Invoiced'
                    case '3400':  // 'Invoiced - Re-ordered'
                    case '4600':  // 'LONG OVERDUE'
                    case '4700':  // 'MISSING'
                    case '4705':  // 'ON HOLD'
                    case '5710':  // 'REQUESTED FOR HOLD'
                    case '5401':  // 'Staff Use'
                        $available = false;
                        break;
                        // Otherwise it's available
                    case '7200':  // 'External Loan Only'
                    case '3100':  // 'In Library use only'
                    case '2700':  // 'Limited Loan'
                    case '2701':  // 'Not For Loan'
                    case '2100':  // 'Not for loan'
                    case '5401':  // 'On Display'
                    default:
                        $available = true;
                        break;
                }
            }

            $holding[] = [
                'id'           => $id,
                'availability' => $available,
                'status'       => $row['STATUS'],
                'location'     => $row['LOCATION'],
                'reserve'      => $row['RESERVE'],
                'campus'       => $campus,
                'callnumber'   => $row['BIB_CALL_NUM'],
                ];
        }

        return $holding;
    }

    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $idList The array of record ids to retrieve the status for
     *
     * @throws ILSException
     * @return array        An array of getStatus() return values on success.
     */
    public function getStatuses($idList)
    {
        $status = [];
        foreach ($idList as $id) {
            $status[] = $this->getStatus($id);
        }
        return $status;
    }

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id      The record id to retrieve the holdings for
     * @param ?array $patron  Patron data
     * @param array  $options Extra options (not currently used)
     *
     * @throws DateException
     * @throws ILSException
     * @return array         On success, an associative array with the following
     * keys: id, availability (boolean), status, location, reserve, callnumber,
     * duedate, number, barcode.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getHolding($id, ?array $patron = null, array $options = [])
    {
        // Strip off the prefix from vtls exports
        $db_id = str_replace('vtls', '', $id);
        $fields = ['bib_id:string' => $db_id];

        $holds = 'SELECT d1.itemid, MAX(h1.request_control_number) AS req_num ' .
            'FROM   dbadmin.itemdetl2 d1, dbadmin.hlrcdetl h1 ' .
            'WHERE  d1.itemid = h1.itemid ' .
            'AND    d1.bibid  = :bib_id ' .
            'GROUP BY d1.itemid';

        $bib_reqs = 'SELECT h.bibid, count(*) as bib_req ' .
            'FROM   hlrcdetl h ' .
            'WHERE  h.itemid = 0 ' .
            'GROUP BY h.bibid';

        $item_reqs = 'SELECT h.itemid, count(*) as item_req ' .
            'FROM   hlrcdetl h ' .
            'WHERE  h.itemid <> 0 ' .
            'GROUP BY h.itemid';

        $issues = 'SELECT MAX(s.issue_id) AS latest_issue, h.bibid ' .
            'FROM   serials_issue s, holdlink h ' .
            'WHERE  h.bibid      = :bib_id ' .
            'AND    h.holdingsid = s.holdingsid ' .
            'GROUP BY h.bibid';

        $reserve_class = 'SELECT DISTINCT item_id, item_class ' .
            ' FROM reserve_item_v';

        // Build SQL Statement
        $sql = 'SELECT d.itemid as item_id, d.copyno, d.barcode, c.due_date, ' .
            's.name as status, s.status_code, ' .
            'l.name as location, l.location_id, b.call_number as bib_call_num, ' .
            'i.call_number as item_call_num, ' .
            'iss.latest_issue, r.item_class as reserve_item_class, ' .
            'ic.item_class, d.units, ' .
            'br.bib_req, ir.item_req ' .
            'FROM   dbadmin.itemdetl2 d, dbadmin.location l, ' .
            'dbadmin.statdetl sd, dbadmin.item_status s, ' .
            'dbadmin.circdetl c, dbadmin.bibliographic_fields b, ' .
            'dbadmin.item_call_number i, item_class_v ic, ' .
            "($holds) h, ($bib_reqs) br, ($item_reqs) ir, ($issues) iss, " .
            "($reserve_class) r " .
            'WHERE  d.location  = l.location_id ' .
            'AND    d.itemclass = ic.item_class_id ' .
            'AND    d.itemid    = sd.itemid (+) ' .
            'AND    sd.stat     = s.status_code (+) ' .
            'AND    d.itemid    = c.itemid (+) ' .
            'AND    d.itemid    = h.itemid (+) ' .
            'AND    d.bibid     = br.bibid (+) ' .
            'AND    d.itemid    = ir.itemid (+) ' .
            'AND    d.itemid    = i.itemid (+) ' .
            'AND    d.itemid    = r.item_id (+) ' .
            'AND    d.bibid     = iss.bibid (+) ' .
            'AND    d.bibid     = b.bib_id ' .
            'AND    d.bibid     = :bib_id ' .
            'ORDER BY l.location_id, d.units_sort_form desc, d.copyno';
        //print "<div style='display:none;'>$sql</div>";

        $result = $this->db->simpleSelect($sql, $fields);
        if ($result === false) {
            throw new ILSException($this->db->getHtmlError());
        }

        // Build Holdings Array
        $holding = [];
        foreach ($result as $row) {
            // If it's reserved or has a due date... not available
            if ($row['DUE_DATE'] != null || $row['REQ_COUNT'] != null) {
                $available = false;
            } else {
                // All these statuses are also unavailable
                // TODO: Make this configurable through Virtua.ini.
                switch ($row['STATUS']) {
                    case 'Fraser Coast only':
                    case 'Being Processed':
                    case 'Not For Loan':
                    case 'Not for loan':
                    case 'Invoiced':
                    case 'IN TRANSIT':
                    case 'Staff Use':
                    case 'MISSING':
                    case 'Damaged Item':
                    case 'At Repair':
                    case 'ON ORDER':
                    case 'ON HOLD':
                    case 'Springfield Only':
                    case 'Part Order Received':
                        $available = false;
                        break;
                        // Otherwise it's available
                    default:
                        $available = true;
                        break;
                }
            }

            // Call number
            if ($row['ITEM_CALL_NUM'] != null) {
                $call_num = $row['ITEM_CALL_NUM'];
            } else {
                $call_num = $row['BIB_CALL_NUM'];
            }

            $temp = [
                'id'            => $id,
                'availability'  => $available,
                'status'        => $row['STATUS'],
                'status_code'   => $row['STATUS_CODE'],
                'location'      => $row['LOCATION'],
                'location_code' => $row['LOCATION_ID'],
                'reserve'       => $row['ITEM_REQ'] + $row['BIB_REQ'],
                'callnumber'    => $call_num,
                'duedate'       => $row['DUE_DATE'],
                'number'        => $row['COPYNO'],
                'barcode'       => $row['BARCODE'],
                'itemclass'     => $row['ITEM_CLASS'],
                'units'         => $row['UNITS'],
                'resitemclass'  => $row['RESERVE_ITEM_CLASS'],
                ];

            // Add to the holdings array
            $holding[] = $temp;
        }

        return (count($holding) != 0 && $patron['id'] != null)
            ? $this->checkHoldAllowed($patron['id'], $holding) : $holding;
    }

    /**
     * Check if this patron is allowed to place a request.
     *   - Return the holdings array with true/false and a reason.
     *
     * Because of the location comparisons with the patron's
     *   location that occur here we also take the opportunity
     *   to push their "Home" location to the top.
     *
     * @param string $patron_id ID of patron
     * @param array  $holdings  Holdings information from getHolding
     *
     * @return array            Holdings info augmented with req_allowed field
     */
    protected function checkHoldAllowed($patron_id, $holdings)
    {
        // Get the patron type
        $sql = 'SELECT p.patron_type_id ' .
            'FROM   patron_type_patron p, patron_barcode b ' .
            'WHERE  b.patron_id = p.patron_id ' .
            'AND    b.barcode   = :patron';
        $fields = ['patron:string' => $patron_id];
        $result = $this->db->simpleSelect($sql, $fields);

        // We should have 1 row and only 1 row.
        if (count($result) != 1) {
            return $holdings;
        }
        $patron_type = $result[0]['PATRON_TYPE_ID'];

        // A matrix of patron types and locations
        // TODO: Make this configurable through Virtua.ini.
        $type_list = [
            'Externals'    => ['AX', 'AD', 'BX', 'BD', 'EX', 'ED', 'GX', 'GD',
                'RX', 'SX', 'SD', 'XS', 'CC', 'RD'],
            'Super User'   => ['LP', 'OC'],
            // 1  => Toowoomba
            '1' => ['AU', 'AM', 'BU', 'BM', 'EU', 'EM', 'GU', 'GM', 'RI', 'SU',
                'SM', 'SC', 'RB', 'OT', 'ST', 'FC', 'LS'],
            // 5  => Springfield
            '5' => ['US', 'ES', 'PS', 'AS', 'GS', 'TS', 'TAS', 'EPS', 'XVS',
                'XPS'],
            // 4  => Fraser Coast
            '4' => ['UF', 'PF', 'AF'],
            ];
        // Where is the patron from?
        $location = '';
        foreach ($type_list as $loc => $patron_types) {
            if (in_array($patron_type, $patron_types)) {
                $location = $loc;
            }
        }
        // Requestable Statuses
        // TODO: Make this configurable through Virtua.ini.
        $status_list = [
            '4401', // At Repair
            '4705', // ON HOLD
            '5400', // Being Processed
            '5401', // On Display
            '5402', // 24 Hour Hold
            '5700',  // IN TRANSIT
            ];
        // Who can place reservations on available items
        $available_locs = [
            '1' => ['5', '4'],
            '4' => [],
            '5' => [],
            ];
        // Who can place reservations on UNavailable items
        $unavailable_locs = [
            '1' => ['1', '5', '4'],
            '4' => [],
            '5' => ['5'],
            ];
        // Who can place reservations on STATUS items
        $status_locs = [
            '1' => ['1', '5', '4'],
            '4' => [],
            '5' => ['5'],
            ];

        // Set a flag for super users, better then
        //  the full function call inside the loop
        if (in_array($patron_type, $type_list['Super User'])) {
            $super_user = true;
        } else {
            $super_user = false;
        }
        // External Users cannot place a request
        if (in_array($patron_type, $type_list['Externals'])) {
            return $holdings;
        }

        /*
         * Finished our basic tests, the real logic starts here
         */
        $sorted = []; // We'll put items from the patron's location in here
        $return = []; // Everything else in here
        foreach ($holdings as $h) {
            $item_loc_code = null;
            // Super Users (eg. Off-Camp, and Lending) can request anything
            if ($super_user) {
                $h['req_allowed'] = true;
            } else {
                // Everyone else we need to do some lookups

                // Can't find their location?
                if ($location == '') {
                    $h['req_allowed'] = false;
                } else {
                    // Known location
                    $can_req = false;
                    // Details about this item -- note that we use 1/0 for
                    // $item_is_out since digits display better in on screen
                    // debugging than booleans.
                    $item_is_out      = $h['duedate'] ? '1' : '0';
                    $item_loc_code    = substr($h['location_code'], 0, 1);
                    $item_stat_code   = $h['status_code'];

                    // The item is on loan ...
                    if ($item_is_out) {
                        // ... and has a requestable status or no status ...
                        if (
                            in_array($item_stat_code, $status_list)
                            || $item_stat_code === null
                        ) {
                            // ... can this user borrow on loan items at this
                            // location?
                            $can_req = in_array(
                                $location,
                                $unavailable_locs[$item_loc_code]
                            );
                        }
                    } else {
                        // The item is NOT on loan ...

                        // ... and has a requestable status ...
                        if (in_array($item_stat_code, $status_list)) {
                            // ... can the user borrow status items at this location?
                            $can_req
                                = in_array($location, $status_locs[$item_loc_code]);
                        } else {
                            // ... and DOESN'T have a requestable status ...
                            if ($item_stat_code !== null) {
                                // ... but has a status, so it can't be requested.
                            } else {
                                // ... can the user borrow available items at this
                                // location?
                                $can_req = in_array(
                                    $location,
                                    $available_locs[$item_loc_code]
                                );
                            }
                        }
                    }
                    /* DEBUGGING */
                    //$can_req = $can_req ? "Y" : "N";
                    //$h['req_allowed'] = "O:$item_is_out
                    // L:$item_loc_code S:$item_stat_code : $can_req";
                    /* Normal Return value */
                    $h['req_allowed'] = $can_req;
                }
            }
            // Item from this patron's "Home"
            if ($item_loc_code == $location) {
                $sorted[] = $h;
            } else {
                $return[] = $h;
            }
        } // End holdings loop
        return array_merge($sorted, $return);
    }

    /* START - Serials functions */

    /**
     * Simple utility -- retrieve data matching a code
     *
     * @param array  $data Data to search
     * @param string $code Code to search for
     *
     * @return mixed
     */
    protected function getField($data, $code)
    {
        foreach ($data as $d) {
            if ($d['code'] == $code) {
                return $d['data'];
            }
        }
        return null;
    }

    /**
     * Patterns coming in here are either all chrono
     *    patterns, or no chrono patterns.
     *
     * This function takes care of the final string
     *    render for each pattern subpart.
     *
     * @param array $data Data to render
     *
     * @return string
     */
    protected function renderPartSubPattern($data)
    {
        $end_time = $start_string = null;

        // Handle empty patterns
        if (count($data) == 0) {
            return '';
        }

        // Test the first element
        $is_chrono = strpos($data['pattern'][0], '(');
        $return_string = '';

        // NON chrono
        if ($is_chrono === false) {
            $i = 0;
            foreach ($data['pattern'] as $d) {
                $return_string .= $d . ' ' . $data['data'][$i] . ' ';
                $i++;
            }
        } else {
            // Chrono
            // Important note: strtotime() expects
            // 01/02/2000 = 2nd Jan 2000
            // 01-02-2000 = 1st Feb 2000 <= Use hyphens
            $pattern = implode('', $data['pattern']);
            switch (strtolower(trim($pattern))) {
                // Error case
                case '':
                    return null;
                    break;
                    // Year only
                case '(year)':
                    return $data['data'][0] . ' ';
                    break;
                    // Year + Month
                case '(year)(month)':
                    $months = explode('-', $data['data'][1]);
                    $m = count($months);
                    $years  = explode('-', $data['data'][0]);
                    $y = count($years);
                    $my = $m . $y;

                    $start_time = strtotime('01-' . $months[0] . '-' . $years[0]);
                    $end_string = 'F Y';

                    switch ($my) {
                        // January 2000 - February 2001
                        case '22':
                            $start_string = 'F Y';
                            $end_time
                                = strtotime('01-' . $months[1] . '-' . $years[1]);
                            break;
                            // January - February 2000
                        case '21':
                            $start_string = 'F';
                            $end_time
                                = strtotime('01-' . $months[1] . '-' . $years[0]);
                            break;
                            // January 2000
                        case '11':
                            $start_string = 'F Y';
                            $end_time = null;
                            break;
                            // January 2000 - January 2001
                        case '12':
                            $start_string = 'F Y';
                            $end_time
                                = strtotime('01-' . $months[0] . '-' . $years[1]);
                            break;
                    }
                    if ($end_time != null) {
                        return date($start_string, $start_time) . ' - ' .
                            date($end_string, $end_time);
                    } else {
                        return date($start_string, $start_time);
                    }
                    break;
                    // Year + Month + Day
                case '(year)(month)(day)':
                    $days   = explode('-', $data['data'][2]);
                    $d = count($days);
                    $months = explode('-', $data['data'][1]);
                    $m = count($months);
                    $years  = explode('-', $data['data'][0]);
                    $y = count($years);
                    $dmy = $d . $m . $y;

                    $start_time
                        = strtotime($days[0] . '-' . $months[0] . '-' . $years[0]);
                    $end_string = 'jS F Y';

                    switch ($dmy) {
                        // 01 January 2000
                        case '111':
                            $start_string = 'jS F Y';
                            $end_time = null;
                            break;
                            // 01 January 2000 - 01 January 2001
                        case '112':
                            $start_string = 'jS F Y';
                            $end_time = strtotime(
                                $days[0] . '-' . $months[0] . '-' . $years[1]
                            );
                            break;
                            // 01 January - 01 February 2000
                        case '121':
                            $start_string = 'jS F';
                            $end_time = strtotime(
                                $days[0] . '-' . $months[1] . '-' . $years[0]
                            );
                            break;
                            // 01 January 2000 - 01 February 2001
                        case '122':
                            $start_string = 'jS F Y';
                            $end_time = strtotime(
                                $days[0] . '-' . $months[1] . '-' . $years[1]
                            );
                            break;
                            // 01 - 02 January 2000
                        case '211':
                            $start_string = 'jS';
                            $end_time = strtotime(
                                $days[1] . '-' . $months[0] . '-' . $years[0]
                            );
                            break;
                            // 01 January 2000 - 02 January 2001
                        case '212':
                            $start_string = 'jS F Y';
                            $end_time = strtotime(
                                $days[1] . '-' . $months[0] . '-' . $years[1]
                            );
                            break;
                            // 01 January - 02 February 2000
                        case '221':
                            $start_string = 'jS F';
                            $end_time = strtotime(
                                $days[1] . '-' . $months[1] . '-' . $years[0]
                            );
                            break;
                            // 01 January 2000 - 02 February 2001
                        case '222':
                            $start_string = 'jS F Y';
                            $end_time = strtotime(
                                $days[1] . '-' . $months[1] . '-' . $years[1]
                            );
                            break;
                    }
                    if ($end_time != null) {
                        return date($start_string, $start_time) . ' - ' .
                            date($end_string, $end_time);
                    } else {
                        return date($start_string, $start_time);
                    }
                    break;
                default:
                    $i = 0;
                    foreach ($data['pattern'] as $d) {
                        $return_string .= $d . ' ' . $data['data'][$i] . ' ';
                        $i++;
                    }
                    break;
            }
        }

        return $return_string;
    }

    /**
     * Breaks up the full pattern into chrono and other
     *   chrono = (year) etc... ie. gets replaced inline
     *   other  = most enum holdings or 'Pt.'... ie. get concatenated
     *
     *   The same sub function handles both, but they must be
     *    sent in like groups.
     *
     * @param array $data Data to render
     *
     * @return string
     */
    protected function renderSubPattern($data)
    {
        $return_string = '';
        $sub_pattern = [];
        $i = 0;
        foreach ($data['pattern'] as $p) {
            // Is this chrono pattern element?
            $is_ch_pattern = strpos($p, '(');

            // If it's not, render what we have so far
            //   and clear the array
            if ($is_ch_pattern === false) {
                $return_string .= $this->renderPartSubPattern($sub_pattern);
                $sub_pattern = [];
            }

            // Add the current element to the array
            $sub_pattern['pattern'][] = $data['pattern'][$i];
            $sub_pattern['data'][]    = $data['data'][$i];

            // Now if the current element is not a
            //   chrono pattern element we render it
            //   on it's own and clear the array again
            if ($is_ch_pattern === false) {
                $return_string .= $this->renderPartSubPattern($sub_pattern);
                $sub_pattern = [];
            }
            $i++;
        }
        // Render the last segment of the array
        $return_string .= $this->renderPartSubPattern($sub_pattern);
        return $return_string;
    }

    /**
     * Currently used to handled note SUBfields
     * eg. 863/z, not 866 generally
     *   but anything non enum and chrono
     *   related ends up here.
     *
     * @param array $data Data to render
     *
     * @return array
     */
    protected function renderOtherPattern($data)
    {
        $return = [];
        $i = 0;
        foreach ($data['data'] as $d) {
            switch ($data['pattern_code'][$i]) {
                case 'z':
                    $return['notes'][] = $d;
                    break;
                default:
                    $return[$data['pattern_code'][$i]][] = $d;
                    break;
            }
            $i++;
        }
        return $return;
    }

    /**
     * Renders individual holdings against a pattern
     *   Note fields and prediction patterns are handled
     *   separately
     *
     * @param array $patterns Pattern data
     * @param array $field    Field data
     *
     * @return array
     */
    protected function renderPattern($patterns, $field)
    {
        $return = [];
        // Check we have a pattern and the pattern exists
        if (isset($field['pattern']) && isset($patterns[$field['pattern']])) {
            // Enumeration, Chonology and Other fields
            $enum_chrono = [
                'a', 'b', 'c', 'd', 'e', 'f', 'i', 'j', 'k', 'l', 'm',
            ];
            $this_en_ch  = ['pattern' => [], 'data' => []];
            $this_other  = ['pattern' => [], 'data' => []];

            $pattern = $patterns[$field['pattern']];
            // Foreach subfield
            foreach ($field['data'] as $d) {
                // Get the pattern for the subfield
                $p = $this->getField($pattern, $d['code']);
                // Put into the sub pattern
                // ... Enumeration/Chronology
                if (in_array($d['code'], $enum_chrono)) {
                    $this_en_ch['pattern_code'][] = $d['code'];
                    $this_en_ch['pattern'][] = $p;
                    $this_en_ch['data'][] = $d['data'];
                } else {
                    // ... Other
                    $this_other['pattern_code'][] = $d['code'];
                    $this_other['pattern'][] = $p;
                    $this_other['data'][] = $d['data'];
                }

                $return['en_ch'] = $this->renderSubPattern($this_en_ch);
                $return['other'] = $this->renderOtherPattern($this_other);
            }
        } else {
            // Otherwise just return the a subfield as a note
            $return['other']['notes'][] = $this->getField($field['data'], 'a');
        }
        return $return;
    }

    /**
     * A function turning holdings marc into an array of display ready strings.
     *
     * @param array $holdings_marc Holdings data from MARC.
     *
     * @return array
     */
    protected function renderSerialHoldings($holdings_marc)
    {
        // Convert to one line per tag
        $data_set = [];
        foreach ($holdings_marc as $row) {
            if (
                $row['SUBFIELD_DATA'] != null
                && trim($row['SUBFIELD_DATA']) != ''
            ) {
                $data_set[$row['FIELD_SEQUENCE']][] = [
                    'tag'  => trim($row['FIELD_TAG']),
                    'code' => trim($row['SUBFIELD_CODE']),
                    'data' => trim($row['SUBFIELD_DATA']),
                ];
            }
        }

        // Prepare the set for sorting on '8' subfields, also move the tag data out
        $sort_set = [];
        // Loop through each sequence
        foreach ($data_set as $row) {
            $tag  = '';
            $data = [];
            $sort_rule = '';
            $sort_order = '';
            // And each subfield
            foreach ($row as $subfield) {
                // Found the '8' subfield
                if ($subfield['code'] == 8) {
                    // Grab the tag for this sequence whilst here
                    $tag  = $subfield['tag'];
                    $sort = explode('.', $subfield['data']);
                    $sort_rule  = $sort[0];
                    $sort_order = $sort[1] ?? 0;
                    $sort_order = sprintf('%05d', $sort_order);
                } else {
                    // Everything else goes in the data bucket
                    $data[] = [
                        'code' => $subfield['code'],
                        'data' => $subfield['data'],
                    ];
                }
            }
            $sort_set[$sort_rule . '.' . $sort_order] = [
                'tag'  => $tag,
                'data' => $data,
            ];
        }

        // Sort the float array
        krsort($sort_set);

        // Remove the prediction patterns from the list
        //  and drop sort orders or holdings.
        $patterns = [];
        $holdings_data = [];
        foreach ($sort_set as $sort => $row) {
            $rule = explode('.', $sort);
            if ($row['tag'] == 853) {
                $patterns[$rule[0]] = $row['data'];
            } else {
                $holdings_data[] = [
                    'pattern' => $rule[0],
                    'data'    => $row['data'],
                ];
            }
        }

        // Render all the holdings now
        $rendered_list = [];
        foreach ($holdings_data as $row) {
            $rendered_list[] = $this->renderPattern($patterns, $row);
        }

        return $rendered_list;
    }

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @throws ILSException
     * @return array     An array with the acquisitions data on success.
     */
    public function getPurchaseHistory($id)
    {
        // Strip off the prefix from vtls exports
        $db_id = str_replace('vtls', '', $id);
        $fields = ['bib_id:string' => $db_id];

        // Let's go check if this bib id is for a serial
        $sql = 'SELECT h.holdingsid, l.name ' .
            'FROM dbadmin.holdlink h, dbadmin.location l ' .
            'WHERE h.bibid   = :bib_id ' .
            'AND h.masked    = 0 ' .
            'AND h.location  = l.location_id';

        $result = $this->db->simpleSelect($sql, $fields);

        // Results indicate serial holdings
        if (count($result) == 0) {
            return [];
        }

        $sql = 'SELECT * ' .
            'FROM dbadmin.iso_2709 i ' .
            'WHERE i.id = :hid ' .
            'AND i.idtype = 104 ' .
            "AND i.field_tag in ('853', '863', '866') " .
            'ORDER BY i.field_sequence, i.subfield_sequence';

        $data = [];
        foreach ($result as $row) {
            $fields = ['hid:string' => $row['HOLDINGSID']];
            $hresult = $this->db->simpleSelect($sql, $fields);
            $data[$row['NAME']] = $this->renderSerialHoldings($hresult);
        }

        return $data;
    }

    /**
     *  Used for TESTING only. Grabs all prediction
     *     patterns in the system for analysis
     *
     * @return array
     */
    public function getAll853()
    {
        $sql = 'SELECT * ' .
            'FROM dbadmin.iso_2709 i ' .
            'WHERE i.idtype = 104 ' .
            "AND i.field_tag in ('853') " .
            'ORDER BY i.field_sequence, i.subfield_sequence';
        $hresult = $this->db->simpleSelect($sql);
        if (count($hresult) == 0) {
            return null;
        }

        $data_set = [];
        foreach ($hresult as $row) {
            if (
                $row['SUBFIELD_DATA'] != null
                && trim($row['SUBFIELD_DATA']) != ''
            ) {
                $data_set[$row['ID'] . '_' . $row['FIELD_SEQUENCE']][] = [
                    'id'   => trim($row['ID']),
                    'code' => trim($row['SUBFIELD_CODE']),
                    'data' => trim($row['SUBFIELD_DATA']),
                    ];
            }
        }
        return $data_set;
    }

    /* END - Serials functions */

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $barcode  The patron barcode
     * @param string $password The patron password
     *
     * @throws ILSException
     * @return mixed           Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function patronLogin($barcode, $password)
    {
        $sql = 'SELECT i.id, b.barcode, i.subfield_data AS password, p.name, ' .
            'p.e_mail_address_primary, p.department ' .
            'FROM dbadmin.iso_2709 i, dbadmin.patron p, dbadmin.patron_barcode b ' .
            'WHERE i.idtype        = 105 ' .
            "AND   i.field_tag     = '015' " .
            "AND   i.subfield_code = 'b' " .
            'AND   p.patron_id     = i.id ' .
            'AND   b.patron_id     = i.id ' .
            'AND   i.id = ( ' .
            '  SELECT p.patron_id AS id ' .
            '  FROM   dbadmin.patron_barcode p ' .
            '  WHERE  UPPER(p.barcode)    = UPPER(:barcode) ' .
            ')';

        $fields = ['barcode:string' => $barcode];
        $result = $this->db->simpleSelect($sql, $fields);

        if (count($result) > 0) {
            // Valid Password
            if ($result[0]['PASSWORD'] == $password) {
                $user = [];
                $split      = strpos($result[0]['NAME'], ',');
                $last_name  = trim(substr($result[0]['NAME'], 0, $split));
                $first_name = trim(substr($result[0]['NAME'], $split + 1));
                $split      = strpos($first_name, ' ');
                if ($split !== false) {
                    $first_name = trim(substr($first_name, 0, $split));
                }

                $user['id']           = trim($result[0]['ID']);
                $user['firstname']    = trim($first_name);
                $user['lastname']     = trim($last_name);
                $user['cat_username'] = strtoupper(trim($result[0]['BARCODE']));
                $user['cat_password'] = trim($result[0]['PASSWORD']);
                $user['email']        = trim($result[0]['E_MAIL_ADDRESS_PRIMARY']);
                $user['major']        = trim($result[0]['DEPARTMENT']);
                $user['college']      = null;

                return $user;
            } else {
                // Invalid Password
                return null;
            }
        } else {
            // User not found
            return null;
        }
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $patron The patron array
     *
     * @throws ILSException
     * @return array        Array of the patron's profile data on success.
     */
    public function getMyProfile($patron)
    {
        $sql = 'SELECT p.name, p.street_address_1, p.street_address_2, p.city, ' .
            'p.postal_code, p.telephone_primary, t.name as patron_type ' .
            'FROM  dbadmin.patron_type_patron pt, dbadmin.patron p, ' .
            'dbadmin.patron_type t ' .
            'WHERE p.patron_id      = pt.patron_id ' .
            'AND   t.patron_type_id = pt.patron_type_id ' .
            'AND   p.patron_id      = :patron_id';

        $fields = ['patron_id:string' => $patron['id']];
        $result = $this->db->simpleSelect($sql, $fields);

        if (count($result) > 0) {
            $split      = strpos($result[0]['NAME'], ',');
            $last_name  = substr($result[0]['NAME'], 0, $split);
            $first_name = substr($result[0]['NAME'], $split + 1);
            $split      = strpos($result[0]['NAME'], ' ');
            if ($split !== false) {
                $first_name = substr($first_name, 0, $split);
            }

            $patron = [
                'firstname' => trim($first_name),
                'lastname'  => trim($last_name),
                'address1'  => trim($result[0]['STREET_ADDRESS_1']),
                'address2'  => trim($result[0]['STREET_ADDRESS_2']),
                'zip'       => trim($result[0]['POSTAL_CODE']),
                'phone'     => trim($result[0]['TELEPHONE_PRIMARY']),
                'group'     => trim($result[0]['PATRON_TYPE']),
                ];

            if ($result[0]['CITY'] != null) {
                if (strlen($patron['address2']) > 0) {
                    $patron['address2'] .= ', ' . trim($result[0]['CITY']);
                } else {
                    $patron['address2'] = trim($result[0]['CITY']);
                }
            }

            return $patron;
        } else {
            return null;
        }
    }

    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException
     * @throws ILSException
     * @return mixed        Array of the patron's fines on success.
     */
    public function getMyFines($patron)
    {
        $fineList = [];

        $sql = 'SELECT a.assessment_amount fine_amount, f.description, ' .
            'a.balance, a.item_due_date due_date, i.bibid bib_id ' .
            'FROM  patron_account a, fine_code_v f, itemdetl2 i ' .
            'WHERE a.state        = 0 ' .
            'AND   a.balance      > 0 ' .
            'AND   a.itemid       = i.itemid ' .
            'AND   a.fine_code_id = f.fine_code_id ' .
            'AND   a.patron_id    = :patron_id';

        $fields = ['patron_id:string' => $patron['id']];
        $result = $this->db->simpleSelect($sql, $fields);

        if (count($result) > 0) {
            foreach ($result as $row) {
                $fineList[] = [
                    'amount'   => $row['FINE_AMOUNT'] * 100,
                    'fine'     => $row['DESCRIPTION'],
                    'balance'  => $row['BALANCE'] * 100,
                    'duedate'  => $row['DUE_DATE'],
                    'id'       => 'vtls' . sprintf('%09d', (int)$row['BIB_ID']),
                    ];
            }
        }
        return $fineList;
    }

    /**
     * Get Patron Holds
     *
     * This is responsible for retrieving all holds by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException
     * @throws ILSException
     * @return array        Array of the patron's holds on success.
     */
    public function getMyHolds($patron)
    {
        $holdList = [];

        $sql = 'SELECT h.bibid, l.name pickup_location, h.pickup_any_location, ' .
            'h.date_last_needed, h.date_placed, h.request_control_number ' .
            'FROM  dbadmin.hlrcdetl h, dbadmin.location l ' .
            'WHERE h.pickup_location = l.location_id ' .
            'AND   h.patron_id       = :patron_id';

        $fields = ['patron_id:string' => $patron['id']];
        $result = $this->db->simpleSelect($sql, $fields);

        if (count($result) > 0) {
            foreach ($result as $row) {
                $holdList[] = [
                    'id'       => 'vtls' . sprintf('%09d', (int)$row['BIBID']),
                    'location' => $row['PICKUP_LOCATION'],
                    'expire'   => $row['DATE_LAST_NEEDED'],
                    'create'   => $row['DATE_PLACED'],
                    'reqnum'   => $row['REQUEST_CONTROL_NUMBER'],
                    ];
            }
        }
        return $holdList;
    }

    /**
     * Get Patron Transactions
     *
     * This is responsible for retrieving all transactions (i.e. checked out items)
     * by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException
     * @throws ILSException
     * @return array        Array of the patron's transactions on success.
     */
    public function getMyTransactions($patron)
    {
        $transList = [];

        $bib_reqs = 'SELECT h.bibid, count(*) as bib_req ' .
            'FROM   hlrcdetl h ' .
            'WHERE  h.itemid = 0 ' .
            'GROUP BY h.bibid';
        $item_reqs = 'SELECT h.itemid, count(*) as item_req ' .
            'FROM   hlrcdetl h ' .
            'WHERE  h.itemid <> 0 ' .
            'GROUP BY h.itemid';

        $sql = 'SELECT i.bibid, i.itemid, c.due_date, i.barcode, ' .
            'c.renew_count, (br.bib_req + ir.item_req) as req_count ' .
            "FROM   circdetl c, itemdetl2 i, ($bib_reqs) br, ($item_reqs) ir " .
            'WHERE  c.itemid    = i.itemid ' .
            'AND    i.bibid     = br.bibid (+) ' .
            'AND    i.itemid    = ir.itemid (+) ' .
            'AND    c.patron_id = :patron_id ' .
            'ORDER BY c.due_date';

        $fields = ['patron_id:string' => $patron['id']];
        $result = $this->db->simpleSelect($sql, $fields);

        if (count($result) > 0) {
            foreach ($result as $row) {
                $transList[] = [
                    'duedate' => $row['DUE_DATE'],
                    'barcode' => $row['BARCODE'],
                    'renew'   => $row['RENEW_COUNT'],
                    'request' => $row['REQ_COUNT'],
                    // IDs need to show as 'vtls000589589'
                    'id'      => 'vtls' . sprintf('%09d', (int)$row['BIBID']),
                    ];
            }
        }
        return $transList;
    }

    /**
     * Get Courses
     *
     * Obtain a list of courses for use in limiting the reserves list.
     *
     * @throws ILSException
     * @return array An associative array with key = ID, value = name.
     */
    public function getCourses()
    {
        $courseList = [];

        $sql = 'SELECT DISTINCT l.course_id ' .
            'FROM reserve_list_v l, reserve_item_v i ' .
            'WHERE l.Reserve_list_id = i.Reserve_list_id ' .
            'AND SYSDATE BETWEEN i.Begin_date AND i.End_date ' .
            'ORDER BY l.course_id';
        $result = $this->db->simpleSelect($sql);

        if (count($result) > 0) {
            foreach ($result as $row) {
                $courseList[] = $row['COURSE_ID'];
            }
        }

        return $courseList;
    }

    /**
     * Find Reserves
     *
     * Obtain information on course reserves.
     *
     * @param string $course ID from getCourses (empty string to match all)
     * @param string $inst   ID from getInstructors (empty string to match all)
     * @param string $dept   ID from getDepartments (empty string to match all)
     *
     * @throws ILSException
     * @return array An array of associative arrays representing reserve items.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function findReserves($course, $inst = false, $dept = false)
    {
        $recordList = [];

        $sql = 'SELECT DISTINCT d.bibid ' .
            'FROM reserve_item_v i, reserve_list_v l, itemdetl2 d ' .
            'WHERE i.Reserve_list_id = l.Reserve_list_id ' .
            'AND SYSDATE BETWEEN i.Begin_date AND i.End_date ' .
            'AND i.Item_id = d.itemid ' .
            'AND l.Course_id = :course';
        $fields = ['course:string' => $course];
        $result = $this->db->simpleSelect($sql, $fields);

        if (count($result) > 0) {
            foreach ($result as $row) {
                $recordList[] = 'vtls' . sprintf('%09d', (int)$row['BIBID']);
            }
        }

        return $recordList;
    }

    /**
     * Retrieve the opening hours for all campuses.
     *   - Used on the home page to show time information.
     *
     * @param string $fake_time Optional time string (for debugging purposes)
     *
     * @return array            Opening hours information.
     */
    public function getOpeningHours($fake_time = null)
    {
        // Change this value for debugging
        // eg. strtotime('25-12-2009') = Christmas
        if ($fake_time) {
            $time = strtotime($fake_time);
        } else {
            $time = strtotime('now');
        }
        $today = date('d-m-Y', $time);
        $time_format = 'H:i:s';

        // Fix Date Handling
        $this->db->simpleSql(
            "ALTER SESSION SET NLS_DATE_FORMAT = 'DD-MM-YY HH24:MI:SS'"
        );

        // Normal opening hours
        $sql = 'SELECT campus, open_time, close_time, status ' .
            'FROM usq_sr_open_normal n ' .
            'WHERE UPPER(dayofweek) = UPPER(:dow)';
        $fields = ['dow:string' => date('l', $time)];
        $result = $this->db->simpleSelect($sql, $fields);
        if (count($result) == 0) {
            return [];
        }

        // Create our return data structure
        $times = [];
        foreach ($result as $row) {
            // Remember times come out with no date, add in today.
            $times[$row['CAMPUS']] = [
                'open'   => "$today " .
                    date($time_format, strtotime($row['OPEN_TIME'])),
                'close'  => "$today " .
                    date($time_format, strtotime($row['CLOSE_TIME'])),
                'status' => $row['STATUS'],
                ];
        }

        // Opening hours exceptions
        $day  = strtolower(date('D', $time));
        // Lowest priority row (numericaly, ie. 1 = most important)
        $priority = 'SELECT e.campus, MIN(e.priority) as priority ' .
            'FROM   usq_sr_open_except e ' .
            "WHERE to_date(:today,'dd/mm/yyyy') " .
            'BETWEEN e.except_date_from AND e.except_date_to ' .
            "  AND app_$day = 1 " .
            'GROUP BY e.campus';
        // Retrieve Exceptions
        $sql = 'SELECT e.campus, e.open_time, e.close_time, e.status, e.reason ' .
            "FROM ($priority) p, usq_sr_open_except e " .
            'WHERE e.campus   = p.campus ' .
            'AND   e.priority = p.priority ' .
            "AND   to_date(:today,'dd/mm/yyyy') " .
            'BETWEEN e.except_date_from AND e.except_date_to ' .
            "AND   app_$day = 1";
        $fields = ['today:string' => date('d/m/Y', $time)];
        $exceptions = $this->db->simpleSelect($sql, $fields);

        foreach ($exceptions as $row) {
            $times[$row['CAMPUS']] = [
                // Remember times come out with no date, add in today.
                'open'   => "$today "
                    . date($time_format, strtotime($row['OPEN_TIME'])),
                'close'  => "$today "
                    . date($time_format, strtotime($row['CLOSE_TIME'])),
                'status' => $row['STATUS'],
                'reason' => $row['REASON'],
            ];
        }
        return $times;
    }

    /**
     * Place Hold
     *
     * Attempts to place a hold or recall on a particular item and returns
     * an array with result details or throws an exception on failure of support
     * classes
     *
     * @param array $holdDetails An array of item and patron data
     *
     * @throws ILSException
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function placeHold($holdDetails)
    {
        // Extract key values from the hold details
        $patron_id = $holdDetails['patron'];
        $req_level = $holdDetails['req_level'];
        $pickup_loc = $holdDetails['pickUpLocation'];
        $item_id = $holdDetails['item_id'];
        $last_date = $holdDetails['requiredBy'];

        // Assume an error response:
        $response = ['success' => false, 'status' => 'hold_error_fail'];

        // Validate input
        //  * Request level
        $allowed_req_levels = [
            'item'   => 0,
            'bib'    => 1,
            'volume' => 2,
            ];
        if (!in_array($req_level, array_keys($allowed_req_levels))) {
            return $response;
        }
        //  * Pickup Location
        $allowed_pickup_locs = [
            'Toowoomba'    => '10000',
            'Fraser Coast' => '40000',
            'Springfield'  => '50000',
            ];
        if (!in_array($pickup_loc, array_keys($allowed_pickup_locs))) {
            return $response;
        }
        //  * Last Date - Valid date and a future date
        $ts_last_date = strtotime($last_date);
        if ($ts_last_date == 0 || $ts_last_date <= strtotime('now')) {
            return $response;
        }

        // Still here? Guess the request is valid, lets send it to virtua
        $virtua_url = $this->getApiBaseUrl() . '?' .
            // Standard stuff
            'search=NOSRCH&function=REQUESTS&reqreqtype=0&reqtype=0' .
            '&reqscr=2&reqreqlevel=2&reqidtype=127&reqmincircperiod=' .
            // Item ID
            "&reqidno=$item_id" .
            // Patron barcode
            "&reqpatronbarcode=$patron_id" .
            // Request Level
            '&reqautoadjustlevel=' . $allowed_req_levels[$req_level] .
            // Pickup location
            '&reqpickuplocation=' . $allowed_pickup_locs[$pickup_loc] .
            // Last Date
            '&reqexpireday=' . date('j', $ts_last_date) .
            '&reqexpiremonth=' . date('n', $ts_last_date) .
            '&reqexpireyear=' . date('Y', $ts_last_date);

        // Get the response
        $result = $this->httpRequest($virtua_url);

        // Look for an error message
        $error_message = 'Your request was not processed.';
        $test = strpos($result, $error_message);

        // If we succeeded, override the default fail message with success:
        if ($test === false) {
            $response['success'] = true;
            $response['status'] = 'hold_success';
        }

        return $response;
    }

    /**
     * Get Cancel Hold Details
     *
     * In order to cancel a hold, Voyager requires the patron details an item ID
     * and a recall ID. This function returns the item id and recall id as a string
     * separated by a pipe, which is then submitted as form data in Hold.php. This
     * value is then extracted by the CancelHolds function.
     *
     * @param array $holdDetails A single hold array from getMyHolds
     * @param array $patron      Patron information from patronLogin
     *
     * @return string Data for use in a form field
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getCancelHoldDetails($holdDetails, $patron = [])
    {
        throw new \Exception('TODO: implement getCancelHoldDetails.');
    }

    /**
     * Cancel Holds
     *
     * Attempts to Cancel a hold or recall on a particular item. The
     * data in $cancelDetails['details'] is determined by getCancelHoldDetails().
     *
     * @param array $cancelDetails An array of item and patron data
     *
     * @return array               An array of data on each request including
     * whether or not it was successful and a system message (if available)
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function cancelHolds($cancelDetails)
    {
        // TODO: implement standard VuFind holds API; utilize cancelHold()
        // below as a support method.
        throw new \Exception('cancelHolds() is not supported yet.');
    }

    /**
     * Cancel a request in virtua.
     *   - Return true/false for success/failure.
     *
     * @param string $request_number ID of hold to cancel
     *
     * @return bool
     */
    protected function cancelHold($request_number)
    {
        $virtua_url = $this->getApiBaseUrl() . '?' .
            // Standard stuff
            'search=NOSRCH&function=REQUESTS&reqreqtype=1&reqtype=0' .
            '&reqscr=4&reqreqlevel=2&reqidtype=127' .
            //"&reqidno=1000651541" .
            "&reqctrlnum=$request_number";

        try {
            // Get the response
            $result = $this->httpRequest($virtua_url);
            // Invalid server response. It's probably down
        } catch (\Exception $e) {
            return false;
        }

        // Look for an error message
        $error_message = 'Your request could not be deleted.';
        $test = strpos($result, $error_message);

        // Return true unless we find the error
        if ($test === false) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Fake a virtua login on the patron's behalf.
     *   - Return a session id.
     *
     * @param array $patron Array with cat_username/cat_password keys
     *
     * @return string       Session ID
     */
    protected function fakeLogin($patron)
    {
        $virtua_url = $this->getApiBaseUrl();
        $postParams = [
            'SourceScreen' => 'INITREQ',
            'conf' => '.&#047;chameleon.conf',
            'elementcount' => '1',
            'function' => 'PATRONATTEMPT',
            'host' => $this->config['Catalog']['host_string'],
            'lng' => $this->getConfiguredLanguage(),
            'login' => '1',
            'pos' => '1',
            'rootsearch' => 'KEYWORD',
            'search' => 'NOSRCH',
            'skin' => 'homepage',
            'patronid' => $patron['cat_username'],
            'patronpassword' => $patron['cat_password'],
            'patronhost' => $this->config['Catalog']['patron_host'],
        ];

        // Get the response
        $result = $this->httpRequest($virtua_url, $postParams);
        // Now find the sessionid. There should be one in the meta tags,
        // so we can look for the first one in the document
        // eg. <meta http-equiv="Refresh" content="30000;
        // url=http://libwebtest2.usq.edu.au:80/cgi-bin/chameleon?sessionid=
        //2009071712483605131&amp;skin=homepage&amp;lng=en&amp;inst=
        //consortium&amp;conf=.%26%23047%3bchameleon.conf&amp;timedout=1" />
        $start = strpos($result, 'sessionid=') + 10;
        $end   = strpos($result, '&amp;skin=');
        return substr($result, $start, $end - $start);
    }

    /**
     * Get Renew Details
     *
     * In order to renew an item, Voyager requires the patron details and an item
     * id. This function returns the item id as a string which is then used
     * as submitted form data in checkedOut.php. This value is then extracted by
     * the RenewMyItems function.
     *
     * @param array $checkOutDetails An array of item data
     *
     * @return string Data for use in a form field
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getRenewDetails($checkOutDetails)
    {
        throw new \Exception('TODO: implement getRenewDetails');
    }

    /**
     * Renew My Items
     *
     * Function for attempting to renew a patron's items. The data in
     * $renewDetails['details'] is determined by getRenewDetails().
     *
     * @param array $renewDetails An array of data required for renewing items
     * including the Patron ID and an array of renewal IDS
     *
     * @return array              An array of renewal information keyed by item ID
     */
    public function renewMyItems($renewDetails)
    {
        $item_list = $renewDetails['details'];
        $patron = $renewDetails['patron'];

        // Get items out on loan at the moment
        $result = $this->getMyTransactions($patron);
        // Make it more accessible - by barcode
        $initial = [];
        foreach ($result as $row) {
            $initial[$row['barcode']] = $row;
        }

        // Fake a login to get an authenticated session
        $session_id = $this->fakeLogin($patron);

        $virtua_url = $this->getApiBaseUrl();

        // Have to use raw post data because of the way
        //   virtua expects the barcodes to come across.
        $post_data  = 'function=' . 'RENEWAL';
        $post_data .= '&search=' . 'PATRON';
        $post_data .= '&sessionid=' . "$session_id";
        $post_data .= '&skin=' . 'homepage';
        $post_data .= '&lng=' . $this->getConfiguredLanguage();
        $post_data .= '&inst=' . 'consortium';
        $post_data .= '&conf=' . urlencode('.&#047;chameleon.conf');
        $post_data .= '&u1=' . '12';
        $post_data .= '&SourceScreen=' . 'PATRONACTIVITY';
        $post_data .= '&pos=' . '1';
        $post_data .= '&patronid=' . $patron['cat_username'];
        $post_data .= '&patronhost='
            . urlencode($this->config['Catalog']['patron_host']);
        $post_data .= '&host='
            . urlencode($this->config['Catalog']['host_string']);
        $post_data .= '&itembarcode=' . implode('&itembarcode=', $item_list);
        $post_data .= '&submit=' . 'Renew';
        $post_data .= '&reset=' . 'Clear';

        $result = $this->httpRequest($virtua_url, null, $post_data);

        // Get items out on loan with renewed info
        $result = $this->getMyTransactions($patron);

        // Foreach item currently on loan
        $return = [];
        foreach ($result as $row) {
            // Did we even attempt to renew?
            if (in_array($row['barcode'], $item_list)) {
                // Yes, so check if the due date changed
                if ($row['duedate'] != $initial[$row['barcode']]['duedate']) {
                    $row['error'] = false;
                    $row['renew_text'] = 'Item successfully renewed.';
                } else {
                    $row['error'] = true;
                    $row['renew_text'] = 'Item renewal failed.';
                }
                $return[] = $row;
            } else {
                // No attempt to renew this item
                $return[] = $row;
            }
        }
        return $return;
    }

    /**
     * Get suppressed authority records
     *
     * @return array ID numbers of suppressed authority records in the system.
     */
    public function getSuppressedAuthorityRecords()
    {
        $list = [];

        $sql = 'select auth_id ' .
            'from state_record_authority ' .
            'WHERE STATE_ID = 1';

        $result = $this->db->simpleSelect($sql);

        if ($result === false) {
            throw new ILSException(
                'An error occurred while connecting to Virtua'
            );
        }

        foreach ($result as $row) {
            $list[] = 'vtls' . str_pad($row['AUTH_ID'], 9, '0', STR_PAD_LEFT);
        }
        return $list;
    }

    /**
     * Support method -- get base URL for API requests.
     *
     * @return string
     */
    protected function getApiBaseUrl()
    {
        // Get the iPortal server
        $host = $this->config['Catalog']['webhost'];
        $path = isset($this->config['Catalog']['cgi_token'])
            ? trim($this->config['Catalog']['cgi_token'], '/')
            : 'cgi-bin';
        return "http://{$host}/{$path}/chameleon";
    }

    /**
     * Support method -- determine the language from the configuration.
     *
     * @return string
     */
    protected function getConfiguredLanguage()
    {
        return $this->config['Catalog']['language'] ?? 'en';
    }

    /**
     * Support method -- perform an HTTP request. This will be a GET request unless
     * either $postParams or $rawPost is set to a non-null value.
     *
     * @param string $url        Target URL for request
     * @param array  $postParams Associative array of POST parameters (null for
     * none).
     * @param string $rawPost    String representing raw POST parameters (null for
     * none).
     *
     * @throws ILSException
     * @return string Response body
     */
    protected function httpRequest($url, $postParams = null, $rawPost = null)
    {
        $method = (null === $postParams && null === $rawPost) ? 'GET' : 'POST';

        try {
            $client = $this->httpService->createClient($url);
            if (is_array($postParams)) {
                $client->setParameterPost($postParams);
            }
            if (null !== $rawPost) {
                $client->setRawBody($rawPost);
                $client->setEncType('application/x-www-form-urlencoded');
            }
            $result = $client->setMethod($method)->send();
        } catch (\Exception $e) {
            $this->throwAsIlsException($e);
        }

        if (!$result->isSuccess()) {
            throw new ILSException('HTTP error');
        }

        return $result->getBody();
    }

    /* Methods yet to be implemented -- see Voyager driver for examples

    public function getNewItems($page, $limit, $daysOld, $fundId = null)

    public function getFunds()

    public function getSuppressedRecords()
     */
}
