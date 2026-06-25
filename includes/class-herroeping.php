<?php
/**
 * 072DESIGN Herroepingsfunctie — hoofdklasse.
 *
 * Verantwoordelijk voor:
 *  - Routing van /herroeping/ (pretty + ?herroeping=1 fallback).
 *  - Twee-staps formulier: invullen -> bevestigen -> verwerken.
 *  - Vastleggen van de herroeping incl. tijdstip (CPT herroeping_log).
 *  - Ontvangstbevestiging naar consument (duurzame gegevensdrager) + melding naar eigenaar.
 *  - Footer-auto-injectie + shortcode [herroepingsknop].
 *  - Instellingenpagina onder Instellingen -> Herroepingsfunctie.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class D072_Herroeping {

	const OPTION   = 'd072_herr_options';
	const CPT      = 'herroeping_log';
	const QUERYVAR = 'herroeping';
	const NONCE    = 'd072_herr_nonce';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		add_action( 'wp_footer', array( $this, 'footer_button' ) );
		add_action( 'wp_head', array( $this, 'noindex_form_page' ) );
		add_shortcode( 'herroepingsknop', array( $this, 'shortcode_button' ) );
		add_shortcode( 'herroepingsformulier', array( $this, 'shortcode_formulier' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Config
	 * ------------------------------------------------------------------- */

	public function defaults() {
		$woo_from = get_option( 'woocommerce_email_from_address' );
		$owner    = $woo_from ? $woo_from : get_option( 'admin_email' );
		$woo_name = get_option( 'woocommerce_email_from_name' );

		return array(
			'shopnaam'        => get_bloginfo( 'name' ),
			'eigenaar_email'  => $owner,
			'afzender_naam'   => $woo_name ? $woo_name : get_bloginfo( 'name' ),
			'afzender_email'  => $owner,
			'accent'          => '#1a1a1a',
			'logo_url'        => '',
			'pagina_id'       => 0,
			'footer_injectie' => 1,
			'footer_label'    => 'Overeenkomst herroepen',
			'intro_tekst'     => 'Heb je online een bestelling geplaatst en wil je daar binnen de bedenktijd van afzien? Vul dit formulier in om je aankoop te herroepen. Je ontvangt direct een bevestiging per e-mail.',
		);
	}

	/**
	 * Accentkleur (huisstijl per klant). Valt terug op een neutrale donkere
	 * default als de waarde leeg of ongeldig is.
	 */
	public function accent() {
		$c = sanitize_hex_color( $this->opt( 'accent' ) );
		return $c ? $c : '#1a1a1a';
	}

	/**
	 * Leesbare tekstkleur (zwart of wit) op een gegeven achtergrondkleur,
	 * bepaald via relatieve helderheid. Zo werkt elke accentkleur.
	 */
	public function ink_on( $hex ) {
		$hex = ltrim( sanitize_hex_color( $hex ) ? $hex : '#1a1a1a', '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		// Perceptuele helderheid (0-255).
		$lum = ( 0.299 * $r + 0.587 * $g + 0.114 * $b );
		return $lum > 150 ? '#1a1a1a' : '#ffffff';
	}

	public function logo_html( $max_h = 40 ) {
		$url = esc_url( $this->opt( 'logo_url' ) );
		if ( ! $url ) {
			return '';
		}
		return '<img src="' . $url . '" alt="' . esc_attr( $this->opt( 'shopnaam' ) ) . '" style="max-height:' . (int) $max_h . 'px;width:auto;display:block;">';
	}

	public function options() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	public function opt( $key ) {
		$o = $this->options();
		return isset( $o[ $key ] ) ? $o[ $key ] : '';
	}

	/** Virtuele route — werkt altijd, ook zonder gekozen pagina. */
	public function form_url() {
		return home_url( '/herroeping/' );
	}

	/**
	 * URL waar knop/footer naartoe wijzen. Is er een herroep-pagina gekozen
	 * (met [herroepingsformulier] erop), dan die; anders de virtuele route.
	 */
	public function form_target_url() {
		$pid = (int) $this->opt( 'pagina_id' );
		if ( $pid > 0 && 'publish' === get_post_status( $pid ) ) {
			$url = get_permalink( $pid );
			if ( $url ) {
				return $url;
			}
		}
		return $this->form_url();
	}

	/** Houd de gekozen herroep-pagina uit de zoekresultaten. */
	public function noindex_form_page() {
		$pid = (int) $this->opt( 'pagina_id' );
		if ( $pid && is_page( $pid ) ) {
			echo '<meta name="robots" content="noindex,nofollow">' . "\n";
		}
	}

	/* ---------------------------------------------------------------------
	 * Logging (CPT met tijdstip — wettelijke eis)
	 * ------------------------------------------------------------------- */

	public function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => 'Herroepingen',
					'singular_name' => 'Herroeping',
					'menu_name'     => 'Herroepingen',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-undo',
				'menu_position'   => 56,
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'show_in_rest'    => false,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------- */

	public function register_rewrite() {
		add_rewrite_rule( '^herroeping/?$', 'index.php?' . self::QUERYVAR . '=1', 'top' );

		// Eenmalig flushen wanneer de versie wijzigt (mu-plugin heeft geen activation hook).
		if ( get_option( 'd072_herr_flushed' ) !== D072_HERR_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'd072_herr_flushed', D072_HERR_VERSION );
		}
	}

	public function register_query_var( $vars ) {
		$vars[] = self::QUERYVAR;
		return $vars;
	}

	private function is_request() {
		// Pretty permalink of querystring-fallback (werkt altijd, ook zonder flush).
		return ( get_query_var( self::QUERYVAR ) || isset( $_GET[ self::QUERYVAR ] ) );
	}

	public function maybe_render() {
		if ( ! $this->is_request() ) {
			return;
		}
		// Virtuele route: zelfde flow, maar in een eigen pagina-shell.
		$this->render_page( $this->flow_html( $this->form_url() ) );
		exit;
	}

	/**
	 * Kern van de twee-staps-flow. Bepaalt de stap op basis van de POST en
	 * geeft de bijbehorende inner-HTML terug (formulier / bevestiging / klaar).
	 * Gedeeld door de virtuele route en de [herroepingsformulier]-shortcode;
	 * $action is de URL waar het formulier naartoe post (route of pagina).
	 */
	private function flow_html( $action ) {
		$step = isset( $_POST['d072_step'] ) ? sanitize_text_field( wp_unslash( $_POST['d072_step'] ) ) : '';

		if ( '' === $step ) {
			return $this->view_form( array(), array(), $action );
		}

		// Vervolgstappen vereisen een geldige nonce + lege honeypot.
		if ( $this->is_bot() || ! $this->check_nonce() ) {
			return $this->view_form( array( 'Er ging iets mis. Probeer het opnieuw.' ), $this->collect_input(), $action );
		}

		$in     = $this->collect_input();
		$errors = $this->validate( $in );
		if ( $errors ) {
			return $this->view_form( $errors, $in, $action );
		}

		if ( 'verwerk' === $step ) {
			$tijdstip = current_time( 'mysql' );
			$id       = $this->record( $in, $tijdstip );
			$this->mail_consument( $in, $tijdstip );
			$this->mail_eigenaar( $in, $tijdstip, $id );
			return $this->view_done( $in, $tijdstip );
		}

		// step === 'bevestig'
		return $this->view_confirm( $in, $action );
	}

	/* ---------------------------------------------------------------------
	 * Stap 1 -> 2: invoer valideren, bevestigingsscherm tonen
	 * ------------------------------------------------------------------- */

	private function collect_input() {
		return array(
			'naam'        => isset( $_POST['naam'] ) ? sanitize_text_field( wp_unslash( $_POST['naam'] ) ) : '',
			'email'       => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'bestelnr'    => isset( $_POST['bestelnr'] ) ? sanitize_text_field( wp_unslash( $_POST['bestelnr'] ) ) : '',
			'besteldatum' => isset( $_POST['besteldatum'] ) ? sanitize_text_field( wp_unslash( $_POST['besteldatum'] ) ) : '',
			'reden'       => isset( $_POST['reden'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reden'] ) ) : '',
		);
	}

	private function validate( $in ) {
		$errors = array();
		if ( '' === $in['naam'] ) {
			$errors[] = 'Vul je naam in.';
		}
		if ( '' === $in['email'] || ! is_email( $in['email'] ) ) {
			$errors[] = 'Vul een geldig e-mailadres in.';
		}
		if ( '' === $in['bestelnr'] ) {
			$errors[] = 'Vul je bestelnummer of een omschrijving van de bestelling in.';
		}
		return $errors;
	}

	private function check_nonce() {
		return isset( $_POST[ self::NONCE ] ) && wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), self::NONCE );
	}

	private function is_bot() {
		// Honeypot: dit veld hoort leeg te zijn.
		return ! empty( $_POST['website'] );
	}

	/* ---------------------------------------------------------------------
	 * Vastleggen (CPT met tijdstip)
	 * ------------------------------------------------------------------- */

	private function record( $in, $tijdstip ) {
		$title = sprintf( 'Herroeping %s — %s', $in['bestelnr'] ? $in['bestelnr'] : '(geen nr)', $in['naam'] );

		$content  = "Naam: {$in['naam']}\n";
		$content .= "E-mail: {$in['email']}\n";
		$content .= "Bestelnummer / omschrijving: {$in['bestelnr']}\n";
		$content .= "Besteldatum (opgegeven): {$in['besteldatum']}\n";
		$content .= "Reden: {$in['reden']}\n";
		$content .= "Tijdstip verklaring: {$tijdstip}\n";

		$id = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'private',
				'post_title'   => $title,
				'post_content' => $content,
				'post_date'    => $tijdstip,
			),
			true
		);

		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_d072_naam', $in['naam'] );
			update_post_meta( $id, '_d072_email', $in['email'] );
			update_post_meta( $id, '_d072_bestelnr', $in['bestelnr'] );
			update_post_meta( $id, '_d072_tijdstip', $tijdstip );
		}

		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Mails
	 * ------------------------------------------------------------------- */

	private function mail_headers() {
		$naam  = $this->opt( 'afzender_naam' );
		$email = $this->opt( 'afzender_email' );
		return array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $naam, $email ),
		);
	}

	private function mail_consument( $in, $tijdstip ) {
		$shop = $this->opt( 'shopnaam' );
		$body = $this->email_wrap(
			'Bevestiging van je herroeping',
			'<p style="margin:0 0 14px;">Beste ' . esc_html( $in['naam'] ) . ',</p>'
			. '<p style="margin:0 0 14px;">We hebben je herroeping ontvangen. Hiermee bevestig je dat je de overeenkomst voor de onderstaande bestelling wilt ontbinden binnen de wettelijke bedenktijd.</p>'
			. $this->detail_table( $in, $tijdstip )
			. '<p style="margin:14px 0 0;">Bewaar deze e-mail als bevestiging. We nemen contact met je op over de afhandeling en eventuele terugbetaling.</p>'
			. '<p style="margin:14px 0 0;">Met vriendelijke groet,<br>' . esc_html( $shop ) . '</p>'
		);

		wp_mail(
			$in['email'],
			sprintf( 'Bevestiging van je herroeping — %s', $shop ),
			$body,
			$this->mail_headers()
		);
	}

	private function mail_eigenaar( $in, $tijdstip, $id ) {
		$shop = $this->opt( 'shopnaam' );
		$link = $id && ! is_wp_error( $id ) ? get_edit_post_link( $id, '' ) : '';

		$extra = '';
		$order = $this->find_order( $in );
		if ( $order ) {
			$extra = '<p style="margin:14px 0 0;"><strong>Gekoppelde WooCommerce-bestelling:</strong> #' . esc_html( $order->get_id() ) . ' (' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ')</p>';
		}

		$body = $this->email_wrap(
			'Nieuwe herroeping ontvangen',
			'<p style="margin:0 0 14px;">Er is een herroeping ingediend via de herroepingsfunctie.</p>'
			. $this->detail_table( $in, $tijdstip )
			. $extra
			. ( $link ? '<p style="margin:14px 0 0;"><a href="' . esc_url( $link ) . '" style="color:' . esc_attr( $this->accent() ) . ';">Bekijk in het dashboard</a></p>' : '' )
		);

		wp_mail(
			$this->opt( 'eigenaar_email' ),
			sprintf( 'Nieuwe herroeping: %s', $in['bestelnr'] ? $in['bestelnr'] : $in['naam'] ),
			$body,
			$this->mail_headers()
		);
	}

	private function find_order( $in ) {
		if ( ! function_exists( 'wc_get_order' ) || '' === $in['bestelnr'] ) {
			return null;
		}
		$num   = preg_replace( '/[^0-9]/', '', $in['bestelnr'] );
		$order = $num ? wc_get_order( (int) $num ) : null;
		if ( $order && strtolower( $order->get_billing_email() ) === strtolower( $in['email'] ) ) {
			return $order;
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Views (self-contained kleuren, dark-mode-proof)
	 * ------------------------------------------------------------------- */

	/**
	 * Gedeelde component-CSS (velden, knoppen, tabellen). Accent-afhankelijk.
	 * Gebruikt door zowel de standalone-pagina als de inline-shortcode, zodat
	 * de styling op één plek leeft.
	 */
	private function component_rules( $accent, $ink ) {
		return '
		.d072-card h1,.d072-embed h1{margin:0 0 6px;font-size:24px;color:#1a1a1a;}
		.d072-lede{margin:0 0 22px;color:#52525b;}
		.d072-field{margin:0 0 16px;}
		.d072-field label{display:block;font-weight:600;margin:0 0 6px;color:#1a1a1a;}
		.d072-field input,.d072-field textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #c4c4cc;border-radius:7px;font-size:16px;background:#ffffff;color:#1a1a1a;}
		.d072-field .hint{font-size:13px;color:#71717a;margin:5px 0 0;}
		.d072-req{color:#c0392b;}
		.d072-hp{position:absolute;left:-9999px;}
		.d072-logo{margin:0 0 18px;}
		.d072-btn{display:inline-block;background:' . esc_attr( $accent ) . ';color:' . esc_attr( $ink ) . ';border:none;border-radius:7px;padding:13px 22px;font-size:16px;font-weight:600;cursor:pointer;text-decoration:none;}
		.d072-btn-ghost{display:inline-block;background:#ffffff;color:#1a1a1a;border:1px solid #c4c4cc;border-radius:7px;padding:13px 22px;font-size:16px;font-weight:600;cursor:pointer;text-decoration:none;margin-right:10px;}
		.d072-errors{background:#fdecec;border:1px solid #f3b4b4;color:#7a1616;border-radius:7px;padding:12px 14px;margin:0 0 20px;}
		.d072-errors ul{margin:6px 0 0;padding-left:18px;}
		.d072-summary{width:100%;border-collapse:collapse;margin:0 0 4px;}
		.d072-summary th,.d072-summary td{text-align:left;padding:9px 10px;border-bottom:1px solid #ececef;font-size:15px;vertical-align:top;color:#1a1a1a;}
		.d072-summary th{width:42%;color:#52525b;font-weight:600;}
		.d072-ok{background:#eaf7ee;border:1px solid #b6e0c2;color:#1c6b35;border-radius:7px;padding:14px 16px;margin:0 0 20px;}
		.d072-back{display:inline-block;margin-top:22px;color:#52525b;text-decoration:none;font-size:14px;}';
	}

	private function render_page( $inner ) {
		// Minimal, theme-onafhankelijk: eigen pagina-shell zodat de functie
		// altijd toonbaar is, ongeacht het actieve thema.
		status_header( 200 );
		nocache_headers();
		$shop   = esc_html( $this->opt( 'shopnaam' ) );
		$accent = $this->accent();
		$ink    = $this->ink_on( $accent );
		$logo   = $this->logo_html( 44 );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title>Herroepen — <?php echo $shop; ?></title>
	<style>
		body{margin:0;background:#f4f4f5;color:#1a1a1a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.6;}
		.d072-wrap{max-width:640px;margin:0 auto;padding:40px 20px;}
		.d072-card{background:#ffffff;color:#1a1a1a;border:1px solid #e4e4e7;border-radius:10px;padding:28px 28px 32px;}
		.d072-foot{margin-top:18px;font-size:13px;color:#a1a1aa;text-align:center;}
<?php echo $this->component_rules( $accent, $ink ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</style>
</head>
<body>
	<div class="d072-wrap">
		<div class="d072-card">
			<?php if ( $logo ) : ?><div class="d072-logo"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
			<?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<p class="d072-foot"><?php echo $shop; ?></p>
	</div>
</body>
</html>
		<?php
	}

	private function field( $name, $label, $value, $type = 'text', $required = true, $hint = '' ) {
		$req = $required ? ' <span class="d072-req">*</span>' : '';
		$out = '<div class="d072-field"><label for="d072_' . esc_attr( $name ) . '">' . esc_html( $label ) . $req . '</label>';
		if ( 'textarea' === $type ) {
			$out .= '<textarea id="d072_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="3">' . esc_textarea( $value ) . '</textarea>';
		} else {
			$out .= '<input id="d072_' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . '>';
		}
		if ( $hint ) {
			$out .= '<p class="hint">' . esc_html( $hint ) . '</p>';
		}
		$out .= '</div>';
		return $out;
	}

	private function view_form( $errors = array(), $in = array(), $action = '' ) {
		$action = $action ? $action : $this->form_url();
		$in     = wp_parse_args(
			$in,
			array( 'naam' => '', 'email' => '', 'bestelnr' => '', 'besteldatum' => '', 'reden' => '' )
		);

		$html  = '<h1>Bestelling herroepen</h1>';
		$html .= '<p class="d072-lede">' . esc_html( $this->opt( 'intro_tekst' ) ) . '</p>';

		if ( $errors ) {
			$html .= '<div class="d072-errors"><strong>Controleer het volgende:</strong><ul>';
			foreach ( $errors as $e ) {
				$html .= '<li>' . esc_html( $e ) . '</li>';
			}
			$html .= '</ul></div>';
		}

		$html .= '<form method="post" action="' . esc_url( $action ) . '">';
		$html .= wp_nonce_field( self::NONCE, self::NONCE, true, false );
		$html .= '<input type="hidden" name="d072_step" value="bevestig">';
		$html .= '<label class="d072-hp" aria-hidden="true">Laat dit veld leeg<input type="text" name="website" tabindex="-1" autocomplete="off"></label>';
		$html .= $this->field( 'naam', 'Naam', $in['naam'] );
		$html .= $this->field( 'email', 'E-mailadres', $in['email'], 'email', true, 'Hierop ontvang je de bevestiging.' );
		$html .= $this->field( 'bestelnr', 'Bestelnummer of omschrijving van de bestelling', $in['bestelnr'] );
		$html .= $this->field( 'besteldatum', 'Besteldatum', $in['besteldatum'], 'text', false, 'Optioneel. Bijvoorbeeld 12-06-2026.' );
		$html .= $this->field( 'reden', 'Reden (optioneel)', $in['reden'], 'textarea', false, 'Je hoeft geen reden op te geven.' );
		$html .= '<button type="submit" class="d072-btn">Verder naar bevestigen</button>';
		$html .= '</form>';

		return $html;
	}

	private function detail_table( $in, $tijdstip ) {
		$rows  = '<table class="d072-summary">';
		$rows .= '<tr><th>Naam</th><td>' . esc_html( $in['naam'] ) . '</td></tr>';
		$rows .= '<tr><th>E-mailadres</th><td>' . esc_html( $in['email'] ) . '</td></tr>';
		$rows .= '<tr><th>Bestelling</th><td>' . esc_html( $in['bestelnr'] ) . '</td></tr>';
		if ( '' !== $in['besteldatum'] ) {
			$rows .= '<tr><th>Besteldatum</th><td>' . esc_html( $in['besteldatum'] ) . '</td></tr>';
		}
		if ( '' !== $in['reden'] ) {
			$rows .= '<tr><th>Reden</th><td>' . nl2br( esc_html( $in['reden'] ) ) . '</td></tr>';
		}
		$rows .= '<tr><th>Tijdstip verklaring</th><td>' . esc_html( mysql2date( 'd-m-Y H:i', $tijdstip ) ) . '</td></tr>';
		$rows .= '</table>';
		return $rows;
	}

	private function view_confirm( $in, $action = '' ) {
		$action = $action ? $action : $this->form_url();
		$now    = current_time( 'mysql' );

		$html  = '<h1>Controleer en bevestig</h1>';
		$html .= '<p class="d072-lede">Klopt dit? Bevestig hieronder om je herroeping definitief in te dienen. Je ontvangt direct een bevestiging per e-mail.</p>';
		$html .= $this->detail_table( $in, $now );

		$html .= '<form method="post" action="' . esc_url( $action ) . '" style="margin-top:22px;">';
		$html .= wp_nonce_field( self::NONCE, self::NONCE, true, false );
		$html .= '<input type="hidden" name="d072_step" value="verwerk">';
		foreach ( array( 'naam', 'email', 'bestelnr', 'besteldatum', 'reden' ) as $k ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $in[ $k ] ) . '">';
		}
		$html .= '<input type="hidden" name="website" value="">';
		$html .= '<a href="' . esc_url( $action ) . '" class="d072-btn-ghost">Terug</a>';
		$html .= '<button type="submit" class="d072-btn">Herroeping bevestigen</button>';
		$html .= '</form>';

		return $html;
	}

	private function view_done( $in, $tijdstip ) {
		$html  = '<h1>Je herroeping is ontvangen</h1>';
		$html .= '<div class="d072-ok">We hebben je herroeping geregistreerd op ' . esc_html( mysql2date( 'd-m-Y \o\m H:i', $tijdstip ) ) . '. Er is een bevestiging gestuurd naar ' . esc_html( $in['email'] ) . '.</div>';
		$html .= $this->detail_table( $in, $tijdstip );
		$html .= '<a class="d072-back" href="' . esc_url( home_url( '/' ) ) . '">&larr; Terug naar de website</a>';
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * E-mail wrapper (self-contained, dark-mode-proof)
	 * ------------------------------------------------------------------- */

	private function email_wrap( $titel, $inner ) {
		$accent = $this->accent();
		$ink    = $this->ink_on( $accent );
		$logo   = $this->opt( 'logo_url' );
		$header = $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $this->opt( 'shopnaam' ) ) . '" style="max-height:34px;width:auto;">'
			: esc_html( $titel );

		return '<div style="background:#f4f4f5;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
			. '<div style="max-width:560px;margin:0 auto;background:#ffffff;color:#1a1a1a;border:1px solid #e4e4e7;border-radius:10px;overflow:hidden;">'
			. '<div style="background:' . esc_attr( $accent ) . ';color:' . esc_attr( $ink ) . ';padding:18px 24px;font-size:18px;font-weight:600;">' . $header . '</div>'
			. '<div style="padding:24px;color:#1a1a1a;line-height:1.6;font-size:15px;">' . $inner . '</div>'
			. '</div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Knop: footer-injectie + shortcode
	 * ------------------------------------------------------------------- */

	public function footer_button() {
		$pid = (int) $this->opt( 'pagina_id' );
		// Niet tonen als injectie uit staat, op de route zelf, of op de herroep-pagina.
		if ( ! $this->opt( 'footer_injectie' ) || $this->is_request() || ( $pid && is_page( $pid ) ) ) {
			return;
		}
		$label = esc_html( $this->opt( 'footer_label' ) );
		$url   = esc_url( $this->form_target_url() );
		echo '<div class="d072-herr-footer" style="text-align:center;padding:14px 16px;font-size:13px;">'
			. '<a href="' . $url . '" style="color:inherit;text-decoration:underline;opacity:.85;">' . $label . '</a>'
			. '</div>';
	}

	public function shortcode_button( $atts ) {
		$atts  = shortcode_atts(
			array(
				'label' => $this->opt( 'footer_label' ),
				'stijl' => 'knop', // 'knop' of 'link'
			),
			$atts,
			'herroepingsknop'
		);
		$url = esc_url( $this->form_target_url() );
		if ( 'link' === $atts['stijl'] ) {
			return '<a href="' . $url . '">' . esc_html( $atts['label'] ) . '</a>';
		}
		$accent = $this->accent();
		$ink    = $this->ink_on( $accent );
		return '<a href="' . $url . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:' . esc_attr( $ink ) . ';text-decoration:none;border-radius:7px;padding:11px 18px;font-weight:600;">' . esc_html( $atts['label'] ) . '</a>';
	}

	/**
	 * Rendert het volledige formulier (incl. twee-staps-flow) inline op een
	 * normale WordPress-pagina. Gebruik: maak een pagina aan, plaats hier
	 * [herroepingsformulier], en link er in het menu naartoe.
	 */
	public function shortcode_formulier( $atts ) {
		$action = get_permalink();
		if ( ! $action ) {
			$action = home_url( add_query_arg( array() ) );
		}
		$accent = $this->accent();
		$ink    = $this->ink_on( $accent );
		$logo   = $this->logo_html( 44 );

		$css = '<style>' . $this->component_rules( $accent, $ink ) . ' .d072-embed{max-width:640px;margin:0 auto;}</style>';

		return $css . '<div class="d072-embed">'
			. ( $logo ? '<div class="d072-logo">' . $logo . '</div>' : '' )
			. $this->flow_html( $action )
			. '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Instellingenpagina
	 * ------------------------------------------------------------------- */

	public function settings_page() {
		add_options_page(
			'Herroepingsfunctie',
			'Herroepingsfunctie',
			'manage_options',
			'd072-herroeping',
			array( $this, 'render_settings' )
		);
	}

	public function register_settings() {
		register_setting(
			'd072_herr_group',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
		);
	}

	public function sanitize_settings( $input ) {
		$d = $this->defaults();
		return array(
			'shopnaam'        => isset( $input['shopnaam'] ) ? sanitize_text_field( $input['shopnaam'] ) : $d['shopnaam'],
			'eigenaar_email'  => isset( $input['eigenaar_email'] ) && is_email( $input['eigenaar_email'] ) ? sanitize_email( $input['eigenaar_email'] ) : $d['eigenaar_email'],
			'afzender_naam'   => isset( $input['afzender_naam'] ) ? sanitize_text_field( $input['afzender_naam'] ) : $d['afzender_naam'],
			'afzender_email'  => isset( $input['afzender_email'] ) && is_email( $input['afzender_email'] ) ? sanitize_email( $input['afzender_email'] ) : $d['afzender_email'],
			'accent'          => isset( $input['accent'] ) && sanitize_hex_color( $input['accent'] ) ? sanitize_hex_color( $input['accent'] ) : $d['accent'],
			'logo_url'        => isset( $input['logo_url'] ) ? esc_url_raw( trim( $input['logo_url'] ) ) : $d['logo_url'],
			'pagina_id'       => isset( $input['pagina_id'] ) ? (int) $input['pagina_id'] : 0,
			'footer_injectie' => empty( $input['footer_injectie'] ) ? 0 : 1,
			'footer_label'    => isset( $input['footer_label'] ) ? sanitize_text_field( $input['footer_label'] ) : $d['footer_label'],
			'intro_tekst'     => isset( $input['intro_tekst'] ) ? sanitize_textarea_field( $input['intro_tekst'] ) : $d['intro_tekst'],
		);
	}

	public function render_settings() {
		$o = $this->options();
		?>
		<div class="wrap">
			<h1>Herroepingsfunctie</h1>
			<p>Wettelijk verplichte digitale herroepingsfunctie (per 19-06-2026).</p>
			<p><strong>Aanbevolen werkwijze:</strong> maak een pagina aan (bijv. "Herroepen"), zet daar de shortcode <code>[herroepingsformulier]</code> op, kies die pagina hieronder, en link er in het menu naartoe. Als je geen pagina kiest, werkt het formulier automatisch op de losse route
				<a href="<?php echo esc_url( $this->form_url() ); ?>" target="_blank"><?php echo esc_html( $this->form_url() ); ?></a> als vangnet.
				De knop kun je los plaatsen met <code>[herroepingsknop]</code>.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'd072_herr_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="shopnaam">Shopnaam</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[shopnaam]" id="shopnaam" type="text" class="regular-text" value="<?php echo esc_attr( $o['shopnaam'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eigenaar_email">Meldingen naar (eigenaar)</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[eigenaar_email]" id="eigenaar_email" type="email" class="regular-text" value="<?php echo esc_attr( $o['eigenaar_email'] ); ?>">
						<p class="description">Hier komt de melding van een nieuwe herroeping binnen.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="afzender_naam">Afzender-naam</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[afzender_naam]" id="afzender_naam" type="text" class="regular-text" value="<?php echo esc_attr( $o['afzender_naam'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="afzender_email">Afzender-e-mailadres</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[afzender_email]" id="afzender_email" type="email" class="regular-text" value="<?php echo esc_attr( $o['afzender_email'] ); ?>">
						<p class="description">Gebruik een adres op het eigen sitedomein (i.v.m. DMARC), niet een ander mail-domein met p=reject.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="accent">Accentkleur (huisstijl)</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[accent]" id="accent" type="color" value="<?php echo esc_attr( $this->accent() ); ?>" style="width:60px;height:36px;vertical-align:middle;padding:2px;">
						<code style="margin-left:8px;"><?php echo esc_html( $this->accent() ); ?></code>
						<p class="description">Kleur van de knoppen en de mailheader. Zet deze op de merkkleur van de klant. De tekstkleur op de knop wordt automatisch zwart of wit voor leesbaarheid.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="logo_url">Logo (optioneel)</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[logo_url]" id="logo_url" type="url" class="regular-text" value="<?php echo esc_attr( $o['logo_url'] ); ?>" placeholder="https://...">
						<p class="description">URL naar het logo van de klant. Verschijnt bovenaan het formulier en in de bevestigingsmail. Leeg laten = geen logo.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pagina_id">Herroep-pagina</label></th>
						<td><?php
							wp_dropdown_pages(
								array(
									'name'              => esc_attr( self::OPTION ) . '[pagina_id]',
									'id'                => 'pagina_id',
									'selected'          => (int) $o['pagina_id'],
									'show_option_none'  => '— Geen (gebruik de losse route) —',
									'option_none_value' => 0,
								)
							);
							?>
						<p class="description">De pagina met <code>[herroepingsformulier]</code>. Knop en footer-link wijzen hier dan automatisch naartoe. Deze pagina wordt op <code>noindex</code> gezet.</p></td>
					</tr>
					<tr>
						<th scope="row">Footer-knop</th>
						<td><label><input name="<?php echo esc_attr( self::OPTION ); ?>[footer_injectie]" type="checkbox" value="1" <?php checked( $o['footer_injectie'], 1 ); ?>> Toon automatisch een herroep-link in de footer (vangnet als je geen menu-item plaatst).</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="footer_label">Knop-/link-tekst</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[footer_label]" id="footer_label" type="text" class="regular-text" value="<?php echo esc_attr( $o['footer_label'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="intro_tekst">Introtekst op het formulier</label></th>
						<td><textarea name="<?php echo esc_attr( self::OPTION ); ?>[intro_tekst]" id="intro_tekst" rows="3" class="large-text"><?php echo esc_textarea( $o['intro_tekst'] ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
