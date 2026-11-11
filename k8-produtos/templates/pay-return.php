<?php
defined('ABSPATH') || exit;
get_header();
?>
<main id="conteudo" class="bmt-purchase-page">
    <div class="bmt-purchase-inner"><?php echo \K8Produtos\PayReturn::render(); ?></div>
</main>
<?php get_footer(); ?>
