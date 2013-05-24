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
			qa_opt('xml_rpc_bool_get_questions',(bool)@$_POST['xml_rpc_bool_get_questions']);
			qa_opt('xml_rpc_bool_question',(bool)@$_POST['xml_rpc_bool_question']);
			qa_opt('xml_rpc_bool_answer',(bool)@$_POST['xml_rpc_bool_answer']);
			qa_opt('xml_rpc_bool_comment',(bool)@$_POST['xml_rpc_bool_comment']);
			qa_opt('xml_rpc_bool_q_upvote',(bool)@$_POST['xml_rpc_bool_q_upvote']);
			qa_opt('xml_rpc_bool_q_unvote',(bool)@$_POST['xml_rpc_bool_q_unvote']);
			qa_opt('xml_rpc_bool_q_downvote',(bool)@$_POST['xml_rpc_bool_q_downvote']);
			qa_opt('xml_rpc_bool_a_upvote',(bool)@$_POST['xml_rpc_bool_a_upvote']);
			qa_opt('xml_rpc_bool_a_unvote',(bool)@$_POST['xml_rpc_bool_a_unvote']);
			qa_opt('xml_rpc_bool_a_downvote',(bool)@$_POST['xml_rpc_bool_a_downvote']);
			
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
			'label' => 'Upvote Questions',
			'tags' => 'NAME="xml_rpc_bool_q_upvote"',
			'value' => qa_opt('xml_rpc_bool_q_upvote'),
			'type' => 'checkbox',
		);
		
		$fields[] = array(
			'label' => 'Unvote Questions',
			'tags' => 'NAME="xml_rpc_bool_q_unvote"',
			'value' => qa_opt('xml_rpc_bool_q_unvote'),
			'type' => 'checkbox',
		);

		$fields[] = array(
			'label' => 'Downvote Questions',
			'tags' => 'NAME="xml_rpc_bool_q_downvote"',
			'value' => qa_opt('xml_rpc_bool_q_downvote'),
			'type' => 'checkbox',
		);

		$fields[] = array(
			'label' => 'Upvote Answers',
			'tags' => 'NAME="xml_rpc_bool_a_upvote"',
			'value' => qa_opt('xml_rpc_bool_a_upvote'),
			'type' => 'checkbox',
		);
		
		$fields[] = array(
			'label' => 'Unvote Answers',
			'tags' => 'NAME="xml_rpc_bool_a_unvote"',
			'value' => qa_opt('xml_rpc_bool_a_unvote'),
			'type' => 'checkbox',
		);

		$fields[] = array(
			'label' => 'Downvote Answers',
			'tags' => 'NAME="xml_rpc_bool_a_downvote"',
			'value' => qa_opt('xml_rpc_bool_a_downvote'),
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

