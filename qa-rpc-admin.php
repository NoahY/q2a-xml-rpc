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
			foreach($_POST as $i => $v)
				if(strpos($i,'xml_rpc_bool_') === 0)
					qa_opt($i,(bool)$v);
			
			$ok = qa_lang('admin/options_saved');
		}
	    else if (qa_clicked('xml_rpc_reset')) {
			foreach($_POST as $i => $v) {
				$def = $this->option_default($i);
				if($def !== null) qa_opt($i,$def);
			}
			$ok = qa_lang('admin/options_reset');
	    }
            
                    
        // Create the form for display

            
		$fields = array();
		
		$fields[] = array(
			'label' => 'Enable XML-RPC server',
			'tags' => 'NAME="xml_rpc_bool_active"',
			'value' => qa_opt('xml_rpc_bool_active'),
			'type' => 'checkbox',
		);
 
  
	    $fields[] = array(
			'type' => 'blank',
		);           

	    $fields[] = array(
			'type' => 'static',
			'label' => 'Enabled Services:',
		);           

	// Get items

		$fields[] = array(
			'label' => 'Get Questions',
			'tags' => 'NAME="xml_rpc_bool_get_questions"',
			'value' => qa_opt('xml_rpc_bool_get_questions'),
			'type' => 'checkbox',
		);

	// Post items

		$fields[] = array(
			'label' => 'Post Questions',
			'tags' => 'NAME="xml_rpc_bool_question"',
			'value' => qa_opt('xml_rpc_bool_question'),
			'type' => 'checkbox',
		);
		$fields[] = array(
			'label' => 'Post Answers',
			'tags' => 'NAME="xml_rpc_bool_answer"',
			'value' => qa_opt('xml_rpc_bool_answer'),
			'type' => 'checkbox',
		);

		$fields[] = array(
			'label' => 'Post Comments',
			'tags' => 'NAME="xml_rpc_bool_comment"',
			'value' => qa_opt('xml_rpc_bool_comment'),
			'type' => 'checkbox',
		);

	// Vote on items

		$fields[] = array(
			'label' => 'Vote on Questions',
			'tags' => 'NAME="xml_rpc_bool_q_vote"',
			'value' => qa_opt('xml_rpc_bool_q_vote'),
			'type' => 'checkbox',
		);

		$fields[] = array(
			'label' => 'Vote on Answers',
			'tags' => 'NAME="xml_rpc_bool_a_vote"',
			'value' => qa_opt('xml_rpc_bool_a_vote'),
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
				//~ array(
					//~ 'label' => qa_lang_html('admin/reset_options_button'),
					//~ 'tags' => 'NAME="xml_rpc_reset"',
				//~ ),
			),
		);
	}
}

