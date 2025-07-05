<?php

/*
Plugin Name: WP Product Fetcher
Plugin URI: https://kevin-benabdelhak.fr/plugins/wp-product-fetcher/
Description: WP Product Fetcher est un plugin WordPress conçu pour faciliter l'importation de données de produits depuis des sources externes en utilisant une API. Ce plugin permet aux utilisateurs de récupérer facilement des informations telles que le nom du produit, le prix et la description d'une URL spécifique. Grâce à une interface conviviale intégrée dans l'éditeur de produits WooCommerce, les utilisateurs peuvent entrer l'URL d'un produit et déclencher un scraping automatisé pour récupérer les détails nécessaires.
Version: 1.0
Author: Kevin BENABDELHAK
*/


if (!defined('ABSPATH')) {
    exit; 
}




if ( !class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
}
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$monUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/kevinbenabdelhak/wp-product-fetcher/', 
    __FILE__,
    'WP-Product-Fetcher' 
);

$monUpdateChecker->setBranch('main');







function add_scraper_meta_box() {
    add_meta_box(
        'scraper_meta_box', 
        'Scraper URL',
        'render_scraper_meta_box',
        'product',
        'side',
        'high' 
    );
}

add_action('add_meta_boxes', 'add_scraper_meta_box');

function render_scraper_meta_box($post) {
    ?>
    <div style="margin: 10px 0;">
        <input type="url" id="scraper_url" name="scraper_url" placeholder="Entrez l'URL ici" style="width: 100%; margin-bottom: 10px;">
        <button id="scraper_button" class="button button-primary">Scraper</button>
        <span id="scraper_loader" style="display:none; margin-left:10px;">
            <img src="<?php echo admin_url('images/loading.gif'); ?>" alt="Chargement..." style="vertical-align: middle; width: 20px; height: 20px;">
            <span id="scraper_step">Étape 1 : Scraping</span>
        </span>
    </div>
   <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#scraper_button').on('click', function(e) {
            e.preventDefault();

            var url = $('#scraper_url').val().trim();

            if (url === '') {
                alert('Veuillez entrer une URL valide.');
                return;
            }

            // Afficher le loader
            $('#scraper_loader').show();
            $('#scraper_button').prop('disabled', true); 

            $.ajax({
                url: "<?php echo admin_url('admin-ajax.php'); ?>",
                method: 'POST',
                data: {
                    action: 'scrape_product_content',
                    url: url,
                    security: '<?php echo wp_create_nonce('scraper_nonce'); ?>'
                },
success: function(response) {
    if (response.success) {
  
        let rewriteWithAI = '<?php echo get_option('wp_product_fetcher_rewrite_with_ai'); ?>' === 'on';

        $('#title').val(response.data.name); 
        $('#_price').val(response.data.price);
        $('#_regular_price').val(response.data.price); 

        if (rewriteWithAI) {
            $('#scraper_step').text('Étape 2 : Réécriture');

            $.ajax({
                url: "<?php echo admin_url('admin-ajax.php'); ?>",
                method: 'POST',
                data: {
                    action: 'rewrite_url_content',
                    title: response.data.name,
                    description: response.data.description,
                    security: '<?php echo wp_create_nonce('rewrite_url_nonce'); ?>'
                },
                success: function(rewriteResponse) {
                    if (rewriteResponse.success) {
                        tinyMCE.get('content').setContent(rewriteResponse.data.description); 
                    
                     $('#title').val(rewriteResponse.data.title); 
                    } else {
                        alert('Erreur lors de la réécriture : ' + rewriteResponse.data.message);
                    }
                },
                error: function() {
                    alert("Une erreur est survenue lors de la réécriture.");
                },
                complete: function() {
                    $('#scraper_loader').hide();
                    $('#scraper_button').prop('disabled', false);
                }
            });
        } else {
            tinyMCE.get('content').setContent(response.data.description);
            
            $('#scraper_loader').hide();
            $('#scraper_button').prop('disabled', false);
        }
    } else {
        alert('Erreur: ' + response.data.message);
        $('#scraper_loader').hide();
        $('#scraper_button').prop('disabled', false);
    }
},
                error: function() {
                    alert("Une erreur est survenue lors de la récupération des données.");
                },
                complete: function() {
               
                }
            });
        });
    });
</script>
    <?php
}

function scrape_product_content() {
    check_ajax_referer('scraper_nonce', 'security');

    $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';
    
    if (empty($url)) {
        wp_send_json_error(['message' => 'URL non fournie.']);
        return;
    }

    $api_key = get_option('wp_product_fetcher_api_key'); 
    $api_url = "https://product-fetcher.com/api/product?apiKey={$api_key}&url={$url}";

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (!$response) {
        wp_send_json_error(['message' => 'Erreur de récupération des données.']);
        return;
    }

    $data = json_decode($response, true);

    if (isset($data['name'], $data['price'], $data['description'])) {
        wp_send_json_success([
            'name' => $data['name'],
            'price' => $data['price'],
            'description' => $data['description'], 
        ]);
    } else {
        wp_send_json_error(['message' => 'Données invalides reçues.']);
    }
}

