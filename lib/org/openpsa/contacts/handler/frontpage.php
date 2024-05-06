<?php
/**
 * @package org.openpsa.contacts
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Frontpage class
 *
 * @package org.openpsa.contacts
 */
class org_openpsa_contacts_handler_frontpage extends midcom_baseclasses_components_handler
{
    use org_openpsa_contacts_handler;

    public function _handler_frontpage(array &$data)
    {
        $data['tree'] = $this->get_group_tree();

        $workflow = $this->get_workflow('datamanager');
        $buttons = [];
        if (midcom::get()->auth->can_user_do('midgard:create', class: org_openpsa_contacts_person_dba::class)) {
            $buttons[] = $workflow->get_button($this->router->generate('person_new'), [
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('create person'),
                MIDCOM_TOOLBAR_GLYPHICON => 'user-o',
            ]);
        }

        if (midcom::get()->auth->can_user_do('midgard:create', class: org_openpsa_contacts_group_dba::class)) {
            $buttons[] = $workflow->get_button($this->router->generate('group_new', ['type' => 'organization']), [
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get('create organization'),
                MIDCOM_TOOLBAR_GLYPHICON => 'group',
            ]);
            $buttons[] = $workflow->get_button($this->router->generate('group_new', ['type' => 'group']), [
                MIDCOM_TOOLBAR_LABEL => sprintf($this->_l10n_midcom->get('create %s'), $this->_l10n->get('group')),
                MIDCOM_TOOLBAR_GLYPHICON => 'group',
            ]);
        }

        $p_merger = new org_openpsa_contacts_duplicates_merge('person', $this->_config);
        if ($p_merger->merge_needed()) {
            $buttons[] = [
                MIDCOM_TOOLBAR_URL => $this->router->generate('person_duplicates'),
                MIDCOM_TOOLBAR_LABEL => sprintf($this->_l10n->get('merge %s'), $this->_l10n->get('persons')),
                MIDCOM_TOOLBAR_GLYPHICON => 'code-fork',
                MIDCOM_TOOLBAR_ENABLED => midcom::get()->auth->can_user_do('midgard:update', class: org_openpsa_contacts_person_dba::class),
            ];
        }
        $this->_view_toolbar->add_items($buttons);

        if (   $this->_topic->can_do('midgard:update')
            && $this->_topic->can_do('midcom:component_config')) {
            $this->_node_toolbar->add_item($workflow->get_button($this->router->generate('config'), [
                MIDCOM_TOOLBAR_LABEL => $this->_l10n_midcom->get('component configuration'),
                MIDCOM_TOOLBAR_HELPTEXT => $this->_l10n_midcom->get('component configuration helptext'),
                MIDCOM_TOOLBAR_GLYPHICON => 'sliders',
            ]));
        }

        midcom::get()->head->set_pagetitle($this->_l10n->get("my contacts"));

        return $this->show('show-frontpage');
    }
}
