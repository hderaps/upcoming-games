<?php
/**
 * Template Name: Ice Zoo Games Calendar
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="gc-page" class="gc-page">

	<header class="gc-page-header" style="text-align:center;">
		<h1 class="gc-page-title"><?php the_title(); ?></h1>
		<?php
		// Show any intro text the editor may have added to the page body
		$content = get_the_content();
		if ( $content ) {
			echo '<div class="gc-page-intro">';
			the_content();
			echo '</div>';
		}
		?>
	</header>

	<?php
	$fetcher  = new GC_Fetcher();
	$upcoming = $fetcher->get_upcoming_games();
	$past     = $fetcher->get_past_results();
	gc_render_all( $upcoming, $past );
	?>

</main>

<?php get_footer(); ?>
