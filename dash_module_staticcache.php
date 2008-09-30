	<ul class="items">
		<li class="item clear">
			<span class="pct80"><?php _e('Average page generation time' ); ?></span><span class="comments pct20"><?php echo $static_cache_average; ?> sec.</span>
		</li>
		
		<li class="item clear">
			<span class="pct80"><?php _e( 'Total number of pages cached' ); ?></span><span class="comments pct20"><?php echo $static_cache_pages; ?></span>
		</li>
		
		<li class="item clear">
			<span class="pct80"><?php _e( 'Hits' ); ?></span><span class="comments pct20"><?php echo $static_cache_hits; ?> (<?php echo $static_cache_hits_pct; ?>%)</span>
		</li>
		
		<li class="item clear">
			<span class="pct80"><?php _e( 'Misses' ); ?></span><span class="comments pct20"><?php echo $static_cache_misses; ?> (<?php echo $static_cache_misses_pct; ?>%)</span>
		</li>
		
		<li class="item clear">
			<script type="text/javascript">
			/* <![CDATA[ */
			function clearStaticCache() {
				var url = '<?php URL::out('auth_ajax', 'context=clear_staticcache'); ?>';
				spinner.start();
				$.get(
					url,
					function( json ) {
						spinner.stop();
						humanMsg.displayMsg( json );
					},
					'json'
				);
			}
			/* ]]> */
			</script>
			<span class="pct100"><a class="link_as_button" href="javascript:clearStaticCache();">Clear Cache Data</a></span>
		</li>
	</ul>
