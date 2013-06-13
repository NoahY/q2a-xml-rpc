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

		$sort = @$data['sort'];
		$start = (int)@$data['start'];
		$size = (int)@$data['size'];

		if($sort == 'updated')
			$qarray = $this->get_updated_qs($size);
		else {
		
			$sortsql = "";
			$tables = "";
			switch ($sort) {
				case 'acount':
				case 'flagcount':
				case 'netvotes':
				case 'views':
					$odersql =' ORDER BY '.$sort.' DESC, created DESC';
					break;
				case 'updated':
					// fudge
					break;
				case 'favorites':
					$tables = ", ^userfavorites";
					$sortsql = " AND ^posts.postid=^userfavorites.entityid AND ^userfavorites.userid=".mysql_real_escape_string($userid)." AND ^userfavorites.entitytype='".mysql_real_escape_string(QA_ENTITY_QUESTION)."'";
					$ordersql = " ORDER BY created DESC";
					break;
				case 'created':
				case 'hotness':
					$ordersql =' ORDER BY '.$sort.' DESC';
					break;
					
				default:
					return new IXR_Error( 405, qa_lang('xmlrpc/error'));
			}
			$qarray = qa_db_read_all_assoc(
				qa_db_query_sub(
					"SELECT ^posts.*, LEFT(^posts.type, 1) AS basetype, UNIX_TIMESTAMP(^posts.created) AS created, ^uservotes.vote as uservote FROM ^posts".$tables." LEFT JOIN ^uservotes ON ^posts.postid=^uservotes.postid AND ^uservotes.userid=$ WHERE ^posts.type='Q'".$sortsql.$ordersql." LIMIT #,#",
					$userid,$start,$size+1
				)
			);
		}
		
		$more = false;
		if(count($qarray) > $size && $sort != 'updated') {
			$more = true;
			array_pop($qarray);
		}
		
		$questions = array();

		if(isset($data['more']) && $start > 0)
			$questions[] = "<less>";
		
		foreach($qarray as $question) {
			if(isset($data['action_id']) && $question['postid'] == $data['action_id'])
				$output['acted'] = count($questions);

			$question = $this->get_single_question($data, $question);
			if($question)
				$questions[] = $question;
		}
		
		// add extra list item for loading more
		
		if($more && isset($data['more']))
			$questions[] = "<more>";
		
		
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
	 * Call to Get Single Question.
	 *
	 * @param array $args ($username, $password, $data['postid', 'action', 'action_id', 'action_data'])
	 * @return array (questions);
	 * 
	 */
	function call_get_question( $args ) {
	
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
		
		if(!$data['postid'])
			return new IXR_Error( 1550, qa_lang('xmlrpc/content_missing') );
				
		if(isset($data['action'])) {
			$output['action_success'] = false;
			switch($data['action']) {
				case 'vote':
					$type = @$data['action_data']['type'];
					$vote = @$data['action_data']['vote'];
		
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

		if(isset($data['action_id']) && $data['postid'] == $data['action_id'])
			$output['acted'] = $data['postid'];


		$question = qa_db_read_one_assoc(
			qa_db_query_sub(
				"SELECT *, LEFT(^posts.b, 1) AS basetype, UNIX_TIMESTAMP(^posts.created) AS created, ^uservotes.vote as uservote FROM ^posts".$tables." LEFT JOIN ^uservotes ON ^posts.postid=^uservotes.postid AND ^uservotes.userid=$ WHERE ^posts.type='Q' AND ^posts.postid=$",
				$userid, $data['postid']
			),
			true
		);

		if($question) {
			$output['data'] = $this->get_single_question($data, $question);
			$output['message'] = qa_lang( 'xmlrpc/question_found');
		$output['confirmation'] = true;
		}
		else {
			$output['confirmation'] = false;
			$output['message'] = qa_lang( 'xmlrpc/no_items_found' );
		}

		if(isset($data['meta']))
			$output['meta'] = $this->get_meta_data();

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
			$output['confirmation'] = true;
			$info = @$data['action_data'];
			$questionid = (int)@$info['questionid'];
			if($questionid) {
				$question = qa_db_read_one_assoc(
					qa_db_query_sub(
						"SELECT *, LEFT(^posts.b, 1) AS basetype, UNIX_TIMESTAMP(^posts.created) AS created, ^uservotes.vote as uservote FROM ^posts".$tables." LEFT JOIN ^uservotes ON ^posts.postid=^uservotes.postid AND ^uservotes.userid=$ WHERE ^posts.type='Q' AND ^posts.postid=$",
						$userid, $questionid
					),
					true
				);

				if($question) {
					$output['data'] = $this->get_single_question($data, $question);
				}
			}
		}
		else
			$output['message'] = qa_lang( 'xmlrpc/vote_error' );
			
		return $output;

	}

	// worker functions

	function get_single_question($data, $questionin) {
		$userid = qa_get_logged_in_userid();
		$questionid = $questionin['postid'];
		$options=qa_post_html_defaults('Q', @$data['full']);
			
		if(@$data['full']) {
			
			require_once(QA_INCLUDE_DIR.'qa-page-question-view.php');
			$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary
			$coptions=qa_post_html_defaults('C', true);
			
			@list($childposts, $achildposts, $parentquestion, $closepost, $extravalue, $categories, $favorite)=qa_db_select_with_pending(
				qa_db_full_child_posts_selectspec($userid, $questionid),
				qa_db_full_a_child_posts_selectspec($userid, $questionid),
				qa_db_post_parent_q_selectspec($questionid),
				qa_db_post_close_post_selectspec($questionid),
				qa_db_post_meta_selectspec($questionid, 'qa_q_extra'),
				qa_db_category_nav_selectspec($questionid, true, true, true),
				isset($userid) ? qa_db_is_favorite_selectspec($userid, QA_ENTITY_QUESTION, $questionid) : null
			);
			
			if ($questionin['basetype']!='Q') // don't allow direct viewing of other types of post
				return null;

			$questionin['extra']=$extravalue;
			
			$answers=qa_page_q_load_as($questionin, $childposts);
			$allcomments=qa_page_q_load_c_follows($questionin, $childposts, $achildposts);
			
			$questionin=$questionin+qa_page_q_post_rules($questionin, null, null, $childposts); // array union
			
			if ($questionin['selchildid'] && (@$answers[$questionin['selchildid']]['type']!='A'))
				$questionin['selchildid']=null; // if selected answer is hidden or somehow not there, consider it not selected

			foreach ($answers as $key => $answer) {
				$answers[$key]=$answer+qa_page_q_post_rules($answer, $questionin, $answers, $achildposts);
				$answers[$key]['isselected']=($answer['postid']==$questionin['selchildid']);
			}

			foreach ($allcomments as $key => $commentfollow) {
				$parent=($commentfollow['parentid']==$questionid) ? $questionin : @$answers[$commentfollow['parentid']];
				$allcomments[$key]=$commentfollow+qa_page_q_post_rules($commentfollow, $parent, $allcomments, null);
			}

			$usershtml=qa_userids_handles_html(array_merge(array($questionin), $answers, $allcomments), true);
			
			$question = $this->get_full_post($questionin, $options, $usershtml);

			$qcomments = array();

			foreach ($allcomments as $idx => $comment) {
				if ($comment['basetype']=='C')
					$comment = $this->get_full_post($comment, $coptions, $usershtml);
				else
					$comment = $this->get_full_post($comment, $options, $usershtml);

				$comment['username'] = $this->get_username($comment['raw']['userid']);
					
				if (($comment['raw']['parentid'] == $questionid))
					$qcomments[]=$comment;
				$allcomments[$idx] = $comment;
			}

			$aoptions=qa_post_html_defaults('A', true);
			
			$outanswers = array();
			foreach($answers as $answer) {
				$aoptions['isselected']=@$answer['isselected'];
				$answer = $this->get_full_post($answer, $aoptions, $usershtml);
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

		
		return $question;
	}
	
	function get_full_post($post, $options, $usershtml) {
		$fields['raw'] = $post;
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary
		$userid = qa_get_logged_in_userid();

		// backwards compatibility (TODO: remove from android)

		$fields['netvotes_raw']=(int)$post['netvotes'];

		$postid=$post['postid'];
		$isquestion=($post['basetype']=='Q');
		$isanswer=($post['basetype']=='A');
		$isbyuser=qa_post_is_by_user($post, $userid, $cookieid);
		$anchor=urlencode(qa_anchor($post['basetype'], $postid));
		$elementid=isset($options['elementid']) ? $options['elementid'] : $anchor;
		$microformats=false;
		$isselected=@$options['isselected'];


		// content
		
		if (@$options['contentview'] && !empty($post['content'])) {
			$viewer=qa_load_viewer($post['content'], $post['format']);
			
			$fields['content']=$viewer->get_html($post['content'], $post['format'], array(
				'blockwordspreg' => @$options['blockwordspreg'],
				'showurllinks' => @$options['showurllinks'],
				'linksnewwindow' => @$options['linksnewwindow'],
			));
		}

		if ($post['hidden'])
			$fields['vote_state']='disabled';
		elseif ($isbyuser)
			$fields['vote_state']='disabled';
		elseif (@$post['uservote']>0)
			$fields['vote_state']='voted_up';
		elseif (@$post['uservote']<0)
			$fields['vote_state']='voted_down';
		else {
			if (strpos($options['voteview'], '-uponly-level')) {
				$fields['vote_state']='up_only';
			} else {
				$fields['vote_state']='enabled';
			}
		}
		
		//	Created when and by whom
	
		$fields['meta_order']=qa_lang_html('main/meta_order'); // sets ordering of meta elements which can be language-specific
		
		if (@$options['whatview'] ) {
			$fields['what']=qa_lang_html($isquestion ? 'main/asked' : ($isanswer ? 'main/answered' : 'main/commented'));
				
			if (@$options['whatlink'] && !$isquestion)
				$fields['what_url']=qa_path_html(qa_request(), array('show' => $postid), null, null, qa_anchor($post['basetype'], $postid));
		}
		
		if (isset($post['created']) && @$options['whenview'])
			$fields['when']=qa_when_to_html($post['created'], @$options['fulldatedays']);
		
		if (@$options['whoview']) {
			$fields['who']=qa_who_to_html($isbyuser, @$post['userid'], $usershtml, @$options['ipview'] ? @$post['createip'] : null, $microformats);
			
			if (isset($post['points'])) {
				if (@$options['pointsview'])
					$fields['who']['points']=($post['points']==1) ? qa_lang_html_sub_split('main/1_point', '1', '1')
						: qa_lang_html_sub_split('main/x_points', qa_html(number_format($post['points'])));
				
				if (isset($options['pointstitle']))
					$fields['who']['title']=qa_get_points_title_html($post['points'], $options['pointstitle']);
			}
				
			if (isset($post['level']))
				$fields['who']['level']=qa_html(qa_user_level_string($post['level']));
		}


	//	Updated when and by whom
		$isselected=@$options['isselected'];
		
		if (
			@$options['updateview'] && isset($post['updated']) &&
			(($post['updatetype']!=QA_UPDATE_SELECTED) || $isselected) && // only show selected change if it's still selected
			( // otherwise check if one of these conditions is fulfilled...
				(!isset($post['created'])) || // ... we didn't show the created time (should never happen in practice)
				($post['hidden'] && ($post['updatetype']==QA_UPDATE_VISIBLE)) || // ... the post was hidden as the last action
				(isset($post['closedbyid']) && ($post['updatetype']==QA_UPDATE_CLOSED)) || // ... the post was closed as the last action
				(abs($post['updated']-$post['created'])>300) || // ... or over 5 minutes passed between create and update times
				($post['lastuserid']!=$post['userid']) // ... or it was updated by a different user
			)
		) {
			switch ($post['updatetype']) {
				case QA_UPDATE_TYPE:
				case QA_UPDATE_PARENT:
					$langstring='main/moved';
					break;
					
				case QA_UPDATE_CATEGORY:
					$langstring='main/recategorized';
					break;

				case QA_UPDATE_VISIBLE:
					$langstring=$post['hidden'] ? 'main/hidden' : 'main/reshown';
					break;
					
				case QA_UPDATE_CLOSED:
					$langstring=isset($post['closedbyid']) ? 'main/closed' : 'main/reopened';
					break;
					
				case QA_UPDATE_TAGS:
					$langstring='main/retagged';
					break;
				
				case QA_UPDATE_SELECTED:
					$langstring='main/selected';
					break;
				
				default:
					$langstring='main/edited';
					break;
			}
			
			$fields['what_2']=qa_lang_html($langstring);
			
			if (@$options['whenview']) {
				$fields['when_2']=qa_when_to_html($post['updated'], @$options['fulldatedays']);
				
			}
			
			if (isset($post['lastuserid']) && @$options['whoview'])
				$fields['who_2']=qa_who_to_html(isset($userid) && ($post['lastuserid']==$userid), $post['lastuserid'], $usershtml, @$options['ipview'] ? $post['lastip'] : null, false);
		}
		else if (
			@$options['updateview'] && isset($post['updated'])
		) {

			// updated meta
			
			switch ($post['obasetype'].'-'.@$post['oupdatetype']) {
				case 'Q-':
					$langstring='main/asked';
					break;
				
				case 'Q-'.QA_UPDATE_VISIBLE:
					$langstring=$post['hidden'] ? 'main/hidden' : 'main/reshown';
					break;
					
				case 'Q-'.QA_UPDATE_CLOSED:
					$langstring=isset($post['closedbyid']) ? 'main/closed' : 'main/reopened';
					break;
					
				case 'Q-'.QA_UPDATE_TAGS:
					$langstring='main/retagged';
					break;
					
				case 'Q-'.QA_UPDATE_CATEGORY:
					$langstring='main/recategorized';
					break;

				case 'A-':
					$langstring='main/answered';
					break;
				
				case 'A-'.QA_UPDATE_SELECTED:
					$langstring='main/answer_selected';
					break;
				
				case 'A-'.QA_UPDATE_VISIBLE:
					$langstring=$post['ohidden'] ? 'main/hidden' : 'main/answer_reshown';
					break;
					
				case 'A-'.QA_UPDATE_CONTENT:
					$langstring='main/answer_edited';
					break;
					
				case 'Q-'.QA_UPDATE_FOLLOWS:
					$langstring='main/asked_related_q';
					break;
				
				case 'C-':
					$langstring='main/commented';
					break;
				
				case 'C-'.QA_UPDATE_TYPE:
					$langstring='main/comment_moved';
					break;
					
				case 'C-'.QA_UPDATE_VISIBLE:
					$langstring=$post['ohidden'] ? 'main/hidden' : 'main/comment_reshown';
					break;
					
				case 'C-'.QA_UPDATE_CONTENT:
					$langstring='main/comment_edited';
					break;
				
				case 'Q-'.QA_UPDATE_CONTENT:
				default:
					$langstring='main/edited';
					break;
			}
			
			$fields['what']=qa_lang_html($langstring);
				
			if ( ($post['obasetype']!='Q') || (@$post['oupdatetype']==QA_UPDATE_FOLLOWS) )
				$fields['what_url']=qa_q_path_html($post['postid'], $post['title'], false, $post['obasetype'], $post['opostid']);

			if (@$options['contentview'] && !empty($post['ocontent'])) {
				$viewer=qa_load_viewer($post['ocontent'], $post['oformat']);
				
				$fields['content']=$viewer->get_html($post['ocontent'], $post['oformat'], array(
					'blockwordspreg' => @$options['blockwordspreg'],
					'showurllinks' => @$options['showurllinks'],
					'linksnewwindow' => @$options['linksnewwindow'],
				));
			}
			
			if (@$options['whenview'])
				$fields['when']=qa_when_to_html($post['otime'], @$options['fulldatedays']);
			
			if (@$options['whoview']) {
				$isbyuser=qa_post_is_by_user(array('userid' => $post['ouserid'], 'cookieid' => @$post['ocookieid']), $userid, $cookieid);
			
				$fields['who']=qa_who_to_html($isbyuser, $post['ouserid'], $usershtml, @$options['ipview'] ? @$post['oip'] : null, false);
		
				if (isset($post['opoints'])) {
					if (@$options['pointsview'])
						$fields['who']['points']=($post['opoints']==1) ? qa_lang_html_sub_split('main/1_point', '1', '1')
							: qa_lang_html_sub_split('main/x_points', qa_html(number_format($post['opoints'])));
							
					if (isset($options['pointstitle']))
						$fields['who']['title']=qa_get_points_title_html($post['opoints'], $options['pointstitle']);
				}

				if (isset($post['olevel']))
					$fields['who']['level']=qa_html(qa_user_level_string($post['olevel']));
			}
		}
		
		$fields['avatar'] = $this->get_post_avatar($post);
		
		return $fields;
	}
	
	function get_updated_qs($count) {
		$userid = qa_get_logged_in_userid();

		$aselspec = qa_db_recent_a_qs_selectspec($userid, 0, null, null, false, false, $count);

		$aselspec['columns']['content']='^posts.content';
		$aselspec['columns']['notify']='^posts.notify';
		$aselspec['columns']['updated']='UNIX_TIMESTAMP(^posts.updated)';
		$aselspec['columns']['updatetype']='^posts.updatetype';
		$aselspec['columns'][]='^posts.format';
		$aselspec['columns'][]='^posts.lastuserid';
		$aselspec['columns']['lastip']='INET_NTOA(^posts.lastip)';
		$aselspec['columns'][]='^posts.parentid';
		$aselspec['columns']['lastviewip']='INET_NTOA(^posts.lastviewip)';


		$cselspec = qa_db_recent_edit_qs_selectspec($userid, 0, null, null, false, false, $count);

		$cselspec['columns']['content']='^posts.content';
		$cselspec['columns']['notify']='^posts.notify';
		$cselspec['columns']['updated']='UNIX_TIMESTAMP(^posts.updated)';
		$cselspec['columns']['updatetype']='^posts.updatetype';
		$cselspec['columns'][]='^posts.format';
		$cselspec['columns'][]='^posts.lastuserid';
		$cselspec['columns']['lastip']='INET_NTOA(^posts.lastip)';
		$cselspec['columns'][]='^posts.parentid';
		$cselspec['columns']['lastviewip']='INET_NTOA(^posts.lastviewip)';

		$eselspec = qa_db_recent_edit_qs_selectspec($userid, 0, null, null, false, false, $count);

		$eselspec['columns']['content']='^posts.content';
		$eselspec['columns']['notify']='^posts.notify';
		$eselspec['columns']['updated']='UNIX_TIMESTAMP(^posts.updated)';
		$eselspec['columns']['updatetype']='^posts.updatetype';
		$eselspec['columns'][]='^posts.format';
		$eselspec['columns'][]='^posts.lastuserid';
		$eselspec['columns']['lastip']='INET_NTOA(^posts.lastip)';
		$eselspec['columns'][]='^posts.parentid';
		$eselspec['columns']['lastviewip']='INET_NTOA(^posts.lastviewip)';

		@list($questions1, $questions2, $questions3, $questions4)=qa_db_select_with_pending(
			qa_db_qs_selectspec($userid, 'created', 0, null, null, false, true, $count),
			$aselspec,
			$cselspec,
			$eselspec
		);
		$qarray = qa_any_sort_and_dedupe(array_merge($questions1, $questions2, $questions3, $questions4)); // questions

		return array_values($qarray);
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
		$info = @$data['action_data'];
		$postid = (int)@$info['postid'];
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

		require_once QA_INCLUDE_DIR.'qa-app-posts.php';
		$userid = qa_get_logged_in_userid();
		
		qa_post_set_selchildid($questionid, $answerid, $userid);

		return true;
	}
	
	function do_favorite($data) {
		$info = @$data['action_data'];
		$postid = (int)@$info['postid'];
		$favorite = isset($info['favorite']);
		$type = @$info['type'];

		if($postid === null || $type === null)
			return false;

		require_once QA_INCLUDE_DIR.'qa-app-favorites.php';

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

	function get_post_buttons($post) {
		
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
		qa_limits_increment(null, QA_LIMIT_LOGINS); // log on failure
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
		$user['flags'] = qa_get_logged_in_flags();
		
		return $user;
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
