<?php defined('ABSPATH') || exit; ?>
<footer class="bmt-footer">
    <div class="bmt-container bmt-footer-main">
        <div><?php bmt_logo(); ?><p>Materiais e experiências que fazem parte<br>da jornada de aprender.</p></div>
        <div><span class="bmt-label">Explore</span><a href="<?php echo esc_url(home_url('/#produtos')); ?>">Produtos disponíveis</a><a href="<?php echo esc_url(home_url('/#como-comprar')); ?>">Como comprar</a></div>
        <div><span class="bmt-label">Podemos ajudar?</span><p>Para dúvidas sobre materiais,<br>consulte sua escola ou fale conosco.</p><a href="<?php echo esc_url(bmt_contact_url()); ?>">Atendimento <span aria-hidden="true">↗</span></a></div>
    </div>
    <div class="bmt-container bmt-footer-bottom"><span>© <?php echo esc_html(wp_date('Y')); ?> BMT Comercial.</span><span><?php if (get_privacy_policy_url()) { ?><a href="<?php echo esc_url(get_privacy_policy_url()); ?>">Política de privacidade</a><?php } ?></span></div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
