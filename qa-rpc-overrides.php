<?php
	function qa_get_request_content() {
		if(qa_opt('xml_rpc_active')) {
			$requestlower=strtolower(qa_request());
			if($requestlower && $requestlower === "xml-rpc") {
				include('qa-xml-rpc-server.php');
				return false;
			}
		}
		return qa_get_request_content_base();
	}

	function qa_get_permit_options() {
		$permits = qa_get_permit_options_base();
		$permits[] = 'xmlrpc_allow_level';
		return $permits;
	}