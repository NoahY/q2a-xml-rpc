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
			'q2a.getQuestions'				=> 'this:call_get_questions',
			'q2a.getQuestion'				=> 'this:call_get_question',
			'q2a.doVote'					=> 'this:call_vote',

			
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
			'message'	  => qa_lang_sub('xmlrpc/hello_x',$this->get_name()),
		);
	}

	/**
	 * Get Question List.
	 *
	 * @param array $args ($username, $password, $data['sort', 'start', 'cats', 'full', 'size', 'action', 'action_id', 'action_data'])
	 * @return array (questions);
	 * 
	 */
	function call_get_questions( $args ) {
	
		if ( !qa_opt( 'xml_rpc_bool_get_questions' ) )
			return new IXR_Error( 405, qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/getting_questions') ));

		// Parse the arguments, assuming they're in the correct order
		$username = mysql_real_escape_string( $args[0] );
		$password   = mysql_real_escape_string( $args[1] );
		$data = @$args[2];

		if ( !$this->login( $username, $password ) )
			return $this->error;

		$userid = qa_get_logged_in_userid();
		$output = array();
		
		if(isset($data['action'])) {
			$output['action_success'] = false;
			$type = @$data['action_data']['type'];
			$vote = @$data['action_data']['vote'];
			switch($data['action']) {
				case 'vote':
		
					switch(true) {
						case ($type == 'Q' && $vote > 0 && !qa_opt( 'xml_rpc_bool_q_upvote' )):
							$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/upvoting_questions') );
							break 2;
						case ($type == 'Q' && $vote == 0 && !qa_opt( 'xml_rpc_bool_q_unvote' )):
							$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/unvoting_questions') );
							break 2;
						case ($type == 'Q' && $vote < 0 && !qa_opt( 'xml_rpc_bool_q_downvote' )):
							$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/downvoting_questions') );
							break 2;
						case ($type == 'A' && $vote > 0 && !qa_opt( 'xml_rpc_bool_a_upvote' )):
							$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/upvoting_questions') );
							break 2;
						case ($type == 'A' && $vote == 0 && !qa_opt( 'xml_rpc_bool_a_unvote' )):
							$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/unvoting_questions') );
							break 2;
						case ($type == 'A' && $vote < 0 && !qa_opt( 'xml_rpc_bool_a_downvote' )):
							$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/downvoting_questions') );
							break 2;
					}	

					$output['action_success'] = $this->do_vote($data);
					break;
				case 'post':
					$output['action_success'] = $this->do_post($data);
					break;
				case 'favorite':
					$output['action_success'] = $this->do_favorite($data);
					break;
				case 'select':
					$output['action_success'] = $this->do_select($data);
					break;
			}
		}

		
		$qarray = qa_db_select_with_pending(
			qa_db_qs_selectspec($userid, $data['sort'], (int)$data['start'], mysql_real_escape_string(@$data['cats']), null, false, false, (int)$data['size'])
		);
		$usershtml=qa_userids_handles_html(qa_any_get_userids_handles($qarray));
		
		$questions = array();
		
		foreach($qarray as $questionid => $post)
			$questions[] = $this->get_single_question($data, $post['postid']);
		
		if(empty($questions))
			$output['message'] = qa_lang( 'xmlrpc/no_items_found' );
		else 
			$output['message'] = qa_lang_sub( 'xmlrpc/x_items_found', count($questions) );
		
		if(isset($data['meta']))
			$output['meta'] = $this->get_meta_data();
		
		$output['confirmation'] = true;
		$output['data'] = $questions;

		return $output;

	}


	/**
	 * Vote Call.
	 *
	 * @param array $args ($username, $password, $data['sort', 'start', 'cats', 'full', 'size', 'action', 'action_id', 'action_data'])
	 * @return array (questions);
	 * 
	 */
	function call_vote( $args ) {

		// Parse the arguments, assuming they're in the correct order
		$username = mysql_real_escape_string( $args[0] );
		$password   = mysql_real_escape_string( $args[1] );
		$data = @$args[2];

		$type = @$data['action_data']['type'];
		$vote = @$data['action_data']['vote'];


		$error = false;
		
		switch(true) {
			case ($type == 'Q' && $vote > 0 && !qa_opt( 'xml_rpc_bool_q_upvote' )):
				$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/upvoting_questions') );
				break;
			case ($type == 'Q' && $vote == 0 && !qa_opt( 'xml_rpc_bool_q_unvote' )):
				$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/unvoting_questions') );
				break;
			case ($type == 'Q' && $vote < 0 && !qa_opt( 'xml_rpc_bool_q_downvote' )):
				$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/downvoting_questions') );
				break;
			case ($type == 'A' && $vote > 0 && !qa_opt( 'xml_rpc_bool_a_upvote' )):
				$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/upvoting_questions') );
				break;
			case ($type == 'A' && $vote == 0 && !qa_opt( 'xml_rpc_bool_a_unvote' )):
				$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/unvoting_questions') );
				break;
			case ($type == 'A' && $vote < 0 && !qa_opt( 'xml_rpc_bool_a_downvote' )):
				$error = qa_lang_sub('xmlrpc/x_is_disabled',qa_lang('xmlrpc/downvoting_questions') );
				break;
		}	

		if ( $error )
			return new IXR_Error( 405, $error );

		if ( !$this->login( $username, $password ) )
			return $this->error;
			
		$userid = qa_get_logged_in_userid();
		$output = array();
		
		if(isset($data['meta_data']))
			$output['meta_data'] = $this->get_meta_data();
		
		$output['confirmation'] = $this->do_vote($data);

		if($output['confirmation']) {
			$output['message'] = qa_lang( 'xmlrpc/voted' );
			$info = @$data['action_data'];
			$questionid = (int)@$info['questionid'];
			if($questionid)
				$output['data'] = get_single_question($data, $data);
		}
		else
			$output['message'] = qa_lang( 'xmlrpc/vote_error' );
			
		return $output;

	}

	// worker functions

	function get_single_question($data, $questionid) {
		$userid = qa_get_logged_in_userid();
		$options=qa_post_html_defaults('Q', @$data['full']);
		if (isset($data['categorypathprefix']))
			$options['categorypathprefix'] = $categorypathprefix;

		$cookieid=qa_cookie_get();
			
		if(@$data['full']) {
			
			$coptions=qa_post_html_defaults('C', true);

			@list($questionin, $childposts, $achildposts, $parentquestion, $closepost, $extravalue, $categories, $favorite)=qa_db_select_with_pending(
				qa_db_full_post_selectspec($userid, $questionid),
				qa_db_full_child_posts_selectspec($userid, $questionid),
				qa_db_full_a_child_posts_selectspec($userid, $questionid),
				qa_db_post_parent_q_selectspec($questionid),
				qa_db_post_close_post_selectspec($questionid),
				qa_db_post_meta_selectspec($questionid, 'qa_q_extra'),
				qa_db_category_nav_selectspec($questionid, true, true, true),
				isset($userid) ? qa_db_is_favorite_selectspec($userid, QA_ENTITY_QUESTION, $questionid) : null
			);


			$answers = array();
			$allcomments = array();
			
			foreach ($childposts as $postid => $post)
				switch ($post['type']) {
					case 'A':
					case 'A_HIDDEN':
					case 'A_QUEUED':
						$answers[]=$post;
						break;
					case 'Q': // never show follow-on Qs which have been hidden, even to admins
					case 'C':
					case 'C_HIDDEN':
					case 'C_QUEUED':
						$allcomments[] = $post;
						break;
				}

			foreach ($achildposts as $postid => $post)
				switch ($post['type']) {
					case 'Q': // never show follow-on Qs which have been hidden, even to admins
					case 'C':
					case 'C_HIDDEN':
					case 'C_QUEUED':
						$allcomments[] = $post;
						break;
				}

			$usershtml=qa_userids_handles_html(array_merge(array($questionin), $answers, $allcomments), true);
			
			$question = qa_post_html_fields($questionin, $userid, $cookieid, $usershtml, null, $options);
			$question['avatar'] = $this->get_post_avatar($questionin);

			$qcomments = array();

			foreach ($allcomments as $idx => $comment) {
				if ($comment['basetype']=='C')
					$comment = qa_post_html_fields($comment, $userid, $cookieid, $usershtml, null, $coptions);
				else
					$comment = qa_post_html_fields($comment, $userid, $cookieid, $usershtml, null, $options);

				$comment['username'] = $this->get_username($comment['raw']['userid']);
					
				if (($comment['raw']['parentid'] == $questionid))
					$qcomments[]=$comment;
				$allcomments[$idx] = $comment;
			}

			$aoptions=qa_post_html_defaults('A', true);
			
			$outanswers = array();
			foreach($answers as $answer) {
				$aoptions['isselected']=@$answer['isselected'];
				$answer = qa_post_html_fields($answer, $userid, $cookieid, $usershtml, null, $aoptions);
				if(!$answer)
					continue;
				$answer['avatar'] = $this->get_post_avatar($answer['raw']);
				$answer['username'] = $this->get_username($answer['raw']['userid']);
				
				$acomments = array();
				foreach ($allcomments as $comment)
					if ($comment['raw']['parentid'] == $answer['raw']['postid'])
						$acomments[] = $comment;

				$answer['comments'] = $acomments;
				
				$outanswers[] = $answer;
			}
			
			$question['answers'] = $outanswers;
			$question['comments'] = $qcomments;
			$question['parentquestion'] = $parentquestion;
			$question['closepost'] = $closepost;
			$question['extravalue'] = $extravalue;
			$question['categories'] = $categories;
			$question['favorite'] = $favorite;
			
		} 
		else {
			$questionin = qa_db_select_with_pending(
				qa_db_full_post_selectspec($userid, $questionid)
			);
			$usershtml=qa_userids_handles_html(array($questionin), true);
			$question = qa_any_to_q_html_fields($questionin, $userid, qa_cookie_get(), $usershtml, null, $options);
		}
		$question['username'] = $this->get_username($userid);
		$question['favorite'] = qa_db_read_one_value(
			qa_db_query_sub(
				"SELECT COUNT(*) FROM ^userfavorites WHERE userid=# AND entitytype=$ AND entityid=#",
				$userid, QA_ENTITY_QUESTION, $questionid
			),
			true
		);
		
		return $question;
	}
	
	function do_post($data) {

		$userid = qa_get_logged_in_userid();
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary

		$questionid = (int)@$data['action_id'];

		$input = $data['action_data'];

		$type = $input['type'];
		$title = @$input['title'];
		$content = @$input['content'];
		$format = @$input['format'];
		$tags = @$input['tags'];
		$category = @$input['category'];
		$notify = @$input['notify'];
		$email = @$input['email'];
		$parentid = @$input['parentid'];
		
		require_once QA_INCLUDE_DIR.'qa-app-users.php';
		require_once QA_INCLUDE_DIR.'qa-app-limits.php';

	//	First check whether the person has permission to do this
		switch($type) {
			case 'Q':
				if(!qa_opt( 'xml_rpc_bool_question') || qa_user_permit_error('permit_post_q'))
					return false;
				break;
			case 'A':
				if(!qa_opt( 'xml_rpc_bool_answer') || qa_user_permit_error('permit_post_a', QA_LIMIT_ANSWERS))
					return false;
				break;
			case 'C':
				if(!qa_opt( 'xml_rpc_bool_comment') || qa_user_permit_error('permit_post_c', QA_LIMIT_COMMENTS))
					return false;
				break;
		}

		require_once QA_INCLUDE_DIR.'qa-app-post-create.php';
		require_once QA_INCLUDE_DIR.'qa-util-string.php';
		require_once QA_INCLUDE_DIR.'qa-db-selects.php';
		require_once QA_INCLUDE_DIR.'qa-app-format.php';
		require_once QA_INCLUDE_DIR.'qa-app-post-create.php';
		require_once QA_INCLUDE_DIR.'qa-page-question-view.php';
		require_once QA_INCLUDE_DIR.'qa-page-question-submit.php';
		require_once QA_INCLUDE_DIR.'qa-util-sort.php';

	//	Process input
		
		$in=array();
		$in['title'] = $title; 
		$in['content'] = $content; 
		$in['format'] = $format?$format:""; 
		$in['text'] = $content; 
		$in['extra'] = null;
		$in['tags'] = $tags;

		$in['notify']=$notify ? true : false;
		$in['email']=$email;
		$in['queued']=qa_user_moderation_reason() ? true : false;

		switch($type) {
			case 'Q':
				
			//	Get some info we need from the database

				$followpostid=$parentid;
				
				$in['categoryid']=$category;
				
				@list($categories, $followanswer, $completetags)=qa_db_select_with_pending(
					qa_db_category_nav_selectspec($in['categoryid'], true),
					isset($followpostid) ? qa_db_full_post_selectspec($userid, $followpostid) : null,
					qa_db_popular_tags_selectspec(0, QA_DB_RETRIEVE_COMPLETE_TAGS)
				);
				
				if (!isset($categories[$in['categoryid']]))
					$in['categoryid']=null;
				
				if (@$followanswer['basetype']!='A')
					$followanswer=null;
					
				$errors=array();
				
				$filtermodules=qa_load_modules_with('filter', 'filter_question');
				foreach ($filtermodules as $filtermodule) {
					$oldin=$in;
					$filtermodule->filter_question($in, $errors, null);
					qa_update_post_text($in, $oldin);
				}
				
				if (qa_using_categories() && count($categories) && (!qa_opt('allow_no_category')) && !isset($in['categoryid']))
					$errors['categoryid']=qa_lang_html('question/category_required'); // check this here because we need to know count($categories)
				
				
				if (empty($errors)) {
					
					$questionid=qa_question_create($followanswer, $userid, qa_get_logged_in_handle(), $cookieid,
						$in['title'], $in['content'], $in['format'], $in['text'], qa_tags_to_tagstring($in['tags']),
						$in['notify'], $in['email'], $in['categoryid'], $in['extra'], $in['queued']);
					
					if (isset($questionid))
						return true;
				}
				break;
			case 'A':
			//	Load relevant information about this question and check it exists
			
				list($question, $childposts)=qa_db_select_with_pending(
					qa_db_full_post_selectspec($userid, $questionid),
					qa_db_full_child_posts_selectspec($userid, $questionid)
				);
				
				if ((@$question['basetype']=='Q') && !isset($question['closedbyid'])) {
					$answers=qa_page_q_load_as($question, $childposts);

					//	Try to create the new answer
					
					$errors=array();
					
					$filtermodules=qa_load_modules_with('filter', 'filter_answer');
					foreach ($filtermodules as $filtermodule) {
						$oldin=$in;
						$filtermodule->filter_answer($in, $errors, $question, null);
						qa_update_post_text($in, $oldin);
					}
					
					if (empty($errors)) {
						$testwords=implode(' ', qa_string_to_words($in['content']));
						
						foreach ($answers as $answer)
							if (!$answer['hidden'])
								if (implode(' ', qa_string_to_words($answer['content'])) == $testwords)
									$errors['content']=qa_lang_html('question/duplicate_content');
					}
					
					if (empty($errors)) {
						$userid=qa_get_logged_in_userid();
						$handle=qa_get_logged_in_handle();
						$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary
						
						$answerid=qa_answer_create($userid, $handle, $cookieid, $in['content'], $in['format'], $in['text'], $in['notify'], $in['email'],
							$question, $in['queued']);
						
						if (isset($answerid))
							return true;
					}
				}

				break;
			case 'C':

			//	Load relevant information about this question and check it exists
			
				@list($question, $parent, $children, )=qa_db_select_with_pending(
					qa_db_full_post_selectspec($userid, $questionid),
					qa_db_full_post_selectspec($userid, $parentid),
					qa_db_full_child_posts_selectspec($userid, $parentid)
				);
				
				
				if (
					(@$question['basetype']=='Q') &&
					((@$parent['basetype']=='Q') || (@$parent['basetype']=='A'))
				) {
					

					$errors=array();
					
					$filtermodules=qa_load_modules_with('filter', 'filter_comment');
					foreach ($filtermodules as $filtermodule) {
						$oldin=$in;
						$filtermodule->filter_comment($in, $errors, $question, $parent, null);
						qa_update_post_text($in, $oldin);
					}
					
					if (empty($errors)) {
						$testwords=implode(' ', qa_string_to_words($in['content']));
						
						foreach ($children as $comment)
							if (($comment['basetype']=='C') && ($comment['parentid']==$parentid) && !$comment['hidden'])
								if (implode(' ', qa_string_to_words($comment['content'])) == $testwords)
									$errors['content']=qa_lang_html('question/duplicate_content');
					}
					
					if (empty($errors)) {
						$userid=qa_get_logged_in_userid();
						$handle=qa_get_logged_in_handle();
						$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary
									
						$commentid=qa_comment_create($userid, $handle, $cookieid, $in['content'], $in['format'], $in['text'], $in['notify'], $in['email'],
							$question, $parent, $children, $in['queued']);
						
						if (isset($commentid)) 
							return true;
					}
				}

				break;
		}
		return false;
	}

	function do_vote($data) {
		require_once QA_INCLUDE_DIR.'qa-app-votes.php';
		$postid = (int)@$data['action_id'];
		$info = @$data['action_data'];
		$vote = (int)@$info['vote'];
		$type = @$info['type'];

		$userid = qa_get_logged_in_userid();
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary
		
		if($postid === null || $vote === null || $type === null)
			return false;
		
		$post=qa_db_select_with_pending(qa_db_full_post_selectspec($userid, $postid));

		$voteerror=qa_vote_error_html($post, $vote, $userid, qa_request());
		
		if ($voteerror === false) {
			qa_vote_set($post, $userid, qa_get_logged_in_handle(), $cookieid, $vote);
			return true;
		}
		return false;
	}

	function do_select($data) {
		$questionid = (int)@$data['action_id'];
		$answerid = @$data['action_data'];

		if($questionid === null)
			return false;

		$userid = qa_get_logged_in_userid();
		
		qa_post_set_selchildid($questionid, $answerid, $userid);

		return true;
	}
	
	function do_favorite($data) {
		$postid = (int)@$data['action_id'];
		$info = @$data['action_data'];
		$favorite = isset($info['favorite']);
		$type = @$info['type'];

		if($postid === null || $type === null)
			return false;

		$userid = qa_get_logged_in_userid();
		$handle = qa_get_logged_in_handle();
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary
		
		qa_user_favorite_set($userid, $handle, $cookieid, $type, $postid, $favorite);

		return true;
	}
		
	function get_meta_data() {
		$meta['options'] = $this->get_qa_opts();
		$meta['user'] = $this->get_user_data();
		return $meta;
	}

	function decide_output($error = false) {
		
		$output['confirmation'] = $error;
		$output['message'] = $error?qa_lang( 'xmlrpc/error' ):qa_lang( 'xmlrpc/success' );
		return $output;
	}

	function get_post_avatar($post) {
		$size = 96;

		if (QA_FINAL_EXTERNAL_USERS) {
			if(!function_exists('get_option'))
				return false;
			
			if ( ! get_option('show_avatars') )
				return false;

			$safe_alt = '';

			$id =  $post['userid'];
			$user = get_userdata($id);
			if ( $user )
				$email = $user->user_email;

			$avatar_default = get_option('avatar_default');
			if ( empty($avatar_default) )
				$default = 'mystery';
			else
				$default = $avatar_default;

			if ( !empty($email) )
				$email_hash = md5( strtolower( trim( $email ) ) );

			if ( is_ssl() ) {
				$host = 'https://secure.gravatar.com';
			} else {
				if ( !empty($email) )
					$host = sprintf( "http://%d.gravatar.com", ( hexdec( $email_hash[0] ) % 2 ) );
				else
					$host = 'http://0.gravatar.com';
			}

			if ( 'mystery' == $default )
				$default = "$host/avatar/ad516503a11cd5ca435acc9bb6523536?s={$size}"; // ad516503a11cd5ca435acc9bb6523536 == md5('unknown@gravatar.com')
			elseif ( 'blank' == $default )
				$default = $email ? 'blank' : includes_url( 'images/blank.gif' );
			elseif ( !empty($email) && 'gravatar_default' == $default )
				$default = '';
			elseif ( 'gravatar_default' == $default )
				$default = "$host/avatar/?s={$size}";
			elseif ( empty($email) )
				$default = "$host/avatar/?d=$default&amp;s={$size}";
			elseif ( strpos($default, 'http://') === 0 )
				$default = add_query_arg( 's', $size, $default );

			if ( !empty($email) ) {
				$out = "$host/avatar/";
				$out .= $email_hash;
				$out .= '?s='.$size;
				$out .= '&amp;d=' . urlencode( $default );

				$rating = get_option('avatar_rating');
				if ( !empty( $rating ) )
					$out .= "&amp;r={$rating}";

				return $out;
			} else
				return $default;
		}
		else {
			if (qa_opt('avatar_allow_gravatar') && isset($post['email']) && (@$post['flags'] & QA_USER_FLAGS_SHOW_GRAVATAR)) 
				return (qa_is_https_probably() ? 'https' : 'http').
					'://www.gravatar.com/avatar/'.md5(strtolower(trim($post['email']))).'?s='.$size;
			elseif (qa_opt('avatar_allow_upload') && ((@$post['flags'] & QA_USER_FLAGS_SHOW_AVATAR)) && isset($post['avatarblobid']) && strlen($post['avatarblobid']))
				return qa_path_html('image', array('qa_blobid' => $post['avatarblobid'], 'qa_size' => $size), null, QA_URL_FORMAT_PARAMS);
			elseif ( (qa_opt('avatar_allow_gravatar')||qa_opt('avatar_allow_upload')) && qa_opt('avatar_default_show') && strlen(qa_opt('avatar_default_blobid')) )
				return qa_path_html('image', array('qa_blobid' => qa_opt('avatar_default_blobid'), 'qa_size' => $size), null, QA_URL_FORMAT_PARAMS);
		}
	}

	/**
	 * Log user in.
	 *
	 * @param string $username  user's username.
	 * @param string $password  user's password.
	 * @return mixed WP_User object if authentication passed, false otherwise
	 */

	function login( $username, $password, $remember = false ) {
		
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
		
	function core_login( $username, $password, $remember = false ) {
		
		require_once QA_INCLUDE_DIR.'qa-app-limits.php';

		if (qa_limits_remaining(null, QA_LIMIT_LOGINS)) {
			require_once QA_INCLUDE_DIR.'qa-db-users.php';
			require_once QA_INCLUDE_DIR.'qa-db-selects.php';
		
			//qa_limits_increment(null, QA_LIMIT_LOGINS); // This causes problems...

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
	
	function wp_login( $username, $password, $remember = false ) {
		$user = wp_authenticate($username, $password);

		if (is_wp_error($user)) {
			$this->error = new IXR_Error( 403, qa_lang( 'xmlrpc/incorrect_user_pass' ) );
			return false;
		}

		wp_set_current_user( $user->ID );

		global $qa_cached_logged_in_user;

		$user=qa_get_logged_in_user();
		$qa_cached_logged_in_user=isset($user) ? $user : false; // to save trying again                       

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

	function get_user_data() {
		$userid = qa_get_logged_in_userid();
		$user = array();
		$user['userid'] = $userid;
		if(QA_FINAL_EXTERNAL_USERS) {
			$obj = get_userdata( $userid );
			$user['display_name'] = $obj->display_name;
			$user['username'] = $obj->user_nicename;
		}
		else {
			$userprofile=qa_db_select_with_pending(
				qa_db_user_profile_selectspec($userid, true)
			);
			$user['display_name'] = @$userprofile['name']?$userprofile['name']:qa_get_logged_in_handle();
			$user['username'] = qa_get_logged_in_handle();
		}
		$user['level'] = qa_get_logged_in_level();
	}

	function get_username($userid) {
		if(QA_FINAL_EXTERNAL_USERS) {
			$obj = get_userdata( $userid );
			return $obj->display_name;
		}
		else {
			$userprofile=qa_db_select_with_pending(
					qa_db_user_profile_selectspec($userid, true)
				);
			return @$userprofile['name']?$userprofile['name']:qa_get_logged_in_handle();
		}
	}

	function get_qa_opts() {

		$opts['get_questions'] = qa_opt('xml_rpc_bool_get_questions');
		$opts['question'] = qa_opt('xml_rpc_bool_question');
		$opts['answer'] = qa_opt('xml_rpc_bool_answer');
		$opts['comment'] = qa_opt('xml_rpc_bool_comment');
		$opts['q_upvote'] = qa_opt('xml_rpc_bool_q_upvote');
		$opts['q_unvote'] = qa_opt('xml_rpc_bool_q_unvote');
		$opts['q_downvote'] = qa_opt('xml_rpc_bool_q_downvote');
		$opts['a_upvote'] = qa_opt('xml_rpc_bool_a_upvote');
		$opts['a_unvote'] = qa_opt('xml_rpc_bool_a_unvote');
		$opts['a_downvote'] = qa_opt('xml_rpc_bool_a_downvote');
		
		return $opts;
	}
}

// start the server
$q2a_xmlrpc_server = new q2a_xmlrpc_server();
$q2a_xmlrpc_server->serve_request();
