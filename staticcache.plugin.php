<?php

class StaticCache extends Plugin
{
	const VERSION= '0.1';
	
	function info()
	{
		return array (
			'name' => 'StaticCache',
			'version' => '0.1',
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
	
	public function action_init()
	{
		$ignore_list = implode( 
			'|',
			array_map(
				create_function( '$a', 'return preg_quote(trim($a), "@");' ),
				explode( ',', Options::get( 'staticcache__ignore_list' ) )
				)
			);
		$request = Site::get_url( 'host' ) . $_SERVER['REQUEST_URI'];
		
		//don't cache pages matching ignore list keywords
		if ( preg_match( "@.*($ignore_list).*@i", $request ) ) {
			return;
		}
		
		$request_id = self::get_request_id();
		$query_id = self::get_query_id();
		
		if ( Cache::has( array("staticcache", $request_id) ) ) {
			$cache = Cache::get( array("staticcache", $request_id) );
			if ( isset( $cache[$query_id] ) ) {
				global $profile_start;
				echo $cache[$query_id];
				$time = microtime(true) - $profile_start;
				echo "<!-- Served by StaticCache in $time seconds -->";
				Options::set(
					'staticcache__average_time',
					( Options::get('staticcache__average_time') + $time ) / 2
				);
				exit;
			}
		}
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
		$theme->static_cache_average = sprintf( '%0.4f', Options::get('staticcache__average_time') );
		$theme->static_cache_pages = count( Cache::get_group('staticcache') );
		$module['content'] = $theme->fetch( 'static_cache_stats' );
		return $module;
	}
	
	public function cache_invalidate( $urls )
	{
		foreach ( Users::get_all() as $user ) {
			foreach( $urls as $url ) {
				$request_id = self::get_request_id( $user->id, $url );
				if ( Cache::has( array("staticcache", $request_id) ) ) {
					Cache::expire( array("staticcache", $request_id) );
				}
			}
		}
	}
	
	public function action_post_insert_after( $post )
	{
		$this->action_post_update_after( $post );
	}
	
	public function action_post_update_after( $post )
	{
		$urls = array(
			$post->comment_feed_link,
			$post->permalink,
			URL::get( 'atom_feed', 'index=1' ),
			Site::get_url( 'habari' )
			);
		$this->cache_invalidate( $urls );
	}
	
	public function action_comment_insert_after( $comment )
	{
		$this->action_comment_update_after( $comment );
	}
	
	public function action_comment_update_after( $comment )
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
			Options::set( 'staticcache__ignore_list', '/admin,/feedback,/user,?nocache' );
		}
	}
	
	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[]= _t('Configure', 'staticcache');
		}
		return $actions;
	}
	
	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			switch ( $action ) {
				case _t('Configure', 'staticcache') :
					$ui = new FormUI( 'staticcache' );
					
					$api_key= $ui->append( 'textarea', 'repos', 'staticcache__ignore_list', _t('Do not cache any URI\'s matching these keywords (comma seperated): ', 'staticcache') );
					$api_key->add_validator( 'validate_required' );
					
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
  		return crc32( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY ) );
	}
	
	public static function get_request_id( $user_id = null, $url = null )
	{
		if ( ! $user_id ) {
			$user = User::identify();
			$user_id = $user instanceof User ? $user->id : 0;
		}
		if ( ! $url ) {
			$url = Site::get_url( 'host' ) . rtrim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
		}
		return crc32( $user_id . $url );
	}
}

function StaticCache_ob_end_flush( $buffer )
{
	$request_id = StaticCache::get_request_id();
	$query_id = StaticCache::get_query_id();
	
	if ( Cache::has( array("staticcache", $request_id) ) ) {
		$cache = Cache::get( array("staticcache", $request_id) );
		$cache[$query_id] = $buffer;
	}
	else {
		$cache = array( $query_id => $buffer );
	}
	Cache::set( array("staticcache", $request_id), $cache );
	
	return false;
}

?>
