	<ul class="items">
		<li class="item clear">
			<span class="pct80"><?php _e('Average page generation time' ); ?></span><span class="comments pct20"><?php echo $static_cache_average; ?> sec.</span>
		</li>
		
		<li class="item clear">
			<span class="pct80"><?php _e( 'Total number of pages cached' ); ?></span><span class="comments pct20"><?php echo $static_cache_pages; ?></span>
		</li>
		
		<li class="item clear">
			<span class="pct80"><?php _e( 'Hits' ); ?></span><span class="comments pct20"><?php echo $static_cache_hits; ?>%</span>
		</li>
		
		<li class="item clear">
			<span class="pct80"><?php _e( 'Misses' ); ?></span><span class="comments pct20"><?php echo $static_cache_misses; ?>%</span>
		</li>
	</ul>
