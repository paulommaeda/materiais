<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('bmt-site'); ?>>
<?php wp_body_open(); ?>
<a class="bmt-skip" href="#conteudo">Pular para o conteúdo</a>
<div class="bmt-topline"><div class="bmt-container">Materiais didáticos, paradidáticos e eventos para escolas <span>Educação em cada escolha.</span></div></div>
<header class="bmt-header">
    <div class="bmt-container bmt-header-row">
        <?php bmt_logo(); ?>
        <nav class="bmt-nav" aria-label="Menu principal">
            <?php if (has_nav_menu('primary')) {
                wp_nav_menu(['theme_location' => 'primary', 'container' => false, 'menu_class' => 'bmt-menu', 'depth' => 1]);
            } else { ?>
                <a href="<?php echo esc_url(home_url('/')); ?>">Início</a>
                <a href="<?php echo esc_url(home_url('/#produtos')); ?>">Produtos</a>
                <a href="<?php echo esc_url(home_url('/#como-comprar')); ?>">Como comprar</a>
            <?php } ?>
        </nav>
        <a class="bmt-button bmt-button-small" href="<?php echo esc_url(home_url('/#produtos')); ?>">Ver produtos <span aria-hidden="true">↗</span></a>
    </div>
</header>
