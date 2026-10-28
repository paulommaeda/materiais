<?php defined('ABSPATH') || exit; get_header(); ?>
<main id="conteudo" class="bmt-container bmt-content">
    <?php if (have_posts()) { while (have_posts()) { the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>><h1><?php the_title(); ?></h1><div class="bmt-entry"><?php the_content(); wp_link_pages(); ?></div></article>
    <?php } the_posts_pagination(); } else { ?>
        <h1>Conteúdo não encontrado</h1><p>Volte ao início para consultar os produtos disponíveis.</p><a class="bmt-button" href="<?php echo esc_url(home_url('/')); ?>">Voltar ao início</a>
    <?php } ?>
</main>
<?php get_footer(); ?>
