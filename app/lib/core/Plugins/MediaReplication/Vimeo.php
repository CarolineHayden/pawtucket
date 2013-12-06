<?php
/** ---------------------------------------------------------------------
 * app/lib/core/Plugins/MediaReplication/WLPlugMediaReplicationVimeo.php : replicates media to Vimeo
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2013 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage MediaReplication
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

  /**
    *
    */ 
    
include_once(__CA_LIB_DIR__."/core/Parsers/getid3/getid3.php");
include_once(__CA_APP_DIR__."/helpers/utilityHelpers.php");
include_once(__CA_LIB_DIR__."/core/Plugins/IWLPlugMediaReplication.php");
include_once(__CA_LIB_DIR__."/core/Plugins/MediaReplication/BaseMediaReplicationPlugin.php");
include_once(__CA_LIB_DIR__."/core/Vimeo/vimeo.php");

class WLPlugMediaReplicationVimeo Extends BaseMediaReplicationPlugin {
	# ------------------------------------------------
	/**
	 * Keys given out by Vimeo for our app https://developer.vimeo.com/apps/30027.
	 * In addition to that, we need an 'access token' and an 'access token secret'. 
	 * Both are account-specific and should be entered by the user via config file.
	 */
	private $ops_client_id = 'b8e4648e40edfa71dbff90531c7c617d3e234b71';
	private $ops_client_secret = '0cbddcf990344763cc4007c9c430fe6f3f150647';
	
	/**
	 * Target info from config file
	 */
	private $opa_target_info;
	
	/**
	 * Vimeo client
	 */
	private $opo_client = null;
	
