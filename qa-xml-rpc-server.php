<?php
/**
 * XML-RPC protocol support for BuddyPress
 */

/**
 * Whether this is a XMLRPC Request
 *
 * @var bool
 */
define( 'XMLRPC_REQUEST', true );

// Some browser-embedded clients send cookies. We don't want them.
$_COOKIE = array();

// A bug in PHP < 5.2.2 makes $HTTP_RAW_POST_DATA not set by default,
// but we can do it ourself.
if ( !isset( $HTTP_RAW_POST_DATA ) ) {
	$HTTP_RAW_POST_DATA = file_get_contents( 'php://input' );
}

// fix for cases where xml isn't on the very first line
if ( isset( $HTTP_RAW_POST_DATA ) )
	$HTTP_RAW_POST_DATA = trim( $HTTP_RAW_POST_DATA );

if ( isset( $_GET['rsd'] ) ) { // http://archipelago.phrasewise.com/rsd
	header( 'Content-Type: text/xml; charset=UTF-8', true );
	?>
	<?php echo '<?xml version="1.0" encoding="UTF-8"?'.'>'; ?>
	<rsd version="1.0" xmlns="http://archipelago.phrasewise.com/rsd">
	  <service>
		<engineName>Question2Answer</engineName>
		<engineLink>http://www.question2answer.org/</engineLink>
		<homePageLink><?php bloginfo_rss( 'url' ) ?></homePageLink>
	  </service>
	  <apis>
		<api name="Question2Answer" preferred="true" apiLink="<?php echo qa_opt('site_url') ?>">
		  <settings>
			<docs>https://github.com/NoahY/q2a-xml-rpc</docs>
		  </settings>
		</api>
	  </apis>
	</rsd>
	<?php
	exit;
}

if(!class_exists('IXR_Server'))
	include('IXR_Library.php');

/**
 * Question2Answer XMLRPC server implementation.
 *
 */
class q2a_xmlrpc_server extends IXR_Server {

	/**
	 * Register all of the XMLRPC methods that XMLRPC server understands.
	 *
	 * @return q2a_xmlrpc_server
	 */
	function q2a_xmlrpc_server() {
		$this->methods = array(

			// for services connecting: verify it
			'q2a.verifyConnection'				=> 'this:call_verify_connection',

			// getting
			'q2a.getQuestions'					=> 'this:call_get_questions',
			'q2a.getQuestion'				=> 'this:call_get_question',
			'q2a.getAnswer'					=> 'this:call_get_answer',
			'q2a.getComment'				=> 'this:call_get_comment',

			// posting
			'q2a.postQuestion'				=> 'this:call_post_question',
			'q2a.postAnswer'				=> 'this:call_post_answer',
			'q2a.postComment'				=> 'this:call_post_comment',

			// voting
			'q2a.voteQuestion'				=> 'this:call_vote_question',
			'q2a.voteAnswer'				=> 'this:call_vote_answer',
			'q2a.voteComment'				=> 'this:call_vote_comment',
			
		);
	}

	function serve_request() {
		$this->IXR_Server( $this->methods );
	}

	/**
	 * Verify xmlrpc handshake
	 *
	 * @param array $args ($username, $password)
	 * @return array (success, message);
	 */
	function call_verify_connection( $args ) {

		// Parse the arguments, assuming they're in the correct order
		$username = escape( $args[0] );
		$password  = escape( $args[1] );

		if ( !$this->login( $username, $password ) )
			return $this->error;

		return array(
			'confirmation' => true,
			'message'	  => qa_lang_sub('xmlrpc/hello_x',QA_FINAL_EXTERNAL_USERS
					? qa_get_logged_in_user_cache()['publicusername']
					: qa_get_logged_in_handle()
				),
		);
	}

