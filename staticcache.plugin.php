<?php

/**
 * @package staticcache
 */

/**
 * StaticCache Plugin will cache the HTML output generated by Habari for each page.
 */
class StaticCache extends Plugin
{
	const VERSION = 0.3;
	const API_VERSION = 004;
	
	const GZ_COMPRESSION = 4;
	const EXPIRE = 86400;
	const EXPIRE_STATS = 604800;
	
	const GROUP_NAME = 'staticcache';
	const STATS_GROUP_NAME = 'staticcache_stats';
	
	/**
	 * Set a priority of 1 on action_init so we run first
	 *
	 * @return array the priorities for our hooks
	 */
	public function set_priorities()
	{
		return array(
			'action_init' => 1
			);
	}
	
	/**
	 * Create aliases to additional hooks
	 *
	 * @return array aliased hooks
	 */
	public function alias()
	{
		return array(
			'action_post_update_after' => array(
				'action_post_insert_after',
				'action_post_delete_after'
			),
			'action_comment_update_after' => array(
				'action_comment_insert_after',
				'action_comment_delete_after'
			)
		);
	}
	
	/**
	 * Serves the cache page or starts the output buffer. Ignore URLs matching
	 * the ignore list, and ignores if there are session messages.
	 *
	 * @see StaticCache_ob_end_flush()
	 */
	public function action_init()
	{
		/**
		 * Allows plugins to add to the ignore list. An array of all URLs to ignore
		 * is passed to the filter.
		 *
		 * @filter staticcache_ignore an array of URLs to ignore
		 */
		$ignore_array = Plugins::filter(
			'staticcache_ignore',
			explode(',', Options::get('staticcache__ignore_list' ))
		);
		
		// sanitize the ignore list for preg_match
		$ignore_list = implode( 
			'|',
			array_map(
				create_function('$a', 'return preg_quote(trim($a), "@");'),
				$ignore_array
			)
		);
		$request = Site::get_url('host') . $_SERVER['REQUEST_URI'];
		$request_method = $_SERVER['REQUEST_METHOD'];
		
		/* don't cache PUT or POST requests, pages matching ignore list keywords, 
		 * nor pages with session messages
		 */
		if ( $request_method == 'PUT' || $request_method == 'POST'
			|| preg_match("@.*($ignore_list).*@i", $request) || Session::has_messages() || ) {
			return;
		}
		
		$request_id = self::get_request_id();
		$query_id = self::get_query_id();
		
		if ( Cache::has(array(self::GROUP_NAME, $request_id)) ) {
			$cache = Cache::get( array(self::GROUP_NAME, $request_id) );
			if ( isset( $cache[$query_id] ) ) {
				global $profile_start;
				
				// send the cached headers
				foreach( $cache[$query_id]['headers'] as $header ) {
					header($header);
				}
				// check for compression
				if ( isset($cache[$query_id]['compressed']) && $cache[$query_id]['compressed'] == true ) {
					echo gzuncompress($cache[$query_id]['body']);
				}
				else {
					echo $cache[$query_id]['body'];
				}
				// record hit and profile data
				$this->record_stats('hit', $profile_start);
				exit;
			}
		}
		// record miss
		$this->record_stats('miss');
		ob_start('StaticCache_ob_end_flush');
	}
	
	/**
	 * Record StaticCaches stats in the cache itself to avoid DB writes.
	 * Data includes hits, misses, and avg.
	 *
	 * @param string $type type of record, either hit or miss
	 * @param double $profile_start start of the profiling
	 */
	protected function record_stats( $type, $profile_start = null )
	{
		switch ( $type ) {
			case 'hit':
				// do stats and output profiling
				$pagetime = microtime(true) - $profile_start;
				$hits = (int) Cache::get(array(self::STATS_GROUP_NAME, 'hits'));
				$profile = (double) Cache::get(array(self::STATS_GROUP_NAME, 'avg'));
				$avg = ($profile * $hits + $pagetime) / ($hits + 1);
				Cache::set( array(self::STATS_GROUP_NAME, 'avg'), $avg, self::EXPIRE_STATS );
				Cache::set( array(self::STATS_GROUP_NAME, 'hits'), $hits + 1, self::EXPIRE_STATS );
				// @todo add option to have output or not
				echo '<!-- ' , _t( 'Served by StaticCache in %s seconds', array($pagetime), 'staticcache' ) , ' -->';
				break;
			case 'miss':
				Cache::set( array(self::STATS_GROUP_NAME, 'misses'), Cache::get(array(self::STATS_GROUP_NAME, 'misses')) + 1, self::EXPIRE_STATS );
				break;
		}
	}
	
