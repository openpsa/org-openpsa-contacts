<?php
/**
 * @package org.openpsa.contacts
 * @author Nemein Oy http://www.nemein.com/
 * @copyright Nemein Oy http://www.nemein.com/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

use Doctrine\ORM\Query\Expr\Join;
use midcom\datamanager\datamanager;
use midcom\grid\provider\client;
use midcom\grid\grid;
use midcom\grid\provider;

/**
 * org.openpsa.contacts group handler and viewer class.
 *
 * @package org.openpsa.contacts
 */
class org_openpsa_contacts_handler_group_view extends midcom_baseclasses_components_handler
implements client
{
    use org_openpsa_contacts_handler;

    private $group;

    private function _populate_toolbar()
    {
        $workflow = $this->get_workflow('datamanager');
        $buttons = [];
        if ($this->group->can_do('midgard:update')) {
            $buttons = [
                $workflow->get_button($this->router->generate('group_edit', ['guid' => $this->group->guid]), [
                    MIDCOM_TOOLBAR_ACCESSKEY => 'e',
                ]),
                $workflow->get_button($this->router->generate('group_new_subgroup', ['type' => 'organization', 'guid' => $this->group->guid]), [
                    MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('create suborganization'),
                    MIDCOM_TOOLBAR_GLYPHICON => 'group',
                ]),
                $workflow->get_button($this->router->generate('group_new_subgroup', ['type' => 'group', 'guid' => $this->group->guid]), [
                    MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('create subgroup'),
                    MIDCOM_TOOLBAR_GLYPHICON => 'group',
                ])
            ];
        }

        if (   midcom::get()->auth->can_user_do('midgard:create', null, org_openpsa_contacts_person_dba::class)
            && $this->group->can_do('midgard:create')) {
                $buttons[] = $workflow->get_button($this->router->generate('person_new_group', ['guid' => $this->group->guid]), [
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('create person'),
                MIDCOM_TOOLBAR_GLYPHICON => 'user-o',
            ]);
        }

        $siteconfig = org_openpsa_core_siteconfig::get_instance();
        $user_url = $siteconfig->get_node_full_url('org.openpsa.user');
        if (   $user_url
            && midcom::get()->auth->can_user_do('org.openpsa.user:access', null, org_openpsa_user_interface::class)) {
            $buttons[] = [
                MIDCOM_TOOLBAR_URL => $user_url . "group/{$this->group->guid}/",
                MIDCOM_TOOLBAR_LABEL => $this->_i18n->get_string('user management', 'org.openpsa.user'),
                MIDCOM_TOOLBAR_GLYPHICON => 'user-circle-o',
            ];
        }

        if ($cal_node = midcom_helper_misc::find_node_by_component('org.openpsa.calendar')) {
            //TODO: Check for privileges somehow
            $buttons[] = org_openpsa_calendar_interface::get_create_button($cal_node, $this->group->guid . '/');
        }
        $this->_view_toolbar->add_items($buttons);
    }

    /**
     * Handler for listing group members
     */
    public function _handler_view(string $guid, array &$data)
    {
        $this->group = new org_openpsa_contacts_group_dba($guid);
        $data['group'] = $this->group;

        if ($this->group->orgOpenpsaObtype < org_openpsa_contacts_group_dba::ORGANIZATION) {
            $type = 'group';
            $data['group_tree'] = $this->get_group_tree();
            $data['members_grid'] = new grid('members_grid', 'json');
        } else {
            $type = 'organization';
            $root_group = org_openpsa_contacts_interface::find_root_group();
            if ($this->group->owner != $root_group->id) {
                $data['parent_group'] = $this->group->get_parent();
            }

            if (midcom::get()->componentloader->is_installed('org.openpsa.invoices')) {
                $qb_billing_data = org_openpsa_invoices_billing_data_dba::new_query_builder();
                $qb_billing_data->add_constraint('linkGuid', '=', $this->group->guid);
                $data['billing_data'] = $qb_billing_data->execute()[0] ?? null;
            }
        }

        $data['view'] = datamanager::from_schemadb($this->_config->get('schemadb_group'))
            ->set_storage($this->group, $type)
            ->get_content_html();

        // Add toolbar items
        $this->_populate_toolbar();

        $this->bind_view_to_object($this->group);

        midcom::get()->head->set_pagetitle($this->group->official);

        $this->add_breadcrumb_path_for_group();

        if ($this->group->orgOpenpsaObtype < org_openpsa_contacts_group_dba::ORGANIZATION) {
            return $this->show('show-group-other');
        }
        return $this->show('show-group');
    }

    private function add_breadcrumb_path_for_group()
    {
        $tmp = [
            $this->group->guid => $this->group->official
        ];

        $root_id = org_openpsa_contacts_interface::find_root_group()->id;
        $parent = $this->group->get_parent();

        while ($parent && $parent->id != $root_id) {
            $tmp[$parent->guid] = $parent->official;
            $parent = $parent->get_parent();
        }

        $tmp = array_reverse($tmp, true);

        foreach ($tmp as $guid => $title) {
            $this->add_breadcrumb($this->router->generate('group_view', ['guid' => $guid]), $title);
        }
    }

    /**
     * Lists group members in JSON format
     */
    public function _handler_json(string $guid, array &$data)
    {
        midcom::get()->skip_page_style = true;
        $data['group'] = new org_openpsa_contacts_group_dba($guid);
        $data['provider'] = new provider($this);

        return $this->show('show-group-json');
    }

    /**
     * Get querybuilder for JSON group member list
     */
    public function get_qb($field = null, $direction = 'ASC', array $search = [])
    {
        $qb = midcom_db_person::new_collector();
        $qb->get_doctrine()
            ->leftJoin('midgard_member', 'm', Join::WITH, 'm.uid = c.id')
            ->where('m.gid = :gid')
            ->setParameter('gid', $this->_request_data['group']->id);

        if ($field !== null) {
            $qb->add_order($field, $direction);
        }
        $qb->add_order('lastname');
        $qb->add_order('firstname');
        $qb->add_order('email');
        $qb->add_order('id');
        return $qb;
    }

    /**
     * Prepares group member data for JSON display
     */
    public function get_row(midcom_core_dbaobject $user)
    {
        $link = $this->router->generate('person_view', ['guid' => $user->guid]);
        $lastname = trim($user->lastname) ?: $this->_l10n->get('person') . ' #' . $user->id;
        $entry = [
            'id' => $user->id,
            'lastname' => "<a href='" . $link . "'>" . $lastname . "</a>",
            'index_lastname' => $lastname,
            'firstname' => "<a href='" . $link . "' >" . $user->firstname . "</a>",
            'index_firstname' => $user->firstname,
            'homepage' => '',
            'index_homepage' => $user->homepage,
            'email' => "<a href='mailto:" . $user->email . "' >" . $user->email . "</a>",
            'index_email' => $user->email
        ];
        if (!empty($user->homepage)) {
            $url = $user->homepage;
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'http://' . $user->homepage;
            }
            $entry['homepage'] = '<a href="' . $url . '">' . $user->homepage . '</a>';
        }

        return $entry;
    }
}
