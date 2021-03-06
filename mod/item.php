<?php

/**
 *
 * This is the POST destination for most all locally posted
 * text stuff. This function handles status, wall-to-wall status, 
 * local comments, and remote coments that are posted on this site 
 * (as opposed to being delivered in a feed).
 * Also processed here are posts and comments coming through the 
 * statusnet/twitter API. 
 * All of these become an "item" which is our basic unit of 
 * information.
 * Posts that originate externally or do not fall into the above 
 * posting categories go through item_store() instead of this function. 
 *
 */  

require_once('include/crypto.php');
require_once('include/enotify.php');
require_once('include/items.php');
require_once('include/attach.php');

function item_post(&$a) {


	// This will change. Figure out who the observer is and whether or not
	// they have permission to post here. Else ignore the post.

	if((! local_user()) && (! remote_user()) && (! x($_REQUEST,'commenter')))
		return;

	require_once('include/security.php');

	$uid = local_user();
	$channel = null;
	$observer = null;

	$profile_uid = ((x($_REQUEST,'profile_uid')) ? intval($_REQUEST['profile_uid'])    : 0);
	require_once('include/identity.php');
	$sys = get_sys_channel();
	if($sys && $profile_uid && ($sys['channel_id'] == $profile_uid) && is_site_admin()) {
		$uid = intval($sys['channel_id']);
		$channel = $sys;
		$observer = $sys;
	}

	if(x($_REQUEST,'dropitems')) {
		require_once('include/items.php');
		$arr_drop = explode(',',$_REQUEST['dropitems']);
		drop_items($arr_drop);
		$json = array('success' => 1);
		echo json_encode($json);
		killme();
	}

	call_hooks('post_local_start', $_REQUEST);

//	 logger('postvars ' . print_r($_REQUEST,true), LOGGER_DATA);

	$api_source = ((x($_REQUEST,'api_source') && $_REQUEST['api_source']) ? true : false);

	// 'origin' (if non-zero) indicates that this network is where the message originated,
	// for the purpose of relaying comments to other conversation members. 
	// If using the API from a device (leaf node) you must set origin to 1 (default) or leave unset.
	// If the API is used from another network with its own distribution
	// and deliveries, you may wish to set origin to 0 or false and allow the other 
	// network to relay comments.

	// If you are unsure, it is prudent (and important) to leave it unset.   

	$origin = (($api_source && array_key_exists('origin',$_REQUEST)) ? intval($_REQUEST['origin']) : 1);

	// To represent message-ids on other networks - this will create an item_id record

	$namespace = (($api_source && array_key_exists('namespace',$_REQUEST)) ? strip_tags($_REQUEST['namespace']) : '');
	$remote_id = (($api_source && array_key_exists('remote_id',$_REQUEST)) ? strip_tags($_REQUEST['remote_id']) : '');

	$owner_hash = null;

	$message_id  = ((x($_REQUEST,'message_id') && $api_source)  ? strip_tags($_REQUEST['message_id'])       : '');
	$created     = ((x($_REQUEST,'created'))     ? datetime_convert('UTC','UTC',$_REQUEST['created']) : datetime_convert());
	$post_id     = ((x($_REQUEST,'post_id'))     ? intval($_REQUEST['post_id'])        : 0);
	$app         = ((x($_REQUEST,'source'))      ? strip_tags($_REQUEST['source'])     : '');
	$return_path = ((x($_REQUEST,'return'))      ? $_REQUEST['return']                 : '');
	$preview     = ((x($_REQUEST,'preview'))     ? intval($_REQUEST['preview'])        : 0);
	$categories  = ((x($_REQUEST,'category'))    ? escape_tags($_REQUEST['category'])  : '');
	$webpage     = ((x($_REQUEST,'webpage'))     ? intval($_REQUEST['webpage'])        : 0);
	$pagetitle   = ((x($_REQUEST,'pagetitle'))   ? escape_tags(urlencode($_REQUEST['pagetitle'])) : '');
	$layout_mid  = ((x($_REQUEST,'layout_mid'))  ? escape_tags($_REQUEST['layout_mid']): '');
	$plink       = ((x($_REQUEST,'permalink'))   ? escape_tags($_REQUEST['permalink']) : '');

	// allow API to bulk load a bunch of imported items with sending out a bunch of posts. 
	$nopush      = ((x($_REQUEST,'nopush'))      ? intval($_REQUEST['nopush'])         : 0);

	/*
	 * Check service class limits
	 */
	if ($uid && !(x($_REQUEST,'parent')) && !(x($_REQUEST,'post_id'))) {
		$ret = item_check_service_class($uid,x($_REQUEST,'webpage'));
		if (!$ret['success']) { 
			notice( t($ret['message']) . EOL) ;
			if(x($_REQUEST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}
	}

	if($pagetitle) {
		require_once('library/urlify/URLify.php');
		$pagetitle = strtolower(URLify::transliterate($pagetitle));
	}


	$item_flags = $item_restrict = 0;

	/**
	 * Is this a reply to something?
	 */

	$parent = ((x($_REQUEST,'parent')) ? intval($_REQUEST['parent']) : 0);
	$parent_mid = ((x($_REQUEST,'parent_mid')) ? trim($_REQUEST['parent_mid']) : '');

	$route = '';
	$parent_item = null;
	$parent_contact = null;
	$thr_parent = '';
	$parid = 0;
	$r = false;

	if($parent || $parent_mid) {

		if(! x($_REQUEST,'type'))
			$_REQUEST['type'] = 'net-comment';

		if($parent) {
			$r = q("SELECT * FROM `item` WHERE `id` = %d LIMIT 1",
				intval($parent)
			);
		}
		elseif($parent_mid && $uid) {
			// This is coming from an API source, and we are logged in
			$r = q("SELECT * FROM `item` WHERE `mid` = '%s' AND `uid` = %d LIMIT 1",
				dbesc($parent_mid),
				intval($uid)
			);
		}
		// if this isn't the real parent of the conversation, find it
		if($r !== false && count($r)) {
			$parid = $r[0]['parent'];
			$parent_mid = $r[0]['mid'];
			if($r[0]['id'] != $r[0]['parent']) {
				$r = q("SELECT * FROM `item` WHERE `id` = `parent` AND `parent` = %d LIMIT 1",
					intval($parid)
				);
			}
		}

		if(($r === false) || (! count($r))) {
			notice( t('Unable to locate original post.') . EOL);
			if(x($_REQUEST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}

		// can_comment_on_post() needs info from the following xchan_query 
		xchan_query($r);

		$parent_item = $r[0];
		$parent = $r[0]['id'];

		// multi-level threading - preserve the info but re-parent to our single level threading

		$thr_parent = $parent_mid;

		$route = $parent_item['route'];

	}

	if(! $observer)
		$observer = $a->get_observer();

	if($parent) {
		logger('mod_item: item_post parent=' . $parent);
		$can_comment = false;
		if((array_key_exists('owner',$parent_item)) && ($parent_item['owner']['abook_flags'] & ABOOK_FLAG_SELF))
			$can_comment = perm_is_allowed($profile_uid,$observer['xchan_hash'],'post_comments');
		else
			$can_comment = can_comment_on_post($observer['xchan_hash'],$parent_item);

		if(! $can_comment) {
			notice( t('Permission denied.') . EOL) ;
			if(x($_REQUEST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}
	}
	else {
		if(! perm_is_allowed($profile_uid,$observer['xchan_hash'],'post_wall')) {
			notice( t('Permission denied.') . EOL) ;
			if(x($_REQUEST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}
	}


	// is this an edited post?

	$orig_post = null;

	if($namespace && $remote_id) {
		// It wasn't an internally generated post - see if we've got an item matching this remote service id
		$i = q("select iid from item_id where service = '%s' and sid = '%s' limit 1",
			dbesc($namespace),
			dbesc($remote_id) 
		);
		if($i)
			$post_id = $i[0]['iid'];	
	}

	if($post_id) {
		$i = q("SELECT * FROM `item` WHERE `uid` = %d AND `id` = %d LIMIT 1",
			intval($profile_uid),
			intval($post_id)
		);
		if(! count($i))
			killme();
		$orig_post = $i[0];
	}


	if(! $channel) {
		if($uid && $uid == $profile_uid) {
			$channel = $a->get_channel();
		}
		else {
			// posting as yourself but not necessarily to a channel you control
			$r = q("select * from channel left join account on channel_account_id = account_id where channel_id = %d LIMIT 1",
				intval($profile_uid)
			);
			if($r)
				$channel = $r[0];
		}
	}


	if(! $channel) {
		logger("mod_item: no channel.");
		if(x($_REQUEST,'return')) 
			goaway($a->get_baseurl() . "/" . $return_path );
		killme();
	}

	$owner_xchan = null;

	$r = q("select * from xchan where xchan_hash = '%s' limit 1",
		dbesc($channel['channel_hash'])
	);
	if($r && count($r)) {
		$owner_xchan = $r[0];
	}
	else {
		logger("mod_item: no owner.");
		if(x($_REQUEST,'return')) 
			goaway($a->get_baseurl() . "/" . $return_path );
		killme();
	}

	$walltowall = false;
	$walltowall_comment = false;

	if($observer) {
		logger('mod_item: post accepted from ' . $observer['xchan_name'] . ' for ' . $owner_xchan['xchan_name'], LOGGER_DEBUG);

		// wall-to-wall detection.
		// For top-level posts, if the author and owner are different it's a wall-to-wall
		// For comments, We need to additionally look at the parent and see if it's a wall post that originated locally.

		if($observer['xchan_name'] != $owner_xchan['xchan_name'])  {
			if($parent_item && ($parent_item['item_flags'] & (ITEM_WALL|ITEM_ORIGIN)) == (ITEM_WALL|ITEM_ORIGIN)) {
				$walltowall_comment = true;
				$walltowall = true;
			}
			if(! $parent) {
				$walltowall = true;		
			}
		}
	}
		
	$public_policy = ((x($_REQUEST,'public_policy')) ? escape_tags($_REQUEST['public_policy']) : map_scope($channel['channel_r_stream'],true));
	if($webpage)
		$public_policy = '';
	if($public_policy)
		$private = 1;

	if($orig_post) {
		$private = 0;
		// webpages are allowed to change ACLs after the fact. Normal conversation items aren't. 
		if($webpage) {
			$str_group_allow   = perms2str($_REQUEST['group_allow']); 
			$str_contact_allow = perms2str($_REQUEST['contact_allow']);
			$str_group_deny    = perms2str($_REQUEST['group_deny']);
			$str_contact_deny  = perms2str($_REQUEST['contact_deny']);
		}
		else {
			$str_group_allow   = $orig_post['allow_gid'];
			$str_contact_allow = $orig_post['allow_cid'];
			$str_group_deny    = $orig_post['deny_gid'];
			$str_contact_deny  = $orig_post['deny_cid'];
			$public_policy     = $orig_post['public_policy'];
		}

		if((strlen($str_group_allow)) 
			|| strlen($str_contact_allow) 
			|| strlen($str_group_deny) 
			|| strlen($str_contact_deny)
			|| strlen($public_policy)) {
			$private = 1;
		}

		$location          = $orig_post['location'];
		$coord             = $orig_post['coord'];
		$verb              = $orig_post['verb'];
		$app               = $orig_post['app'];
		$title             = $_REQUEST['title'];
		$body              = $_REQUEST['body'];
		$item_flags        = $orig_post['item_flags'];

		// force us to recalculate if we need to obscure this post

		if($item_flags & ITEM_OBSCURED)
			$item_flags = ($item_flags ^ ITEM_OBSCURED);

		$item_restrict     = $orig_post['item_restrict'];
		$postopts          = $orig_post['postopts'];
		$created           = $orig_post['created'];
		$mid               = $orig_post['mid'];
		$parent_mid        = $orig_post['parent_mid'];
		$plink             = $orig_post['plink'];

	}
	else {

		// if coming from the API and no privacy settings are set, 
		// use the user default permissions - as they won't have
		// been supplied via a form.

		if(($api_source) 
			&& (! array_key_exists('contact_allow',$_REQUEST))
			&& (! array_key_exists('group_allow',$_REQUEST))
			&& (! array_key_exists('contact_deny',$_REQUEST))
			&& (! array_key_exists('group_deny',$_REQUEST))) {
			$str_group_allow   = $channel['channel_allow_gid'];
			$str_contact_allow = $channel['channel_allow_cid'];
			$str_group_deny    = $channel['channel_deny_gid'];
			$str_contact_deny  = $channel['channel_deny_cid'];
		}
		elseif($walltowall) {

			// use the channel owner's default permissions

			$str_group_allow   = $channel['channel_allow_gid'];
			$str_contact_allow = $channel['channel_allow_cid'];
			$str_group_deny    = $channel['channel_deny_gid'];
			$str_contact_deny  = $channel['channel_deny_cid'];
		}
		else {

			// use the posted permissions

			$str_group_allow   = perms2str($_REQUEST['group_allow']);
			$str_contact_allow = perms2str($_REQUEST['contact_allow']);
			$str_group_deny    = perms2str($_REQUEST['group_deny']);
			$str_contact_deny  = perms2str($_REQUEST['contact_deny']);
		}


		$location          = notags(trim($_REQUEST['location']));
		$coord             = notags(trim($_REQUEST['coord']));
		$verb              = notags(trim($_REQUEST['verb']));
		$title             = escape_tags(trim($_REQUEST['title']));
		$body              = $_REQUEST['body'];
		$postopts          = '';

		$private = ( 
				(  strlen($str_group_allow) 
				|| strlen($str_contact_allow) 
				|| strlen($str_group_deny) 
				|| strlen($str_contact_deny)
				|| strlen($public_policy)
		) ? 1 : 0);

		// If this is a comment, set the permissions from the parent.

		if($parent_item) {
			$private = 0;

			if(($parent_item['item_private']) 
				|| strlen($parent_item['allow_cid']) 
				|| strlen($parent_item['allow_gid']) 
				|| strlen($parent_item['deny_cid']) 
				|| strlen($parent_item['deny_gid'])
				|| strlen($parent_item['public_policy'])) {
				$private = (($parent_item['item_private']) ? $parent_item['item_private'] : 1);
			}

			$public_policy     = $parent_item['public_policy'];
			$str_contact_allow = $parent_item['allow_cid'];
			$str_group_allow   = $parent_item['allow_gid'];
			$str_contact_deny  = $parent_item['deny_cid'];
			$str_group_deny    = $parent_item['deny_gid'];
			$owner_hash        = $parent_item['owner_xchan'];
		}
	
		if(! strlen($body)) {
			if($preview)
				killme();
			info( t('Empty post discarded.') . EOL );
			if(x($_REQUEST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}
	}
	

	$expires = NULL_DATE;

	if(feature_enabled($profile_uid,'content_expire')) {
		if(x($_REQUEST,'expire')) {
			$expires = datetime_convert(date_default_timezone_get(),'UTC', $_REQUEST['expire']);
			if($expires <= datetime_convert())
				$expires = NULL_DATE;
		}
	}

	$post_type = notags(trim($_REQUEST['type']));

	$mimetype = notags(trim($_REQUEST['mimetype']));
	if(! $mimetype)
		$mimetype = 'text/bbcode';

	if($preview) {
		$body = z_input_filter($profile_uid,$body,$mimetype);
	}


	// Verify ability to use html or php!!!

	$execflag = false;

	if($mimetype === 'application/x-php') {
		$z = q("select account_id, account_roles from account left join channel on channel_account_id = account_id where channel_id = %d limit 1",
			intval($profile_uid)
		);
		if($z && ($z[0]['account_roles'] & ACCOUNT_ROLE_ALLOWCODE)) {
			if($uid && (get_account_id() == $z[0]['account_id'])) {
				$execflag = true;
			}
			else {
				notice( t('Executable content type not permitted to this channel.') . EOL);
				if(x($_REQUEST,'return')) 
					goaway($a->get_baseurl() . "/" . $return_path );
				killme();
			}
		}
	}


	if($mimetype === 'text/bbcode') {

		require_once('include/text.php');			
		if($uid && $uid == $profile_uid && feature_enabled($uid,'markdown')) {
			require_once('include/bb2diaspora.php');
			$body = escape_tags($body);
			$body = preg_replace_callback('/\[share(.*?)\]/ism','share_shield',$body);			
			$body = diaspora2bb($body,true);
			$body = preg_replace_callback('/\[share(.*?)\]/ism','share_unshield',$body);
		}

		// BBCODE alert: the following functions assume bbcode input
		// and will require alternatives for alternative content-types (text/html, text/markdown, text/plain, etc.)
		// we may need virtual or template classes to implement the possible alternatives

		// Work around doubled linefeeds in Tinymce 3.5b2
		// First figure out if it's a status post that would've been
		// created using tinymce. Otherwise leave it alone. 

		$plaintext = true;

//		$plaintext = ((feature_enabled($profile_uid,'richtext')) ? false : true);
//		if((! $parent) && (! $api_source) && (! $plaintext)) {
//			$body = fix_mce_lf($body);
//		}



		// If we're sending a private top-level message with a single @-taggable channel as a recipient, @-tag it, if our pconfig is set.


		if((! $parent) && (get_pconfig($profile_uid,'system','tagifonlyrecip')) && (substr_count($str_contact_allow,'<') == 1) && ($str_group_allow == '') && ($str_contact_deny == '') && ($str_group_deny == '')) {
			$x = q("select abook_id, abook_their_perms from abook where abook_xchan = '%s' and abook_channel = %d limit 1",
				dbesc(str_replace(array('<','>'),array('',''),$str_contact_allow)),
				intval($profile_uid)
			);
			if($x && ($x[0]['abook_their_perms'] & PERMS_W_TAGWALL))
				$body .= "\n\n@group+" . $x[0]['abook_id'] . "\n";
		}

		/**
		 * fix naked links by passing through a callback to see if this is a red site
		 * (already known to us) which will get a zrl, otherwise link with url, add bookmark tag to both.
		 * First protect any url inside certain bbcode tags so we don't double link it.
		 */

		$body = preg_replace_callback('/\[code(.*?)\[\/(code)\]/ism','red_escape_codeblock',$body);
		$body = preg_replace_callback('/\[url(.*?)\[\/(url)\]/ism','red_escape_codeblock',$body);
		$body = preg_replace_callback('/\[zrl(.*?)\[\/(zrl)\]/ism','red_escape_codeblock',$body);

		$body = preg_replace_callback("/([^\]\='".'"'."]|^|\#\^)(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\@\_\~\#\%\$\!\+\,]+)/ism", 'red_zrl_callback', $body);

		$body = preg_replace_callback('/\[\$b64zrl(.*?)\[\/(zrl)\]/ism','red_unescape_codeblock',$body);
		$body = preg_replace_callback('/\[\$b64url(.*?)\[\/(url)\]/ism','red_unescape_codeblock',$body);
		$body = preg_replace_callback('/\[\$b64code(.*?)\[\/(code)\]/ism','red_unescape_codeblock',$body);

		// fix any img tags that should be zmg

		$body = preg_replace_callback('/\[img(.*?)\](.*?)\[\/img\]/ism','red_zrlify_img_callback',$body);


		/**
		 *
		 * When a photo was uploaded into the message using the (profile wall) ajax 
		 * uploader, The permissions are initially set to disallow anybody but the
		 * owner from seeing it. This is because the permissions may not yet have been
		 * set for the post. If it's private, the photo permissions should be set
		 * appropriately. But we didn't know the final permissions on the post until
		 * now. So now we'll look for links of uploaded photos and attachments that are in the
		 * post and set them to the same permissions as the post itself.
		 *
		 * If the post was end-to-end encrypted we can't find images and attachments in the body,
		 * use our media_str input instead which only contains these elements - but only do this
		 * when encrypted content exists because the photo/attachment may have been removed from 
		 * the post and we should keep it private. If it's encrypted we have no way of knowing
		 * so we'll set the permissions regardless and realise that the media may not be 
		 * referenced in the post. 
		 *
		 * What is preventing us from being able to upload photos into comments is dealing with
		 * the photo and attachment permissions, since we don't always know who was in the 
		 * distribution for the top level post.
		 * 
		 * We might be able to provide this functionality with a lot of fiddling:
		 * - if the top level post is public (make the photo public)
		 * - if the top level post was written by us or a wall post that belongs to us (match the top level post)
		 * - if the top level post has privacy mentions, add those to the permissions.
		 * - otherwise disallow the photo *or* make the photo public. This is the part that gets messy. 
		 */

		if(! $preview) {
			fix_attached_photo_permissions($profile_uid,$owner_xchan['xchan_hash'],((strpos($body,'[/crypt]')) ? $_POST['media_str'] : $body),$str_contact_allow,$str_group_allow,$str_contact_deny,$str_group_deny);

			fix_attached_file_permissions($channel,$observer['xchan_hash'],((strpos($body,'[/crypt]')) ? $_POST['media_str'] : $body),$str_contact_allow,$str_group_allow,$str_contact_deny,$str_group_deny);

		}



		$body = bb_translate_video($body);

		/**
		 * Fold multi-line [code] sequences
		 */

		$body = preg_replace('/\[\/code\]\s*\[code\]/ism',"\n",$body); 

		$body = scale_external_images($body,false);


		/**
		 * Look for any tags and linkify them
		 */

		$str_tags = '';
		$inform   = '';
		$post_tags = array();

		$tags = get_tags($body);

		$tagged = array();

		if(count($tags)) {
			$first_access_tag = true;
			foreach($tags as $tag) {

				// If we already tagged 'Robert Johnson', don't try and tag 'Robert'.
				// Robert Johnson should be first in the $tags array

				$fullnametagged = false;
				for($x = 0; $x < count($tagged); $x ++) {
					if(stristr($tagged[$x],$tag . ' ')) {
						$fullnametagged = true;
						break;
					}
				}
				if($fullnametagged)
					continue;

				$success = handle_tag($a, $body, $access_tag, $str_tags, ($uid) ? $uid : $profile_uid , $tag); 
				logger('handle_tag: ' . print_r($success,true), LOGGER_DATA);
				if(($access_tag) && (! $parent_item)) {
					logger('access_tag: ' . $tag . ' ' . print_r($access_tag,true), LOGGER_DATA);
					if ($first_access_tag && (! get_pconfig($profile_uid,'system','no_private_mention_acl_override'))) {

						// This is a tough call, hence configurable. The issue is that one can type in a @!privacy mention
						// and also have a default ACL (perhaps from viewing a collection) and could be suprised that the 
						// privacy mention wasn't the only recipient. So the default is to wipe out the existing ACL if a
						// private mention is found. This can be over-ridden if you wish private mentions to be in 
						// addition to the current ACL settings.

						$str_contact_allow = '';
						$str_group_allow = '';
						$first_access_tag = false;
					}
					if(strpos($access_tag,'cid:') === 0) {
						$str_contact_allow .= '<' . substr($access_tag,4) . '>';
						$access_tag = '';	
					}
					elseif(strpos($access_tag,'gid:') === 0) {
						$str_group_allow .= '<' . substr($access_tag,4) . '>';
						$access_tag = '';	
					}
				}

				if($success['replaced']) {
					$tagged[] = $tag;
					$post_tags[] = array(
						'uid'   => $profile_uid, 
						'type'  => $success['termtype'],
						'otype' => TERM_OBJ_POST,
						'term'  => $success['term'],
						'url'   => $success['url']
					); 				
				}
			}
		}


//	logger('post_tags: ' . print_r($post_tags,true));


		$attachments = '';
		$match = false;

		if(preg_match_all('/(\[attachment\](.*?)\[\/attachment\])/',$body,$match)) {
			$attachments = array();
			foreach($match[2] as $mtch) {
				$hash = substr($mtch,0,strpos($mtch,','));
				$rev = intval(substr($mtch,strpos($mtch,',')));
				$r = attach_by_hash_nodata($hash,$rev);
				if($r['success']) {
					$attachments[] = array(
						'href'     => $a->get_baseurl() . '/attach/' . $r['data']['hash'],
						'length'   => $r['data']['filesize'],
						'type'     => $r['data']['filetype'],
						'title'    => urlencode($r['data']['filename']),
						'revision' => $r['data']['revision']
					);
				}
				$body = str_replace($match[1],'',$body);
			}
		}
	}

// BBCODE end alert

	if(strlen($categories)) {
		$cats = explode(',',$categories);
		foreach($cats as $cat) {
			$post_tags[] = array(
				'uid'   => $profile_uid, 
				'type'  => TERM_CATEGORY,
				'otype' => TERM_OBJ_POST,
				'term'  => trim($cat),
				'url'   => $owner_xchan['xchan_url'] . '?f=&cat=' . urlencode(trim($cat))
			); 				
		}
	}

	if(local_user() != $profile_uid)
		$item_flags |= ITEM_UNSEEN;
	
	if($post_type === 'wall' || $post_type === 'wall-comment')
		$item_flags = $item_flags | ITEM_WALL;

	if($origin)
		$item_flags = $item_flags | ITEM_ORIGIN;

	if($moderated)
		$item_restrict = $item_restrict | ITEM_MODERATED;

	if($webpage)
		$item_restrict = $item_restrict | $webpage;
		
		
	if(! strlen($verb))
		$verb = ACTIVITY_POST ;

	$notify_type = (($parent) ? 'comment-new' : 'wall-new' );

	if(! $mid) {
		$mid = (($message_id) ? $message_id : item_message_id());
	}
	if(! $parent_mid) {
		$parent_mid = $mid;
	}

	if($parent_item)
		$parent_mid = $parent_item['mid'];

	// Fallback so that we alway have a thr_parent

	if(!$thr_parent)
		$thr_parent = $mid;

	$datarray = array();

	if(! $parent) {
		$item_flags = $item_flags | ITEM_THREAD_TOP;
	}

	if ((! $plink) && ($item_flags & ITEM_THREAD_TOP)) {
		$plink = z_root() . '/channel/' . $channel['channel_address'] . '/?f=&mid=' . $mid;
	}
	
	$datarray['aid']            = $channel['channel_account_id'];
	$datarray['uid']            = $profile_uid;

	$datarray['owner_xchan']    = (($owner_hash) ? $owner_hash : $owner_xchan['xchan_hash']);
	$datarray['author_xchan']   = $observer['xchan_hash'];
	$datarray['created']        = $created;
	$datarray['edited']         = (($orig_post) ? datetime_convert() : $created);
	$datarray['expires']        = $expires;
	$datarray['commented']      = (($orig_post) ? datetime_convert() : $created);
	$datarray['received']       = (($orig_post) ? datetime_convert() : $created);
	$datarray['changed']        = (($orig_post) ? datetime_convert() : $created);
	$datarray['mid']            = $mid;
	$datarray['parent_mid']     = $parent_mid;
	$datarray['mimetype']       = $mimetype;
	$datarray['title']          = $title;
	$datarray['body']           = $body;
	$datarray['app']            = $app;
	$datarray['location']       = $location;
	$datarray['coord']          = $coord;
	$datarray['verb']           = $verb;
	$datarray['allow_cid']      = $str_contact_allow;
	$datarray['allow_gid']      = $str_group_allow;
	$datarray['deny_cid']       = $str_contact_deny;
	$datarray['deny_gid']       = $str_group_deny;
	$datarray['item_private']   = $private;
	$datarray['attach']         = $attachments;
	$datarray['thr_parent']     = $thr_parent;
	$datarray['postopts']       = $postopts;
	$datarray['item_restrict']  = $item_restrict;
	$datarray['item_flags']     = $item_flags;
	$datarray['layout_mid']     = $layout_mid;
	$datarray['public_policy']  = $public_policy;
	$datarray['comment_policy'] = map_scope($channel['channel_w_comment']); 
	$datarray['term']           = $post_tags;
	$datarray['plink']          = $plink;
	$datarray['route']          = $route;

	// preview mode - prepare the body for display and send it via json

	if($preview) {
		require_once('include/conversation.php');

		$datarray['owner'] = $owner_xchan;
		$datarray['author'] = $observer;
		$datarray['attach'] = json_encode($datarray['attach']);
		$o = conversation($a,array($datarray),'search',false,'preview');
		logger('preview: ' . $o, LOGGER_DEBUG);
		echo json_encode(array('preview' => $o));
		killme();
	}
	if($orig_post)
		$datarray['edit'] = true;

	call_hooks('post_local',$datarray);

	if(x($datarray,'cancel')) {
		logger('mod_item: post cancelled by plugin.');
		if($return_path) {
			goaway($a->get_baseurl() . "/" . $return_path);
		}

		$json = array('cancel' => 1);
		if(x($_REQUEST,'jsreload') && strlen($_REQUEST['jsreload']))
			$json['reload'] = $a->get_baseurl() . '/' . $_REQUEST['jsreload'];

		echo json_encode($json);
		killme();
	}


	if(mb_strlen($datarray['title']) > 255)
		$datarray['title'] = mb_substr($datarray['title'],0,255);

	if(array_key_exists('item_private',$datarray) && $datarray['item_private']) {

		$datarray['body'] = z_input_filter($datarray['uid'],$datarray['body'],$datarray['mimetype']);

		if($uid) {
			if($channel['channel_hash'] === $datarray['author_xchan']) {
				$datarray['sig'] = base64url_encode(rsa_sign($datarray['body'],$channel['channel_prvkey']));
				$datarray['item_flags'] = $datarray['item_flags'] | ITEM_VERIFIED;
			}
		}

		logger('Encrypting local storage');
		$key = get_config('system','pubkey');
		$datarray['item_flags'] = $datarray['item_flags'] | ITEM_OBSCURED;
		if($datarray['title'])
			$datarray['title'] = json_encode(crypto_encapsulate($datarray['title'],$key));
		if($datarray['body'])
			$datarray['body']  = json_encode(crypto_encapsulate($datarray['body'],$key));
	}

	if($orig_post) {
		$datarray['id'] = $post_id;

		item_store_update($datarray,$execflag);

		update_remote_id($channel,$post_id,$webpage,$pagetitle,$namespace,$remote_id,$mid);

		if(! $nopush)
			proc_run('php', "include/notifier.php", 'edit_post', $post_id);

		if((x($_REQUEST,'return')) && strlen($return_path)) {
			logger('return: ' . $return_path);
			goaway($a->get_baseurl() . "/" . $return_path );
		}
		killme();
	}
	else
		$post_id = 0;

	$post = item_store($datarray,$execflag);

	$post_id = $post['item_id'];

	if($post_id) {
		logger('mod_item: saved item ' . $post_id);

		if($parent) {

			// only send comment notification if this is a wall-to-wall comment,
			// otherwise it will happen during delivery

			if(($datarray['owner_xchan'] != $datarray['author_xchan']) && ($parent_item['item_flags'] & ITEM_WALL)) {
				notification(array(
					'type'         => NOTIFY_COMMENT,
					'from_xchan'   => $datarray['author_xchan'],
					'to_xchan'     => $datarray['owner_xchan'],
					'item'         => $datarray,
					'link'		   => $a->get_baseurl() . '/display/' . $datarray['mid'],
					'verb'         => ACTIVITY_POST,
					'otype'        => 'item',
					'parent'       => $parent,
					'parent_mid'   => $parent_item['mid']
				));
			
			}
		}
		else {
			$parent = $post_id;

			if($datarray['owner_xchan'] != $datarray['author_xchan']) {
				notification(array(
					'type'         => NOTIFY_WALL,
					'from_xchan'   => $datarray['author_xchan'],
					'to_xchan'     => $datarray['owner_xchan'],
					'item'         => $datarray,
					'link'		   => $a->get_baseurl() . '/display/' . $datarray['mid'],
					'verb'         => ACTIVITY_POST,
					'otype'        => 'item'
				));
			}
		}

		// photo comments turn the corresponding item visible to the profile wall
		// This way we don't see every picture in your new photo album posted to your wall at once.
		// They will show up as people comment on them.

		if($parent_item['item_restrict'] & ITEM_HIDDEN) {
			$r = q("UPDATE `item` SET `item_restrict` = %d WHERE `id` = %d",
				intval($parent_item['item_restrict'] - ITEM_HIDDEN),
				intval($parent_item['id'])
			);
		}
	}
	else {
		logger('mod_item: unable to retrieve post that was just stored.');
		notice( t('System error. Post not saved.') . EOL);
		goaway($a->get_baseurl() . "/" . $return_path );
		// NOTREACHED
	}

	if($parent) {
		// Store the comment signature information in case we need to relay to Diaspora
		$ditem = $datarray;
		$ditem['author'] = $observer;
		store_diaspora_comment_sig($ditem,$channel,$parent_item, $post_id, (($walltowall_comment) ? 1 : 0));
	}

	update_remote_id($channel,$post_id,$webpage,$pagetitle,$namespace,$remote_id,$mid);

	$datarray['id']    = $post_id;
	$datarray['llink'] = $a->get_baseurl() . '/display/' . $channel['channel_address'] . '/' . $post_id;

	call_hooks('post_local_end', $datarray);

	if(! $nopush)
		proc_run('php', 'include/notifier.php', $notify_type, $post_id);

	logger('post_complete');

	// figure out how to return, depending on from whence we came

	if($api_source)
		return $post;

	if($return_path) {
		goaway($a->get_baseurl() . "/" . $return_path);
	}

	$json = array('success' => 1);
	if(x($_REQUEST,'jsreload') && strlen($_REQUEST['jsreload']))
		$json['reload'] = $a->get_baseurl() . '/' . $_REQUEST['jsreload'];

	logger('post_json: ' . print_r($json,true), LOGGER_DEBUG);

	echo json_encode($json);
	killme();
	// NOTREACHED
}





function item_content(&$a) {

	if((! local_user()) && (! remote_user()))
		return;

	require_once('include/security.php');

	if((argc() == 3) && (argv(1) === 'drop') && intval(argv(2))) {

		require_once('include/items.php');
		$i = q("select id, uid, author_xchan, owner_xchan, source_xchan, item_restrict from item where id = %d limit 1",
			intval(argv(2))
		);

		if($i) {
			$can_delete = false;
			$local_delete = false;
			if(local_user() && local_user() == $i[0]['uid'])
				$local_delete = true;

			$ob_hash = get_observer_hash();
			if($ob_hash && ($ob_hash === $i[0]['author_xchan'] || $ob_hash === $i[0]['owner_xchan'] || $ob_hash === $i[0]['source_xchan']))
				$can_delete = true;

			if(! ($can_delete || $local_delete)) {
				notice( t('Permission denied.') . EOL);
				return;
			}

			// if this is a different page type or it's just a local delete
			// but not by the item author or owner, do a simple deletion

			if($i[0]['item_restrict'] || ($local_delete && (! $can_delete))) {
				drop_item($i[0]['id']);
			}
			else {
				// complex deletion that needs to propagate and be performed in phases
				drop_item($i[0]['id'],true,DROPITEM_PHASE1);
				tag_deliver($i[0]['uid'],$i[0]['id']);
			}
		}
	}
}


function fix_attached_photo_permissions($uid,$xchan_hash,$body,
		$str_contact_allow,$str_group_allow,$str_contact_deny,$str_group_deny) {

	if(get_pconfig($uid,'system','force_public_uploads')) {
		$str_contact_allow = $str_group_allow = $str_contact_deny = $str_group_deny = '';
	}

	$match = null;
	// match img and zmg image links
	if(preg_match_all("/\[[zi]mg(.*?)\](.*?)\[\/[zi]mg\]/",$body,$match)) {
		$images = $match[2];
		if($images) {
			foreach($images as $image) {
				if(! stristr($image,get_app()->get_baseurl() . '/photo/'))
					continue;
				$image_uri = substr($image,strrpos($image,'/') + 1);
				if(strpos($image_uri,'-') !== false)
					$image_uri = substr($image_uri,0, strpos($image_uri,'-'));
				if(strpos($image_uri,'.') !== false)
					$image_uri = substr($image_uri,0, strpos($image_uri,'.'));
				if(! strlen($image_uri))
					continue;
				$srch = '<' . $xchan_hash . '>';

				$r = q("SELECT id FROM photo 
					WHERE allow_cid = '%s' AND allow_gid = '' AND deny_cid = '' AND deny_gid = ''
					AND resource_id = '%s' AND uid = %d LIMIT 1",
					dbesc($srch),
					dbesc($image_uri),
					intval($uid)
				);

				if($r) {
					$r = q("UPDATE photo SET allow_cid = '%s', allow_gid = '%s', deny_cid = '%s', deny_gid = '%s'
						WHERE resource_id = '%s' AND uid = %d ",
						dbesc($str_contact_allow),
						dbesc($str_group_allow),
						dbesc($str_contact_deny),
						dbesc($str_group_deny),
						dbesc($image_uri),
						intval($uid)
					);

					// also update the linked item (which is probably invisible)

					$r = q("select id from item
						WHERE allow_cid = '%s' AND allow_gid = '' AND deny_cid = '' AND deny_gid = ''
						AND resource_id = '%s' and resource_type = 'photo' AND uid = %d LIMIT 1",
						dbesc($srch),
						dbesc($image_uri),
						intval($uid)
					);
					if($r) {
						$private = (($str_contact_allow || $str_group_allow || $str_contact_deny || $str_group_deny) ? true : false);

						$r = q("UPDATE item SET allow_cid = '%s', allow_gid = '%s', deny_cid = '%s', deny_gid = '%s', item_private = %d
							WHERE id = %d AND uid = %d",
							dbesc($str_contact_allow),
							dbesc($str_group_allow),
							dbesc($str_contact_deny),
							dbesc($str_group_deny),
							intval($private),
							intval($r[0]['id']),
							intval($uid)
						);
					}
				}
			}
		}
	}
}


function fix_attached_file_permissions($channel,$observer_hash,$body,
		$str_contact_allow,$str_group_allow,$str_contact_deny,$str_group_deny) {

	if(get_pconfig($channel['channel_id'],'system','force_public_uploads')) {
		$str_contact_allow = $str_group_allow = $str_contact_deny = $str_group_deny = '';
	}

	$match = false;

	if(preg_match_all("/\[attachment\](.*?)\[\/attachment\]/",$body,$match)) {
		$attaches = $match[1];
		if($attaches) {
			foreach($attaches as $attach) {
				$hash = substr($attach,0,strpos($attach,','));
				$rev = intval(substr($attach,strpos($attach,',')));
				attach_store($channel,$observer_hash,$options = 'update', array(
					'hash'      => $hash,
					'revision'  => $rev,
					'allow_cid' => $str_contact_allow,
					'allow_gid'  => $str_group_allow,
					'deny_cid'  => $str_contact_deny,
					'deny_gid'  => $str_group_deny
				));
			}
		}
	}
}

function item_check_service_class($channel_id,$iswebpage) {
	$ret = array('success' => false, $message => '');
	if ($iswebpage) {
		$r = q("select count(i.id)  as total from item i 
			right join channel c on (i.author_xchan=c.channel_hash and i.uid=c.channel_id )  
			and i.parent=i.id and (i.item_restrict & %d)>0 and not (i.item_restrict & %d)>0 and i.uid= %d ",
			intval(ITEM_WEBPAGE),
			intval(ITEM_DELETED),
		intval($channel_id)
	);
	}
	else {
		$r = q("select count(i.id)  as total from item i 
			right join channel c on (i.author_xchan=c.channel_hash and i.uid=c.channel_id )  
			and i.parent=i.id and (i.item_restrict=0) and i.uid= %d ",
		intval($channel_id)
	);
	}
	if(! ($r && count($r))) {
		$ret['message'] = t('Unable to obtain identity information from database');
		return $ret;
	} 
	if (!$iswebpage) {
	if(! service_class_allows($channel_id,'total_items',$r[0]['total'])) {
		$result['message'] .= upgrade_message().sprintf(t("You have reached your limit of %1$.0f top level posts."),$r[0]['total']);
		return $result;
	}
	}
	else {
	if(! service_class_allows($channel_id,'total_pages',$r[0]['total'])) {
		$result['message'] .= upgrade_message().sprintf(t("You have reached your limit of %1$.0f webpages."),$r[0]['total']);
		return $result;
	}	
	}

	$ret['success'] = true;
	return $ret;
}

