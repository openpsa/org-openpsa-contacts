<?php
/**
 * Handler for searching duplicate groups and persons
 *
 * @package org.openpsa.contacts
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */
midcom::get()->auth->require_valid_user();

echo "<p>\n";

midcom::get()->auth->request_sudo('org.openpsa.contacts');

$pfinder = new org_openpsa_contacts_duplicates_check_person;
/* TODO: Get component configuration if possible
$dfinder->config = ;
*/
$pfinder->mark_all(true);

$gfinder = new org_openpsa_contacts_duplicates_check_group;
$gfinder->mark_all(true);

midcom::get()->auth->drop_sudo();
echo " ** ALL DONE<br/>\n";
echo "</p>\n";