	/**
	 * Add the Static Cache dashboard module
	 *
	 * @param array $modules Available dash modules
	 * @return array modules array
	 */
	public function filter_dash_modules( array $modules )
	{
		$this->add_template('static_cache_stats', dirname( __FILE__ ) . '/dash_module_staticcache.php');
		$modules[] = 'Static Cache';
		return $modules;
	}
	
	/**
	 * Filters the static cache dash module to add the theme template output.
	 *
	 * @param array $module the struture of the module
	 * @param Theme the current theme from the handler
	 * @return array the modified module structure
	 */
	public function filter_dash_module_static_cache( array $module, $id, Theme $theme )
	{
		$theme->static_cache_average = sprintf( '%.4f', Cache::get(array(self::STATS_GROUP_NAME, 'avg')) );
		$theme->static_cache_pages = count(Cache::get_group(self::GROUP_NAME));
		
		$hits = Cache::get(array(self::STATS_GROUP_NAME, 'hits'));
		$misses = Cache::get(array(self::STATS_GROUP_NAME, 'misses'));
		$total = $hits + $misses;
		$theme->static_cache_hits_pct = sprintf('%.0f', $total > 0 ? ($hits/$total)*100 : 0);
		$theme->static_cache_misses_pct = sprintf('%.0f', $total > 0 ? ($misses/$total)*100 : 0);
		$theme->static_cache_hits = $hits;
		$theme->static_cache_misses = $misses;
		
		$module['content'] = $theme->fetch('static_cache_stats');
		return $module;
	}
	
	/**
	 * Ajax entry point for the 'clear cache data' action. Clears all stats and cache data
	 * and outputs a JSON encoded string message.
	 */
	public function action_auth_ajax_clear_staticcache()
	{
		foreach ( Cache::get_group(self::GROUP_NAME) as $name => $data ) {
			Cache::expire( array(self::GROUP_NAME, $name) );
		}
		foreach ( Cache::get_group(self::STATS_GROUP_NAME) as $name => $data ) {
			Cache::expire( array(self::STATS_GROUP_NAME, $name) );
		}
		echo json_encode(_t( "Cleared Static Cache's cache" ) );
	}
	
	/**
	 * Invalidates (expires) the cache entries for the give list of URLs.
	 *
	 * @param array $urls An array of urls to clear
	 */
	public function cache_invalidate( array $urls )
	{
		// account for annonymous user (id=0)
		$user_ids = array_map( create_function('$a', 'return $a->id;'), Users::get_all()->getArrayCopy() );
		array_push($user_ids, "0");
		
		// expire the urls for each user id
		foreach ( $user_ids as $user_id ) {
			foreach( $urls as $url ) {
				$request_id = self::get_request_id( $user_id, $url );
				if ( Cache::has(array(self::GROUP_NAME, $request_id)) ) {
					Cache::expire(array(self::GROUP_NAME, $request_id));
				}
			}
		}
	}
	
	/**
	 * Clears cache for the given post after it's updated. includes all CRUD operations.
	 *
	 * @param Post the post object to clear cache for
	 * @see StaticCache::cache_invalidate()
	 */
	public function action_post_update_after( Post $post )
	{
		$urls = array(
			$post->comment_feed_link,
			$post->permalink,
			URL::get('atom_feed', 'index=1'),
			Site::get_url('habari')
			);
		$this->cache_invalidate($urls);
	}
	
	/**
	 * Clears cache for the given comments parent post after it's updated. includes all
	 * CRUD operations.
	 *
	 * @param Comment the comment object to clear cache for it's parent post
	 * @see StaticCache::cache_invalidate()
	 */
	public function action_comment_update_after( Comment $comment )
	{
		$urls = array(
			$comment->post->comment_feed_link,
			$comment->post->permalink,
			URL::get('atom_feed', 'index=1'),
			Site::get_url('habari')
			);
		$this->cache_invalidate($urls);
	}
	
	/**
	 * Setup the initial ignore list on activation. Ignores URLs matching the following:
	 * /admin, /feedback, /user, /ajax, /auth_ajax, and ?nocache
	 */
	public function action_plugin_activation()
	{
		Options::set('staticcache__ignore_list', '/admin,/feedback,/user,/ajax,/auth_ajax,?nocache');
	}
	
