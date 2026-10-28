<?php
defined('ABSPATH') || exit;

add_action('after_setup_theme', static function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo', ['height' => 100, 'width' => 245, 'flex-height' => true, 'flex-width' => true]);
    register_nav_menus(['primary' => 'Menu principal']);
});

add_action('wp_enqueue_scripts', static function () {
    wp_enqueue_style('bmt-educacional', get_stylesheet_uri(), [], wp_get_theme()->get('Version'));
});

require_once __DIR__ . '/update-channel.php';
new BMT_Update_Channel('bmt-educacional', 'theme', '1.0.0', __FILE__);

function bmt_logo(): void
{
    if (has_custom_logo()) {
        the_custom_logo();
    } else {
        echo '<a class="bmt-logo" href="' . esc_url(home_url('/')) . '" aria-label="BMT Comercial — início"><img src="' . esc_url(get_template_directory_uri() . '/assets/logo-bmt.webp') . '" alt="BMT Comercial" width="180" height="74"></a>';
    }
}

function bmt_icon(string $category): string
{
    return class_exists('K8Produtos\Storefront') ? \K8Produtos\Storefront::icon($category) : '';
}

function bmt_contact_url(): string
{
    $phone = preg_replace('/\D/', '', (string) get_theme_mod('bmt_whatsapp', ''));
    if (preg_match('/^55\d{10,11}$/D', $phone)) {
        return 'https://wa.me/' . $phone;
    }
    $email = sanitize_email(get_theme_mod('bmt_email', ''));
    return $email ? 'mailto:' . $email : home_url('/#como-comprar');
}

add_action('customize_register', static function ($customizer) {
    $customizer->add_section('bmt_home', ['title' => 'BMT — Página inicial', 'priority' => 30]);
    foreach ([
        'bmt_hero_title' => ['Título principal', 'Materiais e experiências para a vida escolar.'],
        'bmt_hero_text' => ['Texto de apresentação', 'Materiais didáticos, paradidáticos e eventos para escolas. Encontre o que sua escola preparou e faça sua compra de um jeito simples.'],
        'bmt_email' => ['E-mail de atendimento (opcional)', ''],
        'bmt_whatsapp' => ['WhatsApp com 55 e DDD (opcional)', ''],
    ] as $key => $field) {
        $customizer->add_setting($key, ['default' => $field[1], 'sanitize_callback' => $key === 'bmt_email' ? 'sanitize_email' : 'sanitize_text_field']);
        $customizer->add_control($key, ['section' => 'bmt_home', 'label' => $field[0], 'type' => $key === 'bmt_hero_text' ? 'textarea' : 'text']);
    }
});
