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

		// Parse the arguments, assuming they're in the correct order
		$username = mysql_real_escape_string( $args[0] );
		$password   = mysql_real_escape_string( $args[1] );
		$data = @$args[2];

		if ( !$this->login( $username, $password ) )
			return $this->error;

		$userid = qa_get_logged_in_userid();
		$output = array();
		
		if(isset($data['action'])) {
			$action_message = $this->do_action($data);
		}

		$sort = @$data['sort'];
		$start = (int)@$data['start'];
		$size = (int)@$data['size'];
		$full = isset($data['full']);

		switch($sort) {
			case 'updated':
				$qarray = $this->get_updated_qs($size);
				break;
			case 'favorites':
				$selectspec=qa_db_posts_basic_selectspec($userid, true);
				$selectspec['source'].=" JOIN ^userfavorites AS uf ON ^posts.postid=uf.entityid WHERE uf.userid=$ AND uf.entitytype=$ AND ^posts.type='Q' LIMIT #";
				array_push($selectspec['arguments'], $userid, QA_ENTITY_QUESTION, $size+1);
				$selectspec['sortdesc']='created';
				$qarray = qa_db_select_with_pending(
					$selectspec
				);

				break;
			default:
				$qarray = qa_db_select_with_pending(
					qa_db_qs_selectspec($userid, $sort, $start, null, null, false, $full, $size+1)
				);
				break;
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
			if(isset($data['action_id']) && isset($data['postid']) && $question['postid'] == $data['postid'])
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
		else if(isset($data['action']))
			$output['message'] = $action_message;
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
	
		// Parse the arguments, assuming they're in the correct order
		$username = mysql_real_escape_string( $args[0] );
		$password   = mysql_real_escape_string( $args[1] );
		$data = @$args[2];

		if ( !$this->login( $username, $password ) )
			return $this->error;

		$userid = qa_get_logged_in_userid();
		$output = array();
		
		if(!@$data['postid'])
			return new IXR_Error( 1550, qa_lang('xmlrpc/content_missing') );
				
		if(isset($data['action']))
			$action_message = $this->do_action($data);

		if(isset($data['action_id']))
			$output['acted'] = $data['postid'];

		$question = qa_db_select_with_pending(
			qa_db_full_post_selectspec($userid, $data['postid'])
		);

		if($question) {
			$output['data'] = $this->get_single_question($data, $question);
			$output['message'] = isset($data['action'])?$action_message:qa_lang( 'xmlrpc/question_found');
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
						"SELECT ^posts.*, LEFT(^posts.type, 1) AS basetype, UNIX_TIMESTAMP(^posts.created) AS created, ^uservotes.vote as uservote FROM ^posts LEFT JOIN ^uservotes ON ^posts.postid=^uservotes.postid AND ^uservotes.userid=$ WHERE ^posts.type='Q' AND ^posts.postid=#",
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
		$userid = qa_get_logged_in_userid();
		$cookieid=qa_cookie_get();
		$fields['netvotes_raw']=(int)$post['netvotes'];
		$postid=$post['postid'];
		$isquestion=($post['basetype']=='Q');
		$isanswer=($post['basetype']=='A');
		$isbyuser=@$post['userid']==$userid;
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


		$isselected=@$options['isselected'];
		
	//	Updated when and by whom

		if (isset($post['opostid'])) {
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
			
			$fields['what_2']=qa_lang_html($langstring);
				
			if ( ($post['obasetype']!='Q') || (@$post['oupdatetype']==QA_UPDATE_FOLLOWS) )
				$fields['what_2_url']=qa_q_path_html($post['postid'], $post['title'], false, $post['obasetype'], $post['opostid']);

			if (@$options['contentview'] && !empty($post['ocontent'])) {
				$viewer=qa_load_viewer($post['ocontent'], $post['oformat']);
				
				$fields['content_2']=$viewer->get_html($post['ocontent'], $post['oformat'], array(
					'blockwordspreg' => @$options['blockwordspreg'],
					'showurllinks' => @$options['showurllinks'],
					'linksnewwindow' => @$options['linksnewwindow'],
				));
			}
			
			if (@$options['whenview'])
				$fields['when_2']=qa_when_to_html($post['otime'], @$options['fulldatedays']);
			
			if (@$options['whoview']) {
				$isbyuser=qa_post_is_by_user(array('userid' => $post['ouserid'], 'cookieid' => @$post['ocookieid']), $userid, $cookieid);
			
				$fields['who_2']=qa_who_to_html($isbyuser, $post['ouserid'], $usershtml, @$options['ipview'] ? @$post['oip'] : null, false);
		
				if (isset($post['opoints'])) {
					if (@$options['pointsview'])
						$fields['who_2']['points']=($post['opoints']==1) ? qa_lang_html_sub_split('main/1_point', '1', '1')
							: qa_lang_html_sub_split('main/x_points', qa_html(number_format($post['opoints'])));
							
					if (isset($options['pointstitle']))
						$fields['who_2']['title']=qa_get_points_title_html($post['opoints'], $options['pointstitle']);
				}

				if (isset($post['olevel']))
					$fields['who_2']['level']=qa_html(qa_user_level_string($post['olevel']));
			}
		}
		else if (
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


		$cselspec = qa_db_recent_c_qs_selectspec($userid, 0, null, null, false, false, $count);

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

// actions

	function do_action($data) {
		$action_message = qa_lang( 'xmlrpc/action_failed' );
		$output['action_success'] = false;
		$userid = qa_get_logged_in_userid();
		
		if($data['action'] != 'post') {
			$postid = $data['action']=='select'?(int)@$data['action_data']['question_id']:(int)@$data['action_id'];
			if(!$postid)
				return $action_message;
			$post=qa_db_select_with_pending(qa_db_full_post_selectspec($userid, $postid));
		}

		switch($data['action']) {
			case 'vote':
				$type = @$data['action_data']['type'];
				$vote = @$data['action_data']['vote'];
	
				$output['action_success'] = $this->do_vote($data, $post);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/vote_success' );
				break;
			case 'favorite':
				$output['action_success'] = $this->do_favorite($data, $post);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/favorite_success' );
				break;
			case 'select':
				$output['action_success'] = $this->do_select($data, $post);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/select_success' );
				break;
			case 'post':
				$output['action_success'] = $this->do_post($data);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/post_success' );
				break;
			case 'edit':
				$output['action_success'] = $this->do_edit($data, $post);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/edit_success' );
				break;
			case 'flag':
				$output['action_success'] = $this->do_flag($data, $post);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/flag_success' );
				break;
			case 'unflag':
				$output['action_success'] = $this->do_unflag($data, $post);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/unflag_success' );
				break;
			case 'hide':
				$output['action_success'] = $this->do_hide($data, $post);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/hide_success' );
				break;
			case 'delete':
				$output['action_success'] = $this->do_delete($data, $post);
				if($output['action_success'])
					$action_message = qa_lang( 'xmlrpc/delete_success' );
				break;
		}
		return $action_message;
	}

	
	function do_post($data) {
		
		if(!isset($data['action_data']) || !isset($data['action_data']['type']))
			return false;
		
		$userid = qa_get_logged_in_userid();
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary

		$questionid = (int)@$data['action_data']['question_id'];

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
		
		$permiterror=qa_user_permit_error('permit_post_'.strtolower($type), QA_LIMIT_QUESTIONS);
		
		if($permiterror) // not allowed
			return false;
		
		require_once QA_INCLUDE_DIR.'qa-app-posts.php';
		$postid = qa_post_create($type, $parentid, $title, $content, $format, $category, $tags, $userid, $notify, $email);
		return $postid != null;
	}

	function do_edit($data, $post) {
		
		if(!isset($data['action_data']) || !isset($data['action_data']['type']))
			return false;
		
		$userid = qa_get_logged_in_userid();
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary


		$postid = (int)@$data['action_id'];
		$input = $data['action_data'];

		$type = $input['type'];
		$title = @$input['title'];
		$content = @$input['content'];
		$format = @$input['format'];
		$tags = @$input['tags'];
		$notify = @$input['notify'];
		$email = @$input['email'];
		$parentid = @$input['parentid'];

		if($post['userid'] != $userid) {
					
			$permiterror=qa_user_permit_error('permit_edit_'.strtolower($type));
			
			if($permiterror // not allowed
				|| (($post['basetype']=='Q') && (isset($post['closedbyid']) || (isset($post['selchildid']) && qa_opt('do_close_on_select')))) // closed
			)
				return false;
		}		

		require_once QA_INCLUDE_DIR.'qa-app-posts.php';
		qa_post_set_content($postid, $title, $content, $format, $tags, $notify, $email, $userid);
		return true;
	}

	function do_flag($data, $post) {
		$questionid = (int)@$data['action_data']['question_id'];
		$postid = (int)@$data['action_id'];

		$userid = qa_get_logged_in_userid();
		$handle=qa_get_logged_in_handle();
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary
		
		if($questionid === null || $postid === null)
			return false;

		if($questionid != $postid)
			$question = qa_db_select_with_pending(qa_db_full_post_selectspec($userid, $questionid));
		else
			$question = $post;
		
		require_once QA_INCLUDE_DIR.'qa-app-votes.php';
		$error=qa_flag_error_html($post, $userid, "");
		if (!$error) { // allowed
			if (qa_flag_set_tohide($post, $userid, $handle, $cookieid, $question))
				qa_post_set_hidden($postid, true);
			return true;
		}
		return false;
	}
	function do_unflag($data, $post) {

		$userid = qa_get_logged_in_userid();
		$handle=qa_get_logged_in_handle();
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary

		if(@$post['userflag'] && (!$post['hidden'])) { // allowed
			require_once QA_INCLUDE_DIR.'qa-app-votes.php';
			qa_flag_clear($post, $userid, $handle, $cookieid);
			return true;
		}
		return false;
	}	
	function do_hide($data, $post) {
		$userid=qa_get_logged_in_userid();
		$cookieid=qa_cookie_get();
		$userlevel=qa_user_level_for_post($post);
		$postid = (int)@$data['action_id'];

		$rules['closed']=($post['basetype']=='Q') && (isset($post['closedbyid']) || (isset($post['selchildid']) && qa_opt('do_close_on_select')));
		$rules['isbyuser']=qa_post_is_by_user($post, $userid, $cookieid);
		$rules['queued']=(substr($post['type'], 1)=='_QUEUED');
		$rules['authorlast']=((!isset($post['lastuserid'])) || ($post['lastuserid']===$post['userid']));
		$notclosedbyother=!($rules['closed'] && isset($post['closedbyid']) && !$rules['authorlast']);
		$nothiddenbyother=!($post['hidden'] && !$rules['authorlast']);
		$permiterror_hide_show=qa_user_permit_error($rules['isbyuser'] ? null : 'permit_hide_show', null, $userlevel);

		$rules['reshowimmed']=$post['hidden'] && !qa_user_permit_error('permit_hide_show', null, $userlevel);
			// means post can be reshown immediately without checking whether it needs moderation

		$hideable=(!$post['hidden']) && ($rules['isbyuser'] || !$rules['queued']) &&
			(!$permiterror_hide_show) && ($notclosedbyother || !qa_user_permit_error('permit_hide_show', null, $userlevel));

		$showable=$post['hidden'] && (!$permiterror_hide_show) &&
			($rules['reshowimmed'] || ($nothiddenbyother && !$post['flagcount']));
			// cannot reshow a question if it was hidden by someone else, or if it has flags - unless you have global hide/show permissions
		
		require_once QA_INCLUDE_DIR.'qa-app-posts.php';
		if($hideable && isset($data['action_data']['hide'])) // hide allowed
			qa_post_set_hidden($postid, true, $userid);
		else if ($showable && !isset($data['action_data']['hide'])) // reshow allowed
			qa_post_set_hidden($postid, false, $userid);
		else return false;
		
		return true;
	}
	function do_delete($data, $post) {
		$userlevel=qa_user_level_for_post($post);
		$deleteable=$post['hidden'] && !qa_user_permit_error('permit_delete_hidden', null, $userlevel);
		$postid = (int)@$data['action_id'];
		
		if(!$deleteable)
			return false;
			
		require_once QA_INCLUDE_DIR.'qa-app-posts.php';
		qa_post_delete($postid);
		return true;
	}

	function do_vote($data, $post) {
		require_once QA_INCLUDE_DIR.'qa-app-votes.php';
		$postid = (int)@$data['action_id'];
		$info = @$data['action_data'];
		$vote = (int)@$info['vote'];
		$type = @$info['type'];

		$userid = qa_get_logged_in_userid();
		$cookieid=isset($userid) ? qa_cookie_get() : qa_cookie_get_create(); // create a new cookie if necessary
		
		if($postid === null || $vote === null || $type === null)
			return false;
		
		$voteerror=qa_vote_error_html($post, $vote, $userid, "");
		
		if ($voteerror === false) { // allowed
			qa_vote_set($post, $userid, qa_get_logged_in_handle(), $cookieid, $vote);
			return true;
		}
		return false;
	}

	function do_select($data, $question) {
		
		$questionid = (int)@$data['action_data']['question_id'];
		$answerid = (int)@$data['action_id'];

		if($questionid === null)
			return false;

		$userid = qa_get_logged_in_userid();

		require_once QA_INCLUDE_DIR.'qa-app-posts.php';
		require_once(QA_INCLUDE_DIR.'qa-page-question-view.php');

		$qchildposts = qa_db_select_with_pending(qa_db_full_child_posts_selectspec($userid, $questionid));
		
		$question=$question+qa_page_q_post_rules($question, null, null, $qchildposts); // array union

		if(isset($data['action_data']['select']) && $question['aselectable'] && (!isset($answerid) || ( (!isset($question['selchildid'])) && !qa_opt('do_close_on_select')))) {  // allowed to select
			qa_post_set_selchildid($questionid, $answerid, $userid);
			return true;
		}
		else if(!isset($data['action_data']['select']) && $question['aselectable'] && isset($answerid) && (int)$question['selchildid'] == $answerid) {  // allowed to unselect
			qa_post_set_selchildid($questionid, null, $userid);
			return true;
		}
		return false;
	}
	
	function do_favorite($data) {
		$postid = (int)@$data['action_id'];
		$info = @$data['action_data'];
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

		if (qa_user_limits_remaining(QA_LIMIT_LOGINS)) {
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