	/**
	 * Adds a 'configure' action to the pllugin page.
	 *
	 * @param array $actions the default plugin actions
	 * @param strinf $plugin_id the plugins id
	 * @return array the actions to add
	 */
	public function filter_plugin_config( array $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[]= _t('Configure', 'staticcache');
		}
		return $actions;
	}
	
	/**
	 * Adds the configure UI
	 *
	 * @todo add invalidate cache button
	 * @param string $plugin_id the plugins id
	 * @param string $action the action being performed
	 */
	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t('Configure', 'staticcache') :
					$ui = new FormUI('staticcache');
					
					$ignore = $ui->append('textarea', 'ignore', 'staticcache__ignore_list', _t('Do not cache any URI\'s matching these keywords (comma seperated): ', 'staticcache'));
					$ignore->add_validator('validate_required');
					
					$expire = $ui->append('text', 'expire', 'staticcache__expire', _t('Cache expiry (in seconds): ', 'staticcache'));
					$expire->add_validator('validate_required');
					
					if ( extension_loaded('zlib') ) {
						$compress = $ui->append('checkbox', 'compress', 'staticcache__compress', _t('Compress Cache To Save Space: ', 'staticcache'));
					}
					
					$ui->append('submit', 'save', _t('Save', 'staticcache'));
					$ui->on_success( array( $this, 'save_config_msg' ) );
					$ui->out();
					break;
			}
		}
	}

    public static function save_config_msg( $ui )
	{
		$ui->save();
		Session::notice( _t( 'Options saved' ) );
		return false;
	}
	
	/**
	 * Adds the plugin to the update check routine.
	 */
	public function action_update_check()
	{
		Update::add('StaticCache', '340fb135-e1a1-4351-a81c-dac2f1795169',  self::VERSION);
	}
	
	/**
	 * gets a unique id for the current query string requested.
	 *
	 * @return string Query ID
	 */
	public static function get_query_id()
	{
		return crc32(parse_url(Site::get_url('host') . $_SERVER['REQUEST_URI'], PHP_URL_QUERY));
	}
	
	/**
	 * Gets a unique id for the given request URL and user id.
	 *
	 * @param int the users id. Defaults to current users id or 0 for anonymous
	 * @param string The URL. Defaults to the current REQUEST_URI
	 * @return string Request ID
	 */
	public static function get_request_id( $user_id = null, $url = null )
	{
		if ( ! $user_id ) {
			$user = User::identify();
			$user_id = $user instanceof User ? $user->id : 0;
		}
		if ( ! $url ) {
			$url = Site::get_url('host') . rtrim(parse_url(Site::get_url('host') . $_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
		}
		return crc32($user_id . $url);
	}
}

/**
 * The output buffer callback used to capture the output and cache it.
 *
 * @see StaticCache::init()
 * @param string $buffer The output buffer contents
 * @return bool false
 */
function StaticCache_ob_end_flush( $buffer )
{
	// prevent caching of 404 responses
	if ( !URL::get_matched_rule() || URL::get_matched_rule()->name == 'display_404' ) {
		return false;
	}
	$request_id = StaticCache::get_request_id();
	$query_id = StaticCache::get_query_id();
	$expire = Options::get('staticcache__expire') ? (int) Options::get('staticcache__expire') : StaticCache::EXPIRE;
	
	// get cache if exists
	if ( Cache::has(array(StaticCache::GROUP_NAME, $request_id)) ) {
		$cache = Cache::get(array(StaticCache::GROUP_NAME, $request_id));
	}
	else {
		$cache = array();
	}
	
	// see if we want compression and store cache
	$cache[$query_id] = array(
		'headers' => headers_list(),
		'request_uri' => Site::get_url('host') . $_SERVER['REQUEST_URI']
	);
	if ( Options::get('staticcache__compress') && extension_loaded('zlib') ) {
		$cache[$query_id]['body'] = gzcompress($buffer, StaticCache::GZ_COMPRESSION);
		$cache[$query_id]['compressed'] = true;
	}
	else {
		$cache[$query_id]['body'] = $buffer;
		$cache[$query_id]['compressed'] = false;
	}
	Cache::set( array(StaticCache::GROUP_NAME, $request_id), $cache, $expire );
	
	return false;
}

?>