	/**
	 * Error registry
	 */
	private $opa_errors = array();
	# ------------------------------------------------
	/**
	 *
	 */
	public function __construct($pa_target_info=null) {
		parent::__construct();
		$this->info['NAME'] = 'Vimeo';
		
		$this->description = _t('Replicates media to Vimeo using the Advanced API');
		
		if ($pa_target_info) {
			$this->setTargetInfo($pa_target_info);
		}
	}
	# ------------------------------------------------
	/**
	 *
	 */
	public function setTargetInfo($pa_target_info) {
		$this->opa_target_info = $pa_target_info;
		return $this->getClient(array('reset')); 
	}
	# ------------------------------------------------
	/**
	 * @return string Unique request token. The token can be used on subsequent calls to fetch information about the replication request
	 */
	public function initiateReplication($ps_filepath, $pa_data, $pa_options=null) {
		$o_client = $this->getClient();

		try {
			// upload video to vimeo, set properties afterwards
			if($vs_video_id = $o_client->upload($ps_filepath)) {
				// Vimeo's privacy settings are string values like 'nobody' or 'anybody'.
				$vs_privacy_setting = caGetOption('privacy', $pa_options, 'nobody');
				// this, however, is 1 or 0
				$vn_dl_privacy = caGetOption('downloadPrivacy', $pa_options, 0);
				// by, by-sa, by-nd, by-nc, by-nc-sa, or by-nc-nd. Set to 0 for no CC license.
				$vs_license = caGetOption('license',$pa_options,0);

				$o_client->call('vimeo.videos.setPrivacy', array('privacy' => $vs_privacy_setting, 'video_id' => $vs_video_id));
				$o_client->call('vimeo.videos.setTitle', array('title' => $pa_data['title'], 'video_id' => $vs_video_id));
				$o_client->call('vimeo.videos.setDescription', array('description' => $pa_data['description'], 'video_id' => $vs_video_id));
				$o_client->call('vimeo.videos.setDownloadPrivacy', array('download' => $vn_dl_privacy, 'video_id' => $vs_video_id));
				$o_client->call('vimeo.videos.setLicense', array('license' => $vs_license, 'video_id' => $vs_video_id));

				if(isset($pa_data['tags']) && is_array($pa_data['tags'])) {
					$o_client->call('vimeo.videos.addTags', array('tags' => join(',',$pa_data['tags']), 'video_id' => $vs_video_id));
				}
			} else {
				// upload() and all other phpVimeo methods throw their
				// own exceptions if something goes wrong, except in this case
				throw new VimeoAPIException(_t("File for replication doesn't exist"));
			}
		} catch (VimeoAPIException $e){
			if($vs_video_id){
				$va_errors[$vs_video_id][] = $e->getMessage();	
			}
			return false;
		}
		
		return $this->info['NAME']."://".$vs_video_id;
	}
	# ------------------------------------------------
	/**
	 *
	 */
	public function getReplicationStatus($ps_request_token, $pa_options=null) {
		$o_client = $this->getClient();

		$vs_video_id = preg_replace("!^".$this->info['NAME']."://!", "", $ps_request_token); // remove plugin identifier to obtain raw video ID

		$vo_info = $o_client->call('vimeo.videos.getInfo', array('video_id' => $vs_video_id));

		if($vo_info->stat != 'ok'){
			return __CA_MEDIA_REPLICATION_STATUS_ERROR__;
		}

		if($vo_info->video[0]->is_transcoding == 1) {
			return __CA_MEDIA_REPLICATION_STATUS_PROCESSING__;
		}

		if(strlen($vo_info->video[0]->urls->url[0]->_content)>0){
			return __CA_MEDIA_REPLICATION_STATUS_COMPLETE__;
		}

		return __CA_MEDIA_REPLICATION_STATUS_UNKNOWN__;
	}
	# ------------------------------------------------
	/**
	 *
	 */
	public function getReplicationErrors($ps_request_token) {
		$vs_video_id = preg_replace("!^".$this->info['NAME']."://!", "", $ps_request_token); // remove plugin identifier to obtain raw video ID
		if ($this->getReplicationStatus($ps_request_token) == __CA_MEDIA_REPLICATION_STATUS_ERROR__) {
			return is_array($va_errors = $this->opa_errors[$vs_video_id]) ? $va_errors : array();
		}
		return array();
	}
	# ------------------------------------------------
	/**
	 *
	 */
	public function getReplicationInfo($ps_request_token, $pa_options=null) {
		$o_client = $this->getClient();
		
		$vs_video_id = preg_replace("!^".$this->info['NAME']."://!", "", $ps_request_token); // remove plugin identifier to obtain raw video ID
		$this->opa_errors[$vs_video_id] = array();
		
		$vo_info = $o_client->call('vimeo.videos.getInfo', array('video_id' => $vs_video_id));
		
		return array(
			'id' => $vo_info->video[0]->id,
			'title' => $vo_info->video[0]->title,
			'description' => $vo_info->video[0]->description,
			'viewCount' => $vo_info->video[0]->number_of_plays,
			'pageUrl' => $vo_info->video[0]->urls->url[0]->_content,
			'playUrl' => $o_video_entry->getFlashPlayerUrl()
		);
	}
	# ------------------------------------------------
	/**
	 *
	 */
	public function removeReplication($ps_request_token, $pa_options=null) {
		$o_client = $this->getClient();
		$vs_video_id = preg_replace("!^".$this->info['NAME']."://!", "", $ps_request_token); // remove plugin identifier to obtain raw video ID

		//$o_client->call('vimeo.videos.delete', array('video_id' => $vs_video_id));
	}
	# ------------------------------------------------
	/**
	 *
	 */
	private function getClient($pa_options=null) {
		if ($vb_reset = (bool)caGetOption('reset', $pa_options, false)) {
			$this->opo_client = null;
		}
		if ($this->opo_client) { return $this->opo_client; }


		$this->opo_client = new phpVimeo(
			$this->ops_client_id,
			$this->ops_client_secret,
			$this->opa_target_info['access_token'],
			$this->opa_target_info['access_token_secret']
		);
		
    	return $this->opo_client;
	}
	# ------------------------------------------------
	/**
	 *
	 */
	public function getUrl($ps_key, $pa_options=null) {
		$va_tmp = explode("://", $ps_key);
		if((sizeof($va_tmp) == 2) && (strtolower($va_tmp[0]) == 'vimeo')) {
			return "http://www.vimeo.com/".$va_tmp[1];
		}
		return null;
	}
	# ------------------------------------------------
}
?>