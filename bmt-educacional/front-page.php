<?php defined('ABSPATH') || exit; get_header(); ?>
<main id="conteudo">
    <section class="bmt-hero">
        <div class="bmt-container bmt-hero-grid">
            <div class="bmt-hero-copy">
                <span class="bmt-label"><span class="bmt-dot"></span> Da escola para novas descobertas</span>
                <h1><?php echo esc_html(get_theme_mod('bmt_hero_title', 'Materiais e experiências para a vida escolar.')); ?></h1>
                <p><?php echo esc_html(get_theme_mod('bmt_hero_text', 'Materiais didáticos, paradidáticos e eventos para escolas. Encontre o que sua escola preparou e faça sua compra de um jeito simples.')); ?></p>
                <div class="bmt-hero-actions"><a class="bmt-button" href="#produtos">Explorar produtos <span aria-hidden="true">↗</span></a><a class="bmt-text-link" href="#como-comprar">Saiba como comprar <span aria-hidden="true">→</span></a></div>
                <div class="bmt-hero-note"><span class="bmt-note-icon" aria-hidden="true">✓</span> Escolha por aluno. Pagamento pela K8. Retirada com a escola.</div>
            </div>
            <div class="bmt-hero-art" aria-hidden="true">
                <div class="bmt-orbit orbit-one"></div><div class="bmt-orbit orbit-two"></div><span class="bmt-spark spark-one">✦</span><span class="bmt-spark spark-two">+</span>
                <div class="bmt-book book-back"><span>DESCOBRIR</span><i></i><i></i><i></i></div>
                <div class="bmt-book book-front"><span>BMT · EDUCAÇÃO</span><strong>Aprender<br>abre<br>caminhos.</strong><div class="bmt-book-window"></div><small>Cada descoberta conta.</small></div>
                <div class="bmt-event-card"><span><?php echo bmt_icon('evento'); ?></span><div><small>Além da sala de aula</small><strong>Experiências que ficam.</strong></div></div>
                <div class="bmt-art-tag">Aprender. Descobrir. Viver.</div>
            </div>
        </div>
    </section>
    <section class="bmt-category-strip bmt-container" aria-label="O que você encontra aqui">
        <?php foreach (['didatico' => ['Materiais didáticos', 'Para acompanhar cada etapa.'], 'paradidatico' => ['Paradidáticos', 'Histórias que ampliam horizontes.'], 'evento' => ['Eventos para escolas', 'Vivências que conectam.']] as $key => $item) { ?>
            <a href="<?php echo esc_url(add_query_arg('bmt_categoria', $key, home_url('/')) . '#produtos'); ?>"><span class="bmt-category-icon"><?php echo bmt_icon($key); ?></span><span><strong><?php echo esc_html($item[0]); ?></strong><small><?php echo esc_html($item[1]); ?></small></span><span class="bmt-category-arrow" aria-hidden="true">↗</span></a>
        <?php } ?>
    </section>
    <section id="produtos" class="bmt-products-section bmt-container">
        <div class="bmt-section-heading"><div><span class="bmt-label">Escolhas para aprender mais</span><h2>Produtos disponíveis</h2></div><p>Selecione o produto para consultar os alunos<br>e as condições da sua turma.</p></div>
        <?php if (shortcode_exists('k8_catalogo')) {
            echo do_shortcode('[k8_catalogo]');
        } else { ?>
            <div class="bmt-theme-empty"><h3>Em breve, novidades por aqui.</h3><p>Os materiais e eventos disponíveis serão publicados nesta página.</p><?php if (current_user_can('activate_plugins')) { ?><p>Ative o plugin K8 Produtos para conectar o catálogo ao site.</p><?php } ?></div>
        <?php } ?>
    </section>
    <section id="como-comprar" class="bmt-how-section">
        <div class="bmt-container"><div class="bmt-section-heading"><div><span class="bmt-label">Simples, do início ao fim</span><h2>Pronto para a próxima descoberta?</h2></div><p>Uma compra organizada.<br>Mais tranquilidade para sua família.</p></div>
            <div class="bmt-how-grid">
                <article><span>01</span><h3>Escolha o produto</h3><p>Encontre o material ou evento disponível para sua escola.</p></article>
                <article><span>02</span><h3>Selecione o aluno</h3><p>Informe o CPF do responsável e escolha o aluno vinculado.</p></article>
                <article><span>03</span><h3>Conclua sua compra</h3><p>Confira as condições, pague e acompanhe a confirmação e a retirada.</p></article>
            </div>
        </div>
    </section>
</main>
<?php get_footer(); ?>