add_action('wp_ajax_scrape_product_content', 'scrape_product_content');

add_action('wp_ajax_rewrite_url_content', 'rewrite_url_content');
function rewrite_url_content() {
    check_ajax_referer('rewrite_url_nonce', 'security');

    $title = sanitize_text_field($_POST['title']);
    $description = sanitize_textarea_field($_POST['description']);
    $api_key = get_option('wp_product_fetcher_openai_api_key');

    if (empty($api_key)) {
        wp_send_json_error(['data' => 'Clé API OpenAI non configurée.']);
        return;
    }

    $prompt = "Paraphrase le titre suivant : '{$title}' et réécris la description suivante en paragraphe : '{$description}'. Retourne le résultat au format JSON avec les clés 'title' et 'description'.";

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 100,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 1,
            'max_tokens' => 4000,
            'top_p' => 1,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
            'response_format' => [
                'type' => 'json_object'
            ]
        ]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['data' => 'Erreur lors de la communication avec l\'API. Détails: ' . $response->get_error_message()]);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['choices'][0]['message']['content'])) {
        $json_response = json_decode($data['choices'][0]['message']['content'], true);

        if (isset($json_response['title'], $json_response['description'])) {
            wp_send_json_success(['title' => $json_response['title'], 'description' => $json_response['description']]);
        } else {
            wp_send_json_error(['data' => 'Aucune clé "title" ou "description" trouvée dans la réponse JSON.']);
        }
    } else {
        wp_send_json_error(['data' => 'Aucune réponse valide reçue de l\'API.']);
    }
}

function wp_product_fetcher_menu() {
    add_submenu_page(
        'edit.php?post_type=product',
        'WP Product Fetcher', 
        'WP Product Fetcher',
        'manage_options', 
        'wp-product-fetcher', 
        'wp_product_fetcher_options_page' 
    );
}

add_action('admin_menu', 'wp_product_fetcher_menu');

function wp_product_fetcher_options_page() {
    ?>
    <div class="wrap">
        <h1>WP Product Fetcher</h1>
        <p>Importez les données de pages produits externes avec <a href="https://product-fetcher.com/" target="_blank">Product Fetcher</a> et activez la réécriture avec <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI</a></p>
        <form method="post" action="options.php">
            <?php
            settings_fields('wp_product_fetcher_options_group');
            do_settings_sections('wp_product_fetcher');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Clé API Product Fetcher</th>
                    <td>
                        <input type="text" name="wp_product_fetcher_api_key" value="<?php echo esc_attr(get_option('wp_product_fetcher_api_key')); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Clé API OpenAI</th>
                    <td>
                        <input type="text" name="wp_product_fetcher_openai_api_key" value="<?php echo esc_attr(get_option('wp_product_fetcher_openai_api_key')); ?>" />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Options d'importation</th>
                    <td>
                        <label><input type="checkbox" name="wp_product_fetcher_replace_title" <?php checked(get_option('wp_product_fetcher_replace_title'), 'on'); ?>> Importer le titre</label><br>
                        <label><input type="checkbox" name="wp_product_fetcher_replace_price" <?php checked(get_option('wp_product_fetcher_replace_price'), 'on'); ?>> Importer le prix</label><br>
                        <label><input type="checkbox" name="wp_product_fetcher_replace_description" <?php checked(get_option('wp_product_fetcher_replace_description'), 'on'); ?>> Importer la description longue</label><br>
                        <label><input type="checkbox" name="wp_product_fetcher_rewrite_with_ai" <?php checked(get_option('wp_product_fetcher_rewrite_with_ai'), 'on'); ?>> Réécrire avec l'IA</label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function wp_product_fetcher_register_settings() {
    register_setting('wp_product_fetcher_options_group', 'wp_product_fetcher_api_key');
    register_setting('wp_product_fetcher_options_group', 'wp_product_fetcher_openai_api_key'); 
    register_setting('wp_product_fetcher_options_group', 'wp_product_fetcher_replace_title');
    register_setting('wp_product_fetcher_options_group', 'wp_product_fetcher_replace_price');
    register_setting('wp_product_fetcher_options_group', 'wp_product_fetcher_replace_description');
    register_setting('wp_product_fetcher_options_group', 'wp_product_fetcher_rewrite_with_ai');
}

add_action('admin_init', 'wp_product_fetcher_register_settings');