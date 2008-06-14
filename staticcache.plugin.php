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
			'description' => 'Caches static html ouptut'
		);
	}
	
	public function load()
	{
		$request = Site::get_url( 'host' ) . $_SERVER['REQUEST_URI'];
		
		//don't cache admin pages
		if ( strpos( $request, Site::get_url( 'admin' ) ) !== false ) {
			return;
		}
		
		if ( Cache::has( 'staticcache-' . $request ) ) {
			global $profile_start;
			echo Cache::get( 'staticcache-' . $request );
			$time = microtime(true) - $profile_start;
			echo "<!-- Served by StaticCache in $time seconds -->";
			exit;
		}
		else {
			ob_start( 'StaticCache_ob_end_flush' );
		}
	}
	
	public function action_update_check()
  	{
    	//Update::add( 'StaticCache', '',  $this->info->version );
  	}
}

function StaticCache_ob_end_flush( $buffer )
{
	$request = Site::get_url( 'host' ) . $_SERVER['REQUEST_URI'];
	Cache::set( 'staticcache-' . $request, $buffer );
	return false;
}


?>
