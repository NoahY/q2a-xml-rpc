<?php

	class qa_html_theme_layer extends qa_html_theme_base {

	// theme replacement functions
		
		function doctype() {
			if($this->request == 'admin/permissions' && qa_get_logged_in_level()>=QA_USER_LEVEL_ADMIN) {

				$permits[] = 'xmlrpc_access';
				foreach($permits as $optionname) {
					$value = qa_opt($optionname);
					$optionfield=array(
						'id' => $optionname,
						'label' => qa_lang_html('xmlrpc/'.$optionname).':',
						'tags' => 'NAME="option_'.$optionname.'" ID="option_'.$optionname.'"',
						'value' => $value,
						'error' => qa_html(@$errors[$optionname]),
					);					
					$widest=QA_PERMIT_USERS;
					$narrowest=QA_PERMIT_ADMINS;
					
					$permitoptions=qa_admin_permit_options($widest, $narrowest, (!QA_FINAL_EXTERNAL_USERS) && qa_opt('confirm_user_emails'));
					
					if (count($permitoptions)>1)
						qa_optionfield_make_select($optionfield, $permitoptions, $value,
							($value==QA_PERMIT_CONFIRMED) ? QA_PERMIT_USERS : min(array_keys($permitoptions)));
					$this->content['form']['fields'][$optionname]=$optionfield;

					$this->content['form']['fields'][$optionname.'_points']= array(
						'id' => $optionname.'_points',
						'tags' => 'NAME="option_'.$optionname.'_points" ID="option_'.$optionname.'_points"',
						'type'=>'number',
						'value'=>qa_opt($optionname.'_points'),
						'prefix'=>qa_lang_html('admin/users_must_have').'&nbsp;',
						'note'=>qa_lang_html('admin/points')
					);
					$checkboxtodisplay[$optionname.'_points']='(option_'.$optionname.'=='.qa_js(QA_PERMIT_POINTS).') ||(option_'.$optionname.'=='.qa_js(QA_PERMIT_POINTS_CONFIRMED).')';
				}
				qa_set_display_rules($this->content, $checkboxtodisplay);
			}
			qa_html_theme_base::doctype();
		}
		function html() {
			qa_html_theme_base::html();
		}
		
		
		function head_custom()
		{
			qa_html_theme_base::head_custom();
		}
	}