	/**
	 * Get stream.
	 *
	 * @param array $args ($username, $password, $data['sort', 'start', 'cats', 'size'])
	 * @return array (questions);
	 * 
	 */
	function call_get_questions( $args ) {
		global $bp;

		//check options if this is callable
		$call = (array) maybe_unserialize( get_option( 'q2a_xmlrpc_enabled_calls' ) );
		if ( !qa_opt( 'xml_rpc_bool_get_questions' ) )
			return new IXR_Error( 405, qa_lang_sub('xmlrpc/x_is_disabled','q2a.getQuestions' ));

		// Parse the arguments, assuming they're in the correct order
		$username = mysql_real_escape_string( $args[0] );
		$password   = mysql_real_escape_string( $args[1] );
		$data = @$args[2];

		if ( !$this->login( $username, $password ) )
			return $this->error;

		$userid = qa_get_logged_in_userid();
		
		$qarray = qa_db_select_with_pending(
			qa_db_qs_selectspec($userid, mysql_real_escape_string($data['sort']), (int)mysql_real_escape_string($data['start']), mysql_real_escape_string($data['cats']), null, false, false, (int)mysql_real_escape_string($data['size']))
		);
		
		if($qarray == null || empty($qarray))
			$output['message'] = qa_lang( 'xmlrpc/no_items_found' );
		else 
			$output['message'] = qa_lang_sub( 'xmlrpc/x_items_found', count($qarray) );
		
		$output = array(
			'confirmation' => true,
			'data' => $qarray,
		);

		return $output;

	}

	/**
	 * Log user in.
	 *
	 * @param string $username  user's username.
	 * @param string $password  user's password.
	 * @return mixed WP_User object if authentication passed, false otherwise
	 */

	function login( $username, $password ) {

		if ( !qa_opt( 'xml_rpc_bool_active' ) ) {
			$this->error = new IXR_Error( 405, qa_lang('xmlrpc/plugin_disabled' ) );
			return false;
		}
		

		if (QA_FINAL_EXTERNAL_USERS)
			$user = $this->wp_login( $username, $password );
		else
			$user = $this->core_login( $username, $password );
		
		if(!$user)
			return false;
			
		if ( qa_user_permit_error( 'xmlrpc_access' ) ) {
			$this->error = new IXR_Error( 405, qa_lang( 'xmlrpc/level_disabled' ) );
			return false;
		}

		if(!$this->check_user_allowed( $username )) {
			$this->error = array(
				'confirmation' => false,
				'need_access' => true,
				'message'	  => __( 'XML-RPC services not allowed for this user. Please request access from admin.', 'q2a-xmlrpc' )
			);
			return false;
		}
		return $user;
	}
		
	function core_login( $username, $password ) {
		
		require_once QA_INCLUDE_DIR.'qa-app-limits.php';

		if (qa_limits_remaining(null, QA_LIMIT_LOGINS)) {
			require_once QA_INCLUDE_DIR.'qa-db-users.php';
			require_once QA_INCLUDE_DIR.'qa-db-selects.php';
		
			qa_limits_increment(null, QA_LIMIT_LOGINS);

			$errors=array();
			
			if (qa_opt('allow_login_email_only') || (strpos($username, '@')!==false)) // handles can't contain @ symbols
				$matchusers=qa_db_user_find_by_email($username);
			else
				$matchusers=qa_db_user_find_by_handle($username);
	
			if (count($matchusers)==1) { // if matches more than one (should be impossible), don't log in
				$inuserid=$matchusers[0];
				$userinfo=qa_db_select_with_pending(qa_db_user_account_selectspec($inuserid, true));
				
				if (strtolower(qa_db_calc_passcheck($password, $userinfo['passsalt'])) == strtolower($userinfo['passcheck'])) { // login
					require_once QA_INCLUDE_DIR.'qa-app-users.php';
	
					qa_set_logged_in_user($inuserid, $userinfo['handle'], $remember ? true : false);
					
					return $userinfo;
	
				} else
					$this->error = new IXR_Error( 1512,qa_lang('users/password_wrong'));
	
			} else
				$this->error = new IXR_Error( 1512,qa_lang('users/user_not_found'));
			
		} else {
			$this->error = new IXR_Error( 1512,qa_lang('users/login_limit'));
		}
		return false;
	}
	
	function wp_login( $username, $password ) {
		$user = wp_authenticate($username, $password);

		if (is_wp_error($user)) {
			$this->error = new IXR_Error( 403, qa_lang( 'xmlrpc/incorrect_user_pass' ) );
			return false;
		}

		wp_set_current_user( $user->ID );

		return $user;
	}


	/**
	 * Check User Allowed
	 *
	 * @param string $username  user's username.
	 * @return true if user is allowed to access functions (or per user allowance not true), false otherwise
	 */

	function check_user_allowed( $username ) {
		
		if(!qa_opt('xmlrpc_require_approval'))
			return true;
		
		$users = explode("\n",qa_opt('xmlrpc_allowed_users'));
		if(in_array($username,$users))
			return true;

		return false;
	}
}

// start the server
$q2a_xmlrpc_server = new q2a_xmlrpc_server();
$q2a_xmlrpc_server->serve_request();
