<?php
/**
 * @package org.openpsa.contacts
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use Symfony\Component\HttpFoundation\Request;

/**
 * Duplicates handler
 *
 * @todo This cannot work in 8.09, since metadata fields like creator are read-only.
 * Also, deleting persons isn't supported (although it works if you just call delete())
 * @package org.openpsa.contacts
 */
class org_openpsa_contacts_handler_duplicates extends midcom_baseclasses_components_handler
{
    private bool $notfound = false;

    private string $mode;

    public function _handler_person(Request $request, array &$data)
    {
        $this->mode = 'person';
        return $this->handle($request, $data);
    }

    public function _handler_group(Request $request, array &$data)
    {
        $this->mode = 'group';
        return $this->handle($request, $data);
    }

    private function handle(Request $request, array &$data)
    {
        $data['loop_i'] = 0;

        if ($request->request->has('org_openpsa_contacts_handler_duplicates_object_loop_i')) {
            $data['loop_i'] = $request->request->getInt('org_openpsa_contacts_handler_duplicates_object_loop_i');
            if ($request->request->has('org_openpsa_contacts_handler_duplicates_object_decide_later')) {
                $data['loop_i']++;
            }
        }
        $this->process_submit($request);

        $this->load_next();

        $title = sprintf($this->_l10n->get('merge %s'), $this->_l10n->get($this->mode . 's'));
        midcom::get()->head->set_pagetitle($title);
        $this->add_breadcrumb('', $title);

        if (!$this->notfound) {
            return $this->show('show-duplicate-' . $this->mode . 's');
        }
        return $this->show('show-duplicate-notfound');
    }

    private function load_next()
    {
        $i =& $this->_request_data['loop_i'];
        while ($i < 100) {
            debug_add("Loop iteration {$i}");
            $qb = new midgard_query_builder('midgard_parameter');
            $qb->add_constraint('domain', '=', 'org.openpsa.contacts.duplicates:possible_duplicate');
            $qb->add_order('name', 'ASC');
            $qb->set_limit(1);
            if ($i > 0) {
                $qb->set_offset($i);
            }
            $ret = $qb->execute();

            if (empty($ret)) {
                debug_add("No more results to be had, setting notfound and breaking out of loop");
                $this->notfound = true;
                break;
            }

            $param = $ret[0];
            debug_add("Found duplicate mark on object {$param->parentguid} for object {$param->name}");
            try {
                $object1 = $this->load($param->parentguid);
                $object2 = $this->load($param->name);
            } catch (midcom_error) {
                $i++;
                continue;
            }
            // Make sure we actually have enough rights to do this
            if (   !$object1->can_do('midgard:update')
                || !$object1->can_do('midgard:delete')
                || !$object2->can_do('midgard:update')
                || !$object2->can_do('midgard:delete')) {
                debug_add("Insufficient rights to merge these two, continuing to see if we have more");
                $i++;
                continue;
            }
            // Extra sanity check (in case of semi-successful not-duplicate mark)
            if (   $object1->get_parameter('org.openpsa.contacts.duplicates:not_duplicate', $object2->guid)
                || $object2->get_parameter('org.openpsa.contacts.duplicates:not_duplicate', $object1->guid)) {
                debug_add("It seems these two (#{$object1->id} and #{$object2->id}) have also marked as not duplicates, some cleanup might be a good thing", MIDCOM_LOG_WARN);
                $i++;
                continue;
            }

            $this->_request_data['object1'] = $object1;
            $this->_request_data['object2'] = $object2;
            break;
        }
    }

    private function load(string $guid) : midcom_core_dbaobject
    {
        if ($this->mode == 'person') {
            return new org_openpsa_contacts_person_dba($guid);
        }
        return new org_openpsa_contacts_group_dba($guid);
    }

    private function process_submit(Request $request)
    {
        $keep = $request->request->all('org_openpsa_contacts_handler_duplicates_object_keep');
        $options = $request->request->all('org_openpsa_contacts_handler_duplicates_object_options');
        if (!empty($keep) && count($options) == 2) {
            $option1 = $this->load($options[1]);
            $option2 = $this->load($options[2]);
            $keep = key($keep);
            if ($keep == 'both') {
                $option1->require_do('midgard:update');
                $option2->require_do('midgard:update');
                if (   $option1->set_parameter('org.openpsa.contacts.duplicates:not_duplicate', $option2->guid, time())
                    && $option2->set_parameter('org.openpsa.contacts.duplicates:not_duplicate', $option1->guid, time())) {
                    // Clear the possible duplicate parameters
                    $option1->delete_parameter('org.openpsa.contacts.duplicates:possible_duplicate', $option2->guid);
                    $option2->delete_parameter('org.openpsa.contacts.duplicates:possible_duplicate', $option1->guid);

                    // TODO: Localize
                    midcom::get()->uimessages->add($this->_l10n->get($this->_component), "Keeping both \"{$option1->name}\" and \"{$option2->name}\", they will not be marked as duplicates in the future", 'ok');
                } else {
                    $errstr = midcom_connection::get_error_string();
                    // Failed to set as not duplicate, clear parameter that might have been set (could have only been the first)
                    $option1->delete_parameter('org.openpsa.contacts.duplicates:not_duplicate', $option2->guid);

                    // TODO: Localize
                    midcom::get()->uimessages->add($this->_l10n->get($this->_component), "Failed to mark #{$option1->id} and # {$option2->id} as not duplicates, errstr: {$errstr}", 'error');
                }
            } else {
                if ($keep == $option1->guid) {
                    $object1 =& $option1;
                    $object2 =& $option2;
                } elseif ($keep == $option2->guid) {
                    $object1 =& $option2;
                    $object2 =& $option1;
                } else {
                    throw new midcom_error('Something weird happened (basically we got bogus data)');
                }
                $object1->require_do('midgard:update');
                $object2->require_do('midgard:delete');

                try {
                    $merger = new org_openpsa_contacts_duplicates_merge($this->mode, $this->_config);
                    $merger->merge_delete($object1, $object2);
                } catch (midcom_error $e) {
                    // TODO: Localize
                    midcom::get()->uimessages->add($this->_l10n->get($this->_component), 'Merge failed, errstr: ' . $e->getMessage(), 'error');
                }
            }

            //PONDER: redirect to avoid reloading the POST in case user presses reload ??
        }
    }
}
