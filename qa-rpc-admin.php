<?php
class qa_rpc_admin {
		
	function allow_template($template)
	{
		return ($template!='admin');
	}

	function option_default($option) {
		
		switch($option) {
			case 'xmlrpc_allow_level':
				return QA_PERMIT_USERS;
		    default:
				return null;
		}
		
	}

	function admin_form(&$qa_content)
	{

	// Process form input

	    $ok = null;

		if (qa_clicked('xml_rpc_save')) {
			qa_opt('xml_rpc_bool_active',(bool)@$_POST['xml_rpc_bool_active']);
			
			$ok = qa_lang('admin/options_saved');
		}
            
                    
        // Create the form for display

            
		$fields = array();
		
		$fields[] = array(
			'label' => 'Enable XML-RPC server',
			'tags' => 'NAME="xml_rpc_bool_active"',
			'value' => qa_opt('xml_rpc_bool_active'),
			'type' => 'checkbox',
		);
 
		return array(           
			'ok' => ($ok && !isset($error)) ? $ok : null,
				
			'fields' => $fields,
		 
			'buttons' => array(
				array(
					'label' => qa_lang_html('main/save_button'),
					'tags' => 'NAME="xml_rpc_save"',
				),
			),
		);
	}
}

