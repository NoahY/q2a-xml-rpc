<?php

/*
	Plugin Name: XML-RPC
	Plugin URI: https://github.com/NoahY/q2a-xml-rpc
	Plugin Update Check URI: https://raw.github.com/NoahY/q2a-xml-rpc/master/qa-plugin.php
	Plugin Description: Provides xml-rpc capabilities
	Plugin Version: 0.9.3
	Plugin Date: 2013-05-21
	Plugin Author: NoahY
	Plugin Author URI: http://www.question2answer.org/qa/user/NoahY
	Plugin License: GPLv2
	Plugin Minimum Question2Answer Version: 1.5
*/


	if (!defined('QA_VERSION')) { // don't allow this page to be requested directly from browser
		header('Location: ../../');
		exit;
	}

	qa_register_plugin_layer('qa-rpc-layer.php', 'XML RPC Layer');
	
	qa_register_plugin_module('module', 'qa-rpc-admin.php', 'qa_rpc_admin', 'XML-RPC Admin');

	qa_register_plugin_overrides('qa-rpc-overrides.php');

	qa_register_plugin_phrases('qa-rpc-lang-*.php', 'xmlrpc');

	function qa_xml_rpc_start_server() {
		include('qa-xml-rpc-server.php');
	}

/*
	Omit PHP closing tag to help avoid accidental output
*/
