<?php

class StaticCache extends Plugin
{
	const VERSION = '0.2';
	
	const EXPIRE = 86400;
	
	function info()
	{
		return array (
			'name' => 'StaticCache',
			'version' => '0.2',
			'author' => 'Habari Community',
			'license' => 'Apache License 2.0',
			'description' => 'Caches static HTML ouptut'
		);
	}
	
	public function set_priorities()
	{
		return array(
			'action_init' => 1
			);
	}
	
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
	
	public function action_init()
	{
		/**
		 * Allows plugins to add to the ignore list. An array of all URLs to ignore
		 * is passed to the filter. Plugins should add a string of a URL to ignore
		 * to the array. To ignore anything with '/foo-bar' in it you would do:
		 * <code>$ignore_array[] = '/foo-bar';</code>
		 */
		$ignore_array = Plugins::filter(
			'staticcache_ignore',
			explode( ',', Options::get( 'staticcache__ignore_list' ) )
			);
		
		/** sanitize the ignore list for preg_match */
		$ignore_list = implode( 
			'|',
			array_map(
				create_function( '$a', 'return preg_quote(trim($a), "@");' ),
				$ignore_array
				)
			);
		$request = Site::get_url( 'host' ) . $_SERVER['REQUEST_URI'];
		
		/** don't cache pages matching ignore list keywords */
		if ( preg_match( "@.*($ignore_list).*@i", $request ) ) {
			return;
		}
		
		$request_id = self::get_request_id();
		$query_id = self::get_query_id();
		
		if ( Cache::has( array("staticcache", $request_id) ) ) {
			$cache = Cache::get( array("staticcache", $request_id) );
			if ( isset( $cache[$query_id] ) ) {
				global $profile_start;
				
				foreach( $cache[$query_id]['headers'] as $header ) {
					header($header);
				}
				echo $cache[$query_id]['body'];
				$time = microtime(true) - $profile_start;
				echo "<!-- Served by StaticCache in $time seconds -->";
				Options::set(
					'staticcache__average_time',
					( Options::get('staticcache__average_time') + $time ) / 2
				);
				Options::set('staticcache__hits', Options::get('staticcache__hits') + 1);
				exit;
			}
		}
		Options::set('staticcache__misses', Options::get('staticcache__misses') + 1);
		ob_start( 'StaticCache_ob_end_flush' );
	}
	
	public function filter_dash_modules( $modules )
	{
		$this->add_template( 'static_cache_stats', dirname( __FILE__ ) . '/dash_module_staticcache.php' );
		$modules[] = 'Static Cache';
		return $modules;
	}
	
	public function filter_dash_module_static_cache( $module, $id, $theme )
	{
		$theme->static_cache_average = sprintf( '%.4f', Options::get('staticcache__average_time') );
		$theme->static_cache_pages = count( Cache::get_group('staticcache') );
		
		$hits = Options::get('staticcache__hits');
		$misses = Options::get('staticcache__misses');
		$total = $hits + $misses;
		$theme->static_cache_hits_pct = sprintf('%.0f', $total > 0 ? ($hits/$total)*100 : 0);
		$theme->static_cache_misses_pct = sprintf('%.0f', $total > 0 ? ($misses/$total)*100 : 0);
		$theme->static_cache_hits = $hits;
		$theme->static_cache_misses = $misses;
		
		$module['content'] = $theme->fetch( 'static_cache_stats' );
		return $module;
	}
	
	public function action_auth_ajax_clear_staticcache()
	{
		foreach ( Cache::get_group('staticcache') as $name => $data ) {
			Cache::expire( array('staticcache', $name) );
		}
		Options::set('staticcache__hits', 0);
		Options::set('staticcache__misses', 0);
		Options::set('staticcache__average_time', 0);
		echo json_encode("Cleared Static Cache's cache");
	}
	
	public function cache_invalidate( array $urls )
	{
		// account for annonymous user (id=0)
		$user_ids = array_map( create_function('$a', 'return $a->id;'), Users::get_all()->getArrayCopy() );
		array_push($user_ids, "0");
		
		foreach ( $user_ids as $user_id ) {
			foreach( $urls as $url ) {
				$request_id = self::get_request_id( $user_id, $url );
				if ( Cache::has( array("staticcache", $request_id) ) ) {
					Cache::expire( array("staticcache", $request_id) );
				}
			}
		}
	}
	
	public function action_post_update_after( Post $post )
	{
		$urls = array(
			$post->comment_feed_link,
			$post->permalink,
			URL::get( 'atom_feed', 'index=1' ),
			Site::get_url( 'habari' )
			);
		$this->cache_invalidate( $urls );
	}
	
	public function action_comment_update_after( Comment $comment )
	{
		$urls = array(
			$comment->post->comment_feed_link,
			$comment->post->permalink,
			URL::get( 'atom_feed', 'index=1' ),
			Site::get_url( 'habari' )
			);
		$this->cache_invalidate( $urls );
	}
	
	public function action_plugin_activation( $file )
	{
		if ( $file == str_replace( '\\','/', $this->get_file() ) ) {
			Options::set( 'staticcache__ignore_list', '/admin,/feedback,/user,/ajax,/auth_ajax,?nocache' );
		}
	}
	
	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[]= _t('Configure', 'staticcache');
		}
		return $actions;
	}
	
	/**
	 * @todo add expire time option
	 * @todo add invalidate cache button
	 */
	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t('Configure', 'staticcache') :
					$ui = new FormUI( 'staticcache' );
					
					$ignore = $ui->append( 'textarea', 'ignore', 'staticcache__ignore_list', _t('Do not cache any URI\'s matching these keywords (comma seperated): ', 'staticcache') );
					$ignore->add_validator( 'validate_required' );
					
					$expire = $ui->append( 'text', 'expire', 'staticcache__expire', _t('Cache expiry (in seconds): ', 'staticcache') );
					$expire->add_validator( 'validate_required' );
					
					$ui->append( 'submit', 'save', _t( 'Save', 'staticcache' ) );
					$ui->set_option( 'success_message', _t( 'Configuration saved', 'staticcache' ) );
					$ui->out();
					break;
			}
		}
	}
	
	public function action_update_check()
  	{
    	Update::add( 'StaticCache', '340fb135-e1a1-4351-a81c-dac2f1795169',  $this->info->version );
  	}
  	
  	public static function get_query_id()
  	{
  		return crc32( parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) );
	}
	
	public static function get_request_id( $user_id = null, $url = null )
	{
		if ( ! $user_id ) {
			$user = User::identify();
			$user_id = $user instanceof User ? $user->id : 0;
		}
		if ( ! $url ) {
			$url = Site::get_url( 'host' ) . rtrim( parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/' );
		}
		return crc32( $user_id . $url );
	}
}

function StaticCache_ob_end_flush( $buffer )
{
	// prevent caching of 404 responses
	if ( !URL::get_matched_rule() || URL::get_matched_rule()->name == 'display_404' ) {
		return false;
	}
	$request_id = StaticCache::get_request_id();
	$query_id = StaticCache::get_query_id();
	$expire = Options::get('staticcache__expire') ? (int) Options::get('staticcache__expire') : StaticCache::EXPIRE;
	
	$cache = array( $query_id => array(
		'headers' => headers_list(),
		'body' => $buffer
		));
	Cache::set( array("staticcache", $request_id), $cache, $expire );
	
	return false;
}

?>
