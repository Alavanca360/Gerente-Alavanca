<?php
/**
 * Plugin Name: Gerente Alavanca - Limpeza Inteligente WooCommerce
 * Description: Painel moderno para detectar/remover produtos duplicados e colocar produtos sem imagem em revisão (sob demanda, sem cron recorrente).
 * Version: 1.0.0
 * Author: Gerente Alavanca
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GA_Woo_Product_Cleaner {
    private const OPTION_LOGO_URL = 'ga_cleaner_logo_url';
    private const OPTION_CUSTOM_CSS = 'ga_cleaner_custom_css';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_ga_cleaner_run_no_image_review', [$this, 'handle_no_image_review']);
        add_action('admin_post_ga_cleaner_run_duplicate_cleanup', [$this, 'handle_duplicate_cleanup']);
        add_action('admin_post_ga_cleaner_save_branding', [$this, 'handle_save_branding']);
    }

    public function register_menu(): void {
        add_menu_page(
            __('Gerente Alavanca', 'ga-cleaner'),
            __('Gerente Alavanca', 'ga-cleaner'),
            'manage_woocommerce',
            'ga-cleaner-dashboard',
            [$this, 'render_admin_page'],
            'dashicons-shield-alt',
            56
        );
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_ga-cleaner-dashboard') {
            return;
        }

        wp_enqueue_style('ga-cleaner-inline-style', false, [], '1.0.0');
        wp_add_inline_style('ga-cleaner-inline-style', $this->get_admin_css());
    }

    private function get_admin_css(): string {
        $custom_css = (string) get_option(self::OPTION_CUSTOM_CSS, '');

        return <<<CSS
#ga-cleaner-app {
    max-width: 1080px;
    margin-top: 20px;
}

#ga-cleaner-app .ga-card {
    background: #ffffff;
    border: 1px solid #e7edf3;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 8px 30px rgba(19, 35, 52, 0.05);
    margin-bottom: 18px;
}

#ga-cleaner-app .ga-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 16px;
}

#ga-cleaner-app .ga-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}

#ga-cleaner-app .ga-logo {
    max-height: 52px;
    width: auto;
    border-radius: 8px;
}

#ga-cleaner-app h1,
#ga-cleaner-app h2 {
    margin-top: 0;
}

#ga-cleaner-app .ga-muted {
    color: #607080;
}

#ga-cleaner-app .ga-btn-wrap {
    margin-top: 16px;
}

#ga-cleaner-app .ga-pill {
    background: #f0f5fb;
    color: #1e4a7f;
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 12px;
    margin-left: 6px;
}

{$custom_css}
CSS;
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Você não tem permissão para acessar esta página.', 'ga-cleaner'));
        }

        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce não está ativo. Ative o WooCommerce para usar este plugin.', 'ga-cleaner') . '</p></div>';
            return;
        }

        $logo_url = (string) get_option(self::OPTION_LOGO_URL, '');
        ?>
        <div class="wrap" id="ga-cleaner-app">
            <div class="ga-header ga-card">
                <div>
                    <h1><?php echo esc_html__('Painel de Limpeza de Catálogo', 'ga-cleaner'); ?></h1>
                    <p class="ga-muted"><?php echo esc_html__('Ações iniciadas manualmente (sem cron infinito), com foco em performance e controle operacional.', 'ga-cleaner'); ?></p>
                </div>
                <div>
                    <?php if ($logo_url) : ?>
                        <img class="ga-logo" src="<?php echo esc_url($logo_url); ?>" alt="Logo" />
                    <?php else : ?>
                        <span class="ga-muted"><?php echo esc_html__('Sem logo configurado', 'ga-cleaner'); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php settings_errors('ga_cleaner_notices'); ?>

            <div class="ga-grid">
                <div class="ga-card">
                    <h2>
                        <?php echo esc_html__('Produtos sem imagem → Revisão', 'ga-cleaner'); ?>
                        <span class="ga-pill"><?php echo esc_html__('Manual', 'ga-cleaner'); ?></span>
                    </h2>
                    <p class="ga-muted"><?php echo esc_html__('Localiza produtos publicados sem imagem destacada e muda o status para pendente (revisão).', 'ga-cleaner'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('ga_cleaner_no_image_review'); ?>
                        <input type="hidden" name="action" value="ga_cleaner_run_no_image_review" />
                        <div class="ga-btn-wrap">
                            <button type="submit" class="button button-primary button-hero">
                                <?php echo esc_html__('Iniciar varredura sem imagem', 'ga-cleaner'); ?>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="ga-card">
                    <h2>
                        <?php echo esc_html__('Produtos duplicados → Limpeza em lote', 'ga-cleaner'); ?>
                        <span class="ga-pill"><?php echo esc_html__('Manual', 'ga-cleaner'); ?></span>
                    </h2>
                    <p class="ga-muted"><?php echo esc_html__('Mantém o item mais antigo e move os duplicados para a lixeira, agrupando por SKU e título.', 'ga-cleaner'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('ga_cleaner_duplicate_cleanup'); ?>
                        <input type="hidden" name="action" value="ga_cleaner_run_duplicate_cleanup" />
                        <div class="ga-btn-wrap">
                            <button type="submit" class="button button-secondary button-hero">
                                <?php echo esc_html__('Iniciar limpeza de duplicados', 'ga-cleaner'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="ga-card">
                <h2><?php echo esc_html__('Branding do painel', 'ga-cleaner'); ?></h2>
                <p class="ga-muted"><?php echo esc_html__('Use seu logotipo e personalize estilos para combinar com seu painel de gerenciamento.', 'ga-cleaner'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('ga_cleaner_save_branding'); ?>
                    <input type="hidden" name="action" value="ga_cleaner_save_branding" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="ga_cleaner_logo_url"><?php echo esc_html__('URL do logotipo', 'ga-cleaner'); ?></label></th>
                            <td>
                                <input id="ga_cleaner_logo_url" class="regular-text" type="url" name="ga_cleaner_logo_url" value="<?php echo esc_attr($logo_url); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ga_cleaner_custom_css"><?php echo esc_html__('CSS personalizado', 'ga-cleaner'); ?></label></th>
                            <td>
                                <textarea id="ga_cleaner_custom_css" name="ga_cleaner_custom_css" rows="8" class="large-text code"><?php echo esc_textarea((string) get_option(self::OPTION_CUSTOM_CSS, '')); ?></textarea>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Salvar branding', 'ga-cleaner')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public function handle_no_image_review(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permissão negada.', 'ga-cleaner'));
        }

        check_admin_referer('ga_cleaner_no_image_review');

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1000,
            'meta_query' => [[
                'key' => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ]],
        ]);

        $updated = 0;
        if (!empty($query->posts)) {
            foreach ($query->posts as $product_id) {
                if (!get_post_thumbnail_id($product_id)) {
                    wp_update_post([
                        'ID' => $product_id,
                        'post_status' => 'pending',
                    ]);
                    $updated++;
                }
            }
        }

        add_settings_error(
            'ga_cleaner_notices',
            'ga_cleaner_no_image_result',
            sprintf(__('Finalizado: %d produto(s) sem imagem enviado(s) para revisão.', 'ga-cleaner'), $updated),
            'updated'
        );
        set_transient('settings_errors', get_settings_errors(), 30);

        wp_safe_redirect(admin_url('admin.php?page=ga-cleaner-dashboard'));
        exit;
    }

    public function handle_duplicate_cleanup(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permissão negada.', 'ga-cleaner'));
        }

        check_admin_referer('ga_cleaner_duplicate_cleanup');

        $products = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $groups = [];
        foreach ($products as $product_id) {
            $sku = trim((string) get_post_meta($product_id, '_sku', true));
            $title = trim((string) get_the_title($product_id));

            $key = $sku ? 'sku::' . strtolower($sku) : 'title::' . strtolower($title);

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = $product_id;
        }

        $trashed = 0;
        foreach ($groups as $group) {
            if (count($group) <= 1) {
                continue;
            }

            // Mantém o primeiro (mais antigo) e remove o restante.
            array_shift($group);

            foreach ($group as $duplicate_id) {
                wp_trash_post($duplicate_id);
                $trashed++;
            }
        }

        add_settings_error(
            'ga_cleaner_notices',
            'ga_cleaner_duplicate_result',
            sprintf(__('Finalizado: %d produto(s) duplicado(s) enviado(s) para a lixeira.', 'ga-cleaner'), $trashed),
            'updated'
        );
        set_transient('settings_errors', get_settings_errors(), 30);

        wp_safe_redirect(admin_url('admin.php?page=ga-cleaner-dashboard'));
        exit;
    }

    public function handle_save_branding(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permissão negada.', 'ga-cleaner'));
        }

        check_admin_referer('ga_cleaner_save_branding');

        $logo_url = isset($_POST['ga_cleaner_logo_url']) ? esc_url_raw(wp_unslash($_POST['ga_cleaner_logo_url'])) : '';
        $custom_css = isset($_POST['ga_cleaner_custom_css']) ? sanitize_textarea_field(wp_unslash($_POST['ga_cleaner_custom_css'])) : '';

        update_option(self::OPTION_LOGO_URL, $logo_url);
        update_option(self::OPTION_CUSTOM_CSS, $custom_css);

        add_settings_error(
            'ga_cleaner_notices',
            'ga_cleaner_branding_saved',
            __('Branding atualizado com sucesso.', 'ga-cleaner'),
            'updated'
        );
        set_transient('settings_errors', get_settings_errors(), 30);

        wp_safe_redirect(admin_url('admin.php?page=ga-cleaner-dashboard'));
        exit;
    }
}

new GA_Woo_Product_Cleaner();
