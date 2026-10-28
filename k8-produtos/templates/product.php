<?php
defined('ABSPATH') || exit;
get_header();
?>
<main id="conteudo" class="bmt-purchase-page">
    <div class="bmt-purchase-inner">
        <a class="bmt-back" href="<?php echo esc_url(home_url('/#produtos')); ?>">← Voltar aos produtos</a>
        <?php echo \K8Produtos\Views::product_shortcode(['id' => absint(get_query_var('bmt_produto'))]); ?>
    </div>
</main>
<?php get_footer(); ?>
