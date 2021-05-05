<?php
/**
 * @package org.openpsa.contacts
 * @author Nemein Oy http://www.nemein.com/
 * @copyright Nemein Oy http://www.nemein.com/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

use midcom\datamanager\datamanager;

/**
 * OpenPSA Contact registers/user manager
 *
 * @package org.openpsa.contacts
 */
class org_openpsa_contacts_interface extends midcom_baseclasses_components_interface
implements midcom_services_permalinks_resolver
{
    /**
     * Prepares the component's indexer client
     */
    public function _on_reindex($topic, midcom_helper_configuration $config, midcom_services_indexer $indexer)
    {
        $qb_organisations = org_openpsa_contacts_group_dba::new_query_builder();
        $qb_organisations->add_constraint('orgOpenpsaObtype', '<>', org_openpsa_contacts_group_dba::MYCONTACTS);
        $organisation_dm = datamanager::from_schemadb($config->get('schemadb_group'));

        $qb_persons = org_openpsa_contacts_person_dba::new_query_builder();
        $person_dm = datamanager::from_schemadb($config->get('schemadb_person'));

        $indexer = new org_openpsa_contacts_midcom_indexer($topic, $indexer);
        $indexer->add_query('organisations', $qb_organisations, $organisation_dm);
        $indexer->add_query('persons', $qb_persons, $person_dm);

        return $indexer;
    }

    /**
     * Locates the root group
     */
    public static function find_root_group(string $name = '__org_openpsa_contacts') : midcom_db_group
    {
        static $root_groups = [];

        //Check if we have already initialized
        if (!empty($root_groups[$name])) {
            return $root_groups[$name];
        }

        $qb = midcom_db_group::new_query_builder();
        $qb->add_constraint('owner', '=', 0);
        $qb->add_constraint('name', '=', $name);

        $results = $qb->execute();

        if (!empty($results)) {
            $root_groups[$name] = end($results);
        } else {
            debug_add("OpenPSA Contacts root group could not be found", MIDCOM_LOG_WARN);

            //Attempt to  auto-initialize the group.
            midcom::get()->auth->request_sudo('org.openpsa.contacts');
            $grp = new midcom_db_group();
            $grp->owner = 0;
            $grp->name = $name;
            $grp->official = midcom::get()->i18n->get_string($name, 'org.openpsa.contacts');
            $ret = $grp->create();
            midcom::get()->auth->drop_sudo();
            if (!$ret) {
                throw new midcom_error("Could not auto-initialize the module, group creation failed: " . midcom_connection::get_error_string());
            }
            $root_groups[$name] = $grp;
        }

        return $root_groups[$name];
    }

    public function resolve_object_link(midcom_db_topic $topic, midcom_core_dbaobject $object) : ?string
    {
        if (   $object instanceof org_openpsa_contacts_group_dba
            || $object instanceof midcom_db_group) {
            return "group/{$object->guid}/";
        }
        if (   $object instanceof org_openpsa_contacts_person_dba
            || $object instanceof midcom_db_person) {
            return "person/{$object->guid}/";
        }
        return null;
    }

    private function _get_data_from_url(string $url) : array
    {
        $data = [];

        // TODO: Error handling
        $client = new org_openpsa_httplib();
        $html = $client->get($url);

        // Check for ICBM coordinate information
        if ($icbm = org_openpsa_httplib_helpers::get_meta_value($html, 'icbm')) {
            $data['icbm'] = $icbm;
        }

        // Check for RSS feed
        $rss_url = org_openpsa_httplib_helpers::get_link_values($html, 'alternate');

        if (!empty($rss_url)) {
            $data['rss_url'] = $rss_url[0]['href'];

            // We have a feed URL, but we should check if it is GeoRSS as well
            $items = net_nemein_rss_fetch::raw_fetch($data['rss_url'])->get_items();

            if (   !empty($items)
                && (   $items[0]->get_latitude()
                    || $items[0]->get_longitude())) {
                // This is a GeoRSS feed
                $data['georss_url'] = $data['rss_url'];
            }
        }

        $microformats = Mf2\parse($html);
        $hcards = [];
        foreach ($microformats['items'] as $item) {
            if (in_array('h-card', $item['type'])) {
                $hcards[] = $item['properties'];
            }
        }

        if (!empty($hcards)) {
            // We have found hCard data here
            $data['hcards'] = $hcards;
        }

        return $data;
    }

    /**
     * AT handler for fetching Semantic Web data for person or group
     */
    public function check_url(array $args, midcom_baseclasses_components_cron_handler $handler) : bool
    {
        if (array_key_exists('person', $args)) {
            $type = 'person';
        } elseif (array_key_exists('group', $args)) {
            $type = 'group';
        } else {
            $handler->print_error('Person or Group GUID not set, aborting');
            return false;
        }

        $classname = 'org_openpsa_contacts_' . $type . '_dba';
        $method = '_check_' . $type . '_url';
        $guid = $args[$type];

        try {
            $object = new $classname($guid);
        } catch (midcom_error $e) {
            $handler->print_error($type . " {$guid} not found, error " . $e->getMessage());
            return false;
        }
        if (!$object->homepage) {
            $handler->print_error($type . " {$object->guid} has no homepage, skipping");
            return false;
        }
        return $this->$method($object);
    }

    private function _check_group_url(org_openpsa_contacts_group_dba $group) : bool
    {
        $data = $this->_get_data_from_url($group->homepage);

        // TODO: We can use a lot of other data too
        if (array_key_exists('hcards', $data)) {
            // Process those hCard values that are interesting for us
            foreach ($data['hcards'] as $hcard) {
                $this->_update_from_hcard($group, $hcard);
            }

            $group->update();
        }
        return true;
    }

    private function _check_person_url(org_openpsa_contacts_person_dba $person) : bool
    {
        $data = $this->_get_data_from_url($person->homepage);

        if (array_key_exists('rss_url', $data)) {
            // Instead of using the ICBM position data directly we can subscribe to it so we get modifications too
            $person->set_parameter('net.nemein.rss', 'url', $data['rss_url']);
        }

        if (array_key_exists('hcards', $data)) {
            // Process those hCard values that are interesting for us
            foreach ($data['hcards'] as $hcard) {
                $this->_update_from_hcard($person, $hcard);
            }

            $person->update();
        }
        return true;
    }

    private function _update_from_hcard($object, array $hcard)
    {
        foreach ($hcard as $key => $val) {
            switch ($key) {
                case 'email':
                    $object->email = reset($val);
                    break;

                case 'tel':
                    $object->workphone = reset($val);
                    break;

                case 'note':
                    $object->extra = reset($val);
                    break;

                case 'photo':
                    // TODO: Importing the photo would be cool
                    break;

                case 'adr':
                    $adr = reset($val);
                    if (array_key_exists('street-address', $adr['properties'])) {
                        $object->street = reset($adr['properties']['street-address']);
                    }
                    if (array_key_exists('postal-code', $adr['properties'])) {
                        $object->postcode = reset($adr['properties']['postal-code']);
                    }
                    if (array_key_exists('locality', $adr['properties'])) {
                        $object->city = reset($adr['properties']['locality']);
                    }
                    if (array_key_exists('country-name', $adr['properties'])) {
                        $object->country = reset($adr['properties']['country-name']);
                    }
                    break;
            }
        }
    }
}
