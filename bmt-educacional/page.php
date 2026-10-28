<?php defined('ABSPATH') || exit; get_header(); ?>
<main id="conteudo" class="bmt-container bmt-content">
    <?php while (have_posts()) { the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>><h1><?php the_title(); ?></h1><div class="bmt-entry"><?php the_content(); wp_link_pages(); ?></div></article>
    <?php } ?>
</main>
<?php get_footer(); ?>
