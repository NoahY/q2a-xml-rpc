<?php
	function qa_get_request_content() {
		if(qa_opt('xml_rpc_bool_active')) {
			$requestlower=strtolower(qa_request());
			if($requestlower && $requestlower === "xml-rpc") {
				qa_xml_rpc_start_server();
				return false;
			}
		}
		return qa_get_request_content_base();
	}

	function qa_get_permit_options() {
		$permits = qa_get_permit_options_base();
		$permits[] = 'xmlrpc_access';
		return $permits;
	}