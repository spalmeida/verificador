<?php
/*
Plugin Name: Verificador de Versão - Plugins e Temas
Plugin URI: https://github.com/spalmeida/verificador
Description: Retorna uma tabela com a versão atual e a última versão dos plugins e temas (ativos e inativos), destacando diferenças de versão. Somente administradores podem acessar e verificar as atualizações diretamente do GitHub.
Version: 1.0.0
Author: Samuel Almeida
Author URI: https://github.com/spalmeida
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Evita acesso direto
}

// Classe que controla as atualizações do GitHub
class GitHub_Updater {
    private $api_url = 'https://api.github.com/repos/spalmeida/verificador/releases/latest';
    private $plugin_file;

    public function __construct( $plugin_file ) {
        $this->plugin_file = $plugin_file;
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_updates' ] );
        add_filter( 'plugins_api', [ $this, 'get_plugin_info' ], 10, 3 );
    }

    // Verifica se há atualizações disponíveis no GitHub
    public function check_for_updates( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        // Pega a versão mais recente do GitHub
        $release_info = $this->get_latest_release_info();

        if ( ! $release_info ) {
            return $transient;
        }

        $plugin_data = get_plugin_data( $this->plugin_file );
        $current_version = $plugin_data['Version'];

        if ( version_compare( $current_version, $release_info['tag_name'], '<' ) ) {
            $plugin_slug = plugin_basename( $this->plugin_file );
            $transient->response[ $plugin_slug ] = (object) [
                'slug'        => $plugin_slug,
                'new_version' => $release_info['tag_name'],
                'package'     => $release_info['zipball_url'], // URL para o arquivo ZIP do GitHub
                'url'         => 'https://github.com/spalmeida/verificador',
            ];
        }

        return $transient;
    }

    // Obtém informações sobre o plugin, como a versão disponível e o link de download
    public function get_plugin_info( $false, $action, $response ) {
        if ( ! isset( $response->slug ) || $response->slug !== plugin_basename( $this->plugin_file ) ) {
            return $false;
        }

        // Pega a versão mais recente do GitHub
        $release_info = $this->get_latest_release_info();

        if ( ! $release_info ) {
            return $false;
        }

        $plugin_data = get_plugin_data( $this->plugin_file );

        $response->name           = $plugin_data['Name'];
        $response->slug           = plugin_basename( $this->plugin_file );
        $response->version        = $release_info['tag_name'];
        $response->download_link  = $release_info['zipball_url'];
        $response->sections       = [
            'description' => $plugin_data['Description'],
        ];

        return $response;
    }

    // Pega informações do release mais recente no GitHub
    private function get_latest_release_info() {
        $response = wp_remote_get( $this->api_url );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $release_data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $release_data['tag_name'] ) && isset( $release_data['zipball_url'] ) ) {
            return $release_data;
        }

        return false;
    }
}

// Carregar Bootstrap no front-end do plugin
function pt_verificador_version_enqueue_scripts() {
    wp_enqueue_style( 'bootstrap-css', 'https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css' );
}
add_action( 'wp_enqueue_scripts', 'pt_verificador_version_enqueue_scripts' );

// Criar um endpoint de URL
function pt_verificador_version_endpoint() {
    add_rewrite_rule( '^verificador-versao/?', 'index.php?verificador_versao=1', 'top' );
}
add_action( 'init', 'pt_verificador_version_endpoint' );

// Manipular a query var para o endpoint
function pt_verificador_version_query_vars( $query_vars ) {
    $query_vars[] = 'verificador_versao';
    return $query_vars;
}
add_filter( 'query_vars', 'pt_verificador_version_query_vars' );

// Manipular a solicitação para o endpoint
function pt_verificador_version_template_redirect() {
    if ( get_query_var( 'verificador_versao' ) ) {
        // Verifica se o usuário é administrador
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Você não tem permissão para acessar esta página.' ); // Mensagem de erro para quem não é admin
        }
        pt_verificador_version_output();
        exit;
    }
}
add_action( 'template_redirect', 'pt_verificador_version_template_redirect' );

// Função para pegar as atualizações disponíveis no WordPress
function pt_get_available_updates() {
    return get_site_transient( 'update_plugins' );
}

// Função que exibe a lista de plugins e temas com suas versões
function pt_verificador_version_output() {
    header( 'Content-Type: text/html; charset=utf-8' );
    
    // Carregar CSS Bootstrap
    echo '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">';
    echo '<div class="container mt-5">';

    // Lista de plugins
    $all_plugins = get_plugins();
    $updates = pt_get_available_updates();
    echo '<h2>Plugins</h2>';
    echo '<table class="table table-bordered">';
    echo '<thead><tr><th>Nome</th><th>Versão Atual</th><th>Última Versão</th></tr></thead><tbody>';
    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
        $current_version = $plugin_data['Version'];
        $latest_version = isset( $updates->response[ $plugin_file ]->new_version ) ? $updates->response[ $plugin_file ]->new_version : $current_version;
        
        if ( version_compare( $current_version, $latest_version, '<' ) ) {
            $color = 'red';
            $style = 'font-weight: bold;';
        } else {
            $color = 'green';
            $style = '';
        }

        echo '<tr>';
        echo '<td>' . esc_html( $plugin_data['Name'] ) . '</td>';
        echo '<td style="color:' . $color . '; ' . $style . '">' . esc_html( $current_version ) . '</td>';
        echo '<td>' . esc_html( $latest_version ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Lista de temas (ativos e inativos)
    $themes = wp_get_themes();
    echo '<h2>Temas</h2>';
    echo '<table class="table table-bordered">';
    echo '<thead><tr><th>Nome</th><th>Versão Atual</th><th>Última Versão</th></tr></thead><tbody>';
    foreach ( $themes as $theme ) {
        $current_theme_version = $theme->get( 'Version' );
        $latest_theme_version = isset( $updates->response[ $theme->get_stylesheet() ]->new_version ) ? $updates->response[ $theme->get_stylesheet() ]->new_version : $current_theme_version;
        
        if ( version_compare( $current_theme_version, $latest_theme_version, '<' ) ) {
            $color = 'red';
            $style = 'font-weight: bold;';
        } else {
            $color = 'green';
            $style = '';
        }

        echo '<tr>';
        echo '<td>' . esc_html( $theme->get( 'Name' ) ) . '</td>';
        echo '<td style="color:' . $color . '; ' . $style . '">' . esc_html( $current_theme_version ) . '</td>';
        echo '<td>' . esc_html( $latest_theme_version ) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    
    echo '</div>';
}

// Flush rewrite rules ao ativar o plugin
function pt_verificador_version_activate() {
    pt_verificador_version_endpoint();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pt_verificador_version_activate' );

// Remove as regras ao desativar o plugin
function pt_verificador_version_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pt_verificador_version_deactivate' );

// Iniciar verificação de atualizações do GitHub
if ( is_admin() ) {
    new GitHub_Updater( __FILE__ );
}
