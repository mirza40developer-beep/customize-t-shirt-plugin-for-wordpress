<?php
/**
 * Plugin Name: Drop X Customizable T-Shirt Designer
 * Description: Elementor-ready WooCommerce T-shirt designer with custom text, artwork, colours, sizes, and print sides.
 * Version: 2.0
 * Author: mirza40developer
 * Text Domain: dropx-tshirt-designer
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

final class DropX_Tshirt_Designer {
	const VERSION = '2.0';
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
	}

	public function boot() {
		load_plugin_textdomain( 'dropx-tshirt-designer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'dropx_tshirt_designer', array( $this, 'shortcode' ) );
		add_action( 'wp_ajax_dropx_add_tshirt_design', array( $this, 'add_design_to_cart' ) );
		add_action( 'wp_ajax_nopriv_dropx_add_tshirt_design', array( $this, 'add_design_to_cart' ) );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_design' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_design_from_session' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_design_to_order' ), 10, 4 );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widget' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'admin_render_order_item_preview' ), 10, 3 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_render_order_previews' ), 10, 4 );
	}

	public function register_assets() {
		$dir = plugin_dir_path( __FILE__ ) . 'assets/';
		$url = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_register_style( 'dropx-tshirt-designer', $url . 'designer.css', array(), file_exists( $dir . 'designer.css' ) ? filemtime( $dir . 'designer.css' ) : self::VERSION );
		wp_register_script( 'dropx-tshirt-designer', $url . 'designer.js', array(), file_exists( $dir . 'designer.js' ) ? filemtime( $dir . 'designer.js' ) : self::VERSION, true );
	}

	private function enqueue_assets() {
		wp_enqueue_style( 'dropx-tshirt-designer' );
		wp_enqueue_script( 'dropx-tshirt-designer' );
		wp_localize_script( 'dropx-tshirt-designer', 'DropXDesigner', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'dropx_tshirt_design' ),
			'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'i18n' => array(
				'uploadError' => __( 'Choose a PNG, JPG, or WebP image up to 5 MB.', 'dropx-tshirt-designer' ),
				'cartError' => __( 'We could not add your design to the cart. Please try again.', 'dropx-tshirt-designer' ),
				'adding' => __( 'Adding design…', 'dropx-tshirt-designer' ),
			),
		) );
	}

	/** Use [dropx_tshirt_designer product_id="123" title="Custom tee"]. */
	public function shortcode( $atts = array() ) {
		return $this->render_designer( shortcode_atts( array( 'product_id' => 0, 'title' => '' ), $atts, 'dropx_tshirt_designer' ) );
	}

	public function get_product_options() {
		$options = array( '0' => __( '— Select a product —', 'dropx-tshirt-designer' ) );
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $options;
		}

		$products = wc_get_products( array( 'status' => 'publish', 'limit' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_name' ) ) {
				continue;
			}
			$options[ (string) $product->get_id() ] = $product->get_name() . ' (#' . $product->get_id() . ')';
		}

		return $options;
	}

	public function render_designer( $settings = array() ) {
		$this->enqueue_assets();
		$product_id = ! empty( $settings['product_id'] ) ? absint( $settings['product_id'] ) : absint( get_option( 'dropx_default_product_id', 0 ) );
		$product = function_exists( 'wc_get_product' ) && $product_id ? wc_get_product( $product_id ) : false;
		$product_available = false;
		if ( $product ) {
			if ( $product->is_type( 'variable' ) ) {
				$product_available = (bool) $this->get_purchasable_variation_id( $product, 'M' );
			} else {
				$product_available = $product->is_purchasable();
			}
		}
		$product_message = '';
		if ( ! $product ) {
			$product_message = __( 'No WooCommerce product has been chosen for this designer. Select a product in the widget or Drop X Designer settings.', 'dropx-tshirt-designer' );
		} elseif ( $product->is_type( 'variable' ) && ! $product_available ) {
			$product_message = __( 'The selected product is variable and cannot be added as-is. Use a simple product or extend the designer for variations.', 'dropx-tshirt-designer' );
		} elseif ( ! $product_available ) {
			if ( ! $product->is_in_stock() ) {
				$product_message = __( 'The selected product is out of stock. Please choose a different product.', 'dropx-tshirt-designer' );
			} elseif ( '' === $product->get_price() || null === $product->get_price() ) {
				$product_message = __( 'The selected product must have a price before it can be ordered.', 'dropx-tshirt-designer' );
			} else {
				$product_message = __( 'The selected product is not currently available. Please choose another product.', 'dropx-tshirt-designer' );
			}
		}
		$default_title = trim( (string) get_option( 'dropx_default_title', '' ) );
		$title = ! empty( $settings['title'] ) ? sanitize_text_field( $settings['title'] ) : ( ! empty( $default_title ) ? sanitize_text_field( $default_title ) : ( $product ? $product->get_name() : __( 'Your custom T-shirt', 'dropx-tshirt-designer' ) ) );
		$designer_id = wp_unique_id( 'dropx-designer-' );

		ob_start();
		?>
		<section id="<?php echo esc_attr( $designer_id ); ?>" class="dropx-designer" data-product-id="<?php echo esc_attr( $product ? $product->get_id() : 0 ); ?>" data-product-available="<?php echo esc_attr( $product_available ? '1' : '0' ); ?>" aria-label="<?php esc_attr_e( 'Custom T-shirt designer', 'dropx-tshirt-designer' ); ?>">
			<div class="dropx-designer__preview-column">
				<div class="dropx-designer__eyebrow"><?php esc_html_e( '', 'dropx-tshirt-designer' ); ?></div>
				<div class="dropx-designer__stage">
					<div class="dropx-designer__shirt" style="--dropx-shirt-colour:#f5f3ef">
						<svg class="dropx-designer__shirt-shape" viewBox="0 0 400 460" aria-hidden="true" focusable="false"><path fill="currentColor" d="M116 34 70 59 13 117l55 76 47-32v260h170V161l47 32 55-76-57-58-46-25c-17 22-39 34-84 34s-67-12-84-34Z"/><path class="dropx-designer__collar" fill="none" stroke="rgba(0,0,0,.22)" stroke-width="6" d="M158 39c6 33 78 33 84 0"/></svg>
						<div class="dropx-designer__print-area" aria-label="<?php esc_attr_e( 'Drag text or artwork inside this printable area', 'dropx-tshirt-designer' ); ?>">
							<div class="dropx-designer__safe-area" aria-hidden="true"></div>
						<div class="dropx-designer__print-item dropx-designer__print-text" data-item="text"></div>
							<img class="dropx-designer__print-item dropx-designer__print-image" data-item="artwork" src="" alt="" draggable="false" hidden />
						</div>
					</div>
					<div class="dropx-designer__side-label" aria-live="polite"><?php esc_html_e( 'Front print', 'dropx-tshirt-designer' ); ?></div>
				</div>
				<div class="dropx-designer__preview-controls">
					<div class="dropx-designer__segmented" role="group" aria-label="<?php esc_attr_e( 'Print side', 'dropx-tshirt-designer' ); ?>">
						<button type="button" class="is-active" data-side="front"><?php esc_html_e( 'Front', 'dropx-tshirt-designer' ); ?></button>
						<button type="button" data-side="back"><?php esc_html_e( 'Back', 'dropx-tshirt-designer' ); ?></button>
					</div>
					<div class="dropx-designer__zoom-controls" aria-hidden="false">
						<button type="button" class="dropx-designer__zoom-btn" data-zoom="out" aria-label="<?php esc_attr_e( 'Zoom out', 'dropx-tshirt-designer' ); ?>">−</button>
						<button type="button" class="dropx-designer__zoom-btn" data-zoom="in" aria-label="<?php esc_attr_e( 'Zoom in', 'dropx-tshirt-designer' ); ?>">+</button>
						<button type="button" class="dropx-designer__zoom-btn" data-zoom="reset" aria-label="<?php esc_attr_e( 'Reset zoom', 'dropx-tshirt-designer' ); ?>">⤢</button>
					</div>
				</div>
				<p class="dropx-designer__hint"><?php esc_html_e( 'Tip: select text or artwork in the preview, then drag it to position your design.', 'dropx-tshirt-designer' ); ?></p>
			</div>
			<div class="dropx-designer__controls-column">
				<header class="dropx-designer__header"><div><div class="dropx-designer__eyebrow"><?php esc_html_e( '', 'dropx-tshirt-designer' ); ?></div><h2><?php echo esc_html( $title ); ?></h2></div><?php if ( $product && $product->get_price_html() ) : ?><div class="dropx-designer__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div><?php endif; ?></header>

				<!-- Print side control moved into the preview column so it sits under the shirt -->

				<div class="dropx-designer__control-group"><span class="dropx-designer__label"><?php esc_html_e( 'Garment colour', 'dropx-tshirt-designer' ); ?></span><div class="dropx-designer__swatches" role="group"><button type="button" class="dropx-designer__swatch is-active" data-colour="#f5f3ef" style="--swatch-colour:#f5f3ef" aria-label="Cream"></button><button type="button" class="dropx-designer__swatch" data-colour="#1d1d1f" style="--swatch-colour:#1d1d1f" aria-label="Black"></button><button type="button" class="dropx-designer__swatch" data-colour="#6f7478" style="--swatch-colour:#6f7478" aria-label="Gray"></button></div></div>

				<div class="dropx-designer__control-group"><span class="dropx-designer__label"><?php esc_html_e( 'Size', 'dropx-tshirt-designer' ); ?></span><div class="dropx-designer__sizes" role="group"><?php foreach ( array( 'XS', 'S', 'M', 'L', 'XL', '2XL' ) as $size ) : ?><button type="button" class="<?php echo 'M' === $size ? 'is-active' : ''; ?>" data-size="<?php echo esc_attr( $size ); ?>"><?php echo esc_html( $size ); ?></button><?php endforeach; ?></div></div>

				<div class="dropx-designer__control-group"><label class="dropx-designer__label" for="<?php echo esc_attr( $designer_id . '-text' ); ?>"><?php esc_html_e( 'Add text', 'dropx-tshirt-designer' ); ?></label><textarea id="<?php echo esc_attr( $designer_id . '-text' ); ?>" class="dropx-designer__text-input" rows="2" maxlength="70" placeholder="<?php esc_attr_e( 'YOUR DESIGN', 'dropx-tshirt-designer' ); ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"></textarea><div class="dropx-designer__two-up"><label><?php esc_html_e( 'Font', 'dropx-tshirt-designer' ); ?><div class="dropx-designer__font-input-group"><select class="dropx-designer__font-select"><option value="sans">Modern Sans</option><option value="serif">Classic Serif</option><option value="display">Bold Display</option><option value="mono">Mono</option><option value="custom">Custom Google Font</option></select><input class="dropx-designer__font-input" list="<?php echo esc_attr( $designer_id . '-google-fonts' ); ?>" type="text" placeholder="<?php esc_attr_e( 'Type or choose a Google Font', 'dropx-tshirt-designer' ); ?>" aria-label="<?php esc_attr_e( 'Google Font family name', 'dropx-tshirt-designer' ); ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" /><datalist id="<?php echo esc_attr( $designer_id . '-google-fonts' ); ?>"><option value="Abril Fatface"><option value="Aclonica"><option value="Acme"><option value="Albert Sans"><option value="Anton"><option value="Archivo"><option value="Arimo"><option value="Arvo"><option value="Asap"><option value="Bebas Neue"><option value="Bitter"><option value="Bricolage Grotesque"><option value="Cabin"><option value="Cairo"><option value="Caveat"><option value="Coda"><option value="Comfortaa"><option value="Cormorant"><option value="Crete Round"><option value="Crimson Text"><option value="DM Sans"><option value="Dancing Script"><option value="Dosis"><option value="EB Garamond"><option value="Encode Sans"><option value="Exo 2"><option value="Fira Sans"><option value="Fredoka"><option value="Gabarito"><option value="Galada"><option value="Georgia"><option value="Gloock"><option value="Great Vibes"><option value="Hanken Grotesk"><option value="IBM Plex Sans"><option value="Inter"><option value="Josefin Sans"><option value="Jost"><option value="Kanit"><option value="Karla"><option value="Lato"><option value="Libre Baskerville"><option value="Libre Franklin"><option value="Lora"><option value="Merriweather"><option value="Montserrat"><option value="Mulish"><option value="Nunito"><option value="Old Standard TT"><option value="Open Sans"><option value="Orbitron"><option value="Oswald"><option value="Outfit"><option value="Playfair Display"><option value="Poppins"><option value="PT Sans"><option value="Quicksand"><option value="Raleway"><option value="Roboto"><option value="Roboto Condensed"><option value="Roboto Mono"><option value="Rokkitt"><option value="Rubik"><option value="Saira"><option value="Sora"><option value="Source Sans 3"><option value="Space Grotesk"><option value="Teko"><option value="Titillium Web"><option value="Ubuntu"><option value="Urbanist"><option value="VT323"><option value="Work Sans"><option value="Young Serif"></datalist></div></label><label><?php esc_html_e( 'Text colour', 'dropx-tshirt-designer' ); ?><input class="dropx-designer__text-colour" type="color" value="#151515" /></label></div></div>

				<div class="dropx-designer__control-group"><span class="dropx-designer__label"><?php esc_html_e( 'Upload artwork', 'dropx-tshirt-designer' ); ?></span><label class="dropx-designer__upload"><input class="dropx-designer__upload-input" type="file" accept="image/png,image/jpeg,image/webp" /><span class="dropx-designer__upload-icon" aria-hidden="true">↑</span><span><strong><?php esc_html_e( 'Choose an image', 'dropx-tshirt-designer' ); ?></strong><small><?php esc_html_e( 'PNG, JPG, or WebP · max 5 MB', 'dropx-tshirt-designer' ); ?></small></span></label><div class="dropx-designer__artwork-row" hidden><span class="dropx-designer__artwork-name"></span><button type="button" class="dropx-designer__remove-artwork"><?php esc_html_e( 'Remove', 'dropx-tshirt-designer' ); ?></button></div></div>

				<div class="dropx-designer__control-group dropx-designer__layout-controls"><div class="dropx-designer__label-row"><span class="dropx-designer__label"><?php esc_html_e( 'Selected design size', 'dropx-tshirt-designer' ); ?></span><output class="dropx-designer__scale-value">100%</output></div><input class="dropx-designer__scale" type="range" min="45" max="170" value="100" /><button type="button" class="dropx-designer__reset-layout"><?php esc_html_e( 'Reset current side', 'dropx-tshirt-designer' ); ?></button></div>

				<footer class="dropx-designer__footer"><?php if ( ! $product_available ) : ?><p class="dropx-designer__notice" aria-live="polite"><?php echo esc_html( $product_message ); ?></p><?php endif; ?><button type="button" class="dropx-designer__add-cart" <?php disabled( ! $product_available ); ?>><?php esc_html_e( 'Add custom T-shirt to cart', 'dropx-tshirt-designer' ); ?><span aria-hidden="true">→</span></button><div class="dropx-designer__status" role="status" aria-live="polite"></div></footer>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	public function add_design_to_cart() {
		check_ajax_referer( 'dropx_tshirt_design', 'nonce' );
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce must be active to add a custom T-shirt to the cart.', 'dropx-tshirt-designer' ) ), 400 );
		}
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart || ! is_object( WC()->cart ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce cart is not available. Please ensure sessions are enabled and refresh the page.', 'dropx-tshirt-designer' ) ), 500 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'The selected product could not be found. Please choose a valid WooCommerce product.', 'dropx-tshirt-designer' ) ), 400 );
		}
		if ( ! $product->is_purchasable() ) {
			if ( $product->is_type( 'variable' ) ) {
				$message = __( 'This product is variable and requires a selected variation. Use a simple product or add variation support.', 'dropx-tshirt-designer' );
			} elseif ( ! $product->is_in_stock() ) {
				$message = __( 'This product is out of stock. Please choose a different product.', 'dropx-tshirt-designer' );
			} elseif ( '' === $product->get_price() || null === $product->get_price() ) {
				$message = __( 'This product must have a price before it can be ordered.', 'dropx-tshirt-designer' );
			} else {
				$message = __( 'This custom T-shirt is not currently available.', 'dropx-tshirt-designer' );
			}
			wp_send_json_error( array( 'message' => $message ), 400 );
		}

		$raw_design = isset( $_POST['design'] ) ? json_decode( wp_unslash( $_POST['design'] ), true ) : null;
		$design = $this->sanitize_design( $raw_design );
		if ( is_wp_error( $design ) ) {
			wp_send_json_error( array( 'message' => $design->get_error_message() ), 400 );
		}

		// Accept optional front/back preview PNG data URLs from the client and save them to uploads
		foreach ( array( 'front' => 'preview_front', 'back' => 'preview_back' ) as $side => $field ) {
			if ( ! empty( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ) {
				$preview = wp_unslash( $_POST[ $field ] );
				if ( preg_match( '#^data:image/(png|jpeg|webp);base64,([a-zA-Z0-9+/=\s]+)$#', $preview, $m ) ) {
					$binary = base64_decode( preg_replace( '/\s+/', '', $m[2] ), true );
					if ( false !== $binary ) {
						$ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
						$filename = 'dropx-preview-' . $side . '-' . wp_generate_uuid4() . '.' . $ext;
						$upload = wp_upload_bits( $filename, null, $binary );
						if ( empty( $upload['error'] ) ) {
							$design[ $side . '_preview_url' ] = esc_url_raw( $upload['url'] );
							if ( function_exists( 'wp_insert_attachment' ) ) {
								require_once ABSPATH . 'wp-admin/includes/image.php';
								require_once ABSPATH . 'wp-admin/includes/file.php';
								require_once ABSPATH . 'wp-admin/includes/media.php';
								$file_path = $upload['file'];
								$filetype = wp_check_filetype( $file_path );
								$attachment = array(
									'post_mime_type' => $filetype['type'] ?: 'image/png',
									'post_title' => sanitize_file_name( $filename ),
									'post_content' => '',
									'post_status' => 'inherit'
								);
								$attach_id = wp_insert_attachment( $attachment, $file_path );
								if ( ! is_wp_error( $attach_id ) ) {
									$meta = wp_generate_attachment_metadata( $attach_id, $file_path );
									if ( is_array( $meta ) ) wp_update_attachment_metadata( $attach_id, $meta );
									$design[ $side . '_preview_attachment_id' ] = (int) $attach_id;
								}
							}
						}
					}
				}
			}
		}

		if ( $product->is_type( 'variable' ) ) {
			$variation_id = $this->get_purchasable_variation_id( $product, $design['size'] );
			if ( ! $variation_id ) {
				wp_send_json_error( array( 'message' => __( 'No purchasable variation could be found for this product. Please choose a different product or update your size variations.', 'dropx-tshirt-designer' ) ), 400 );
			}
			$variation = wc_get_product( $variation_id );
			$variation_data = $this->prepare_variation_data( $variation, $design['size'] );
			if ( empty( $variation_data ) ) {
				wp_send_json_error( array( 'message' => __( 'Could not build the product variation data. Please verify the selected WooCommerce variation is configured correctly.', 'dropx-tshirt-designer' ) ), 500 );
			}
			$key = WC()->cart->add_to_cart( $product->get_id(), 1, $variation_id, $variation_data, array( 'dropx_design' => $design, 'dropx_design_token' => wp_generate_uuid4() ) );
		} else {
			$key = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'dropx_design' => $design, 'dropx_design_token' => wp_generate_uuid4() ) );
		}
		if ( ! $key ) {
			$notice_messages = array();
			if ( function_exists( 'wc_get_notices' ) ) {
				foreach ( wc_get_notices( 'error' ) as $notice ) {
					$notice_messages[] = wp_strip_all_tags( $notice );
				}
			}
			$error_message = $notice_messages ? implode( ' ', $notice_messages ) : __( 'Your design could not be added to the cart. Please verify the selected product is available and try again.', 'dropx-tshirt-designer' );
			wp_send_json_error( array( 'message' => $error_message ), 500 );
		}
		wp_send_json_success( array( 'message' => __( 'Your custom T-shirt is in the cart.', 'dropx-tshirt-designer' ), 'cartUrl' => wc_get_cart_url() ) );
	}

	private function sanitize_design( $raw ) {
		if ( ! is_array( $raw ) ) return new WP_Error( 'dropx_invalid_design', __( 'The design data was not valid. Please refresh and try again.', 'dropx-tshirt-designer' ) );
		$design = array( 'colour' => $this->hex_colour( isset( $raw['colour'] ) ? $raw['colour'] : '' ), 'size' => isset( $raw['size'] ) ? strtoupper( sanitize_text_field( $raw['size'] ) ) : 'M', 'sides' => array() );
		if ( ! in_array( $design['size'], array( 'XS', 'S', 'M', 'L', 'XL', '2XL' ), true ) ) $design['size'] = 'M';
		foreach ( array( 'front', 'back' ) as $side ) {
			$source = isset( $raw['sides'][ $side ] ) && is_array( $raw['sides'][ $side ] ) ? $raw['sides'][ $side ] : array();
			$fontType = isset( $source['fontType'] ) ? sanitize_text_field( $source['fontType'] ) : 'sans';
			$fontName = '';
			if ( 'custom' === $fontType && ! empty( $source['font'] ) ) {
				$fontName = sanitize_text_field( $source['font'] );
			} elseif ( in_array( $fontType, array( 'sans', 'serif', 'display', 'mono' ), true ) ) {
				$fontName = $fontType;
			}
			$text = isset( $source['text'] ) ? sanitize_textarea_field( $source['text'] ) : '';
			if ( '' === trim( $text ) || strcasecmp( trim( $text ), 'YOUR DESIGN' ) === 0 ) {
				$text = '';
			}
			$design['sides'][ $side ] = array(
				'text' => $text,
				'text_colour' => $this->hex_colour( isset( $source['textColour'] ) ? $source['textColour'] : '#151515' ),
				'fontType' => in_array( $fontType, array( 'sans', 'serif', 'display', 'mono', 'custom' ), true ) ? $fontType : 'sans',
				'font' => $this->sanitize_font( $fontName ),
				'text_layout' => $this->sanitize_layout( isset( $source['textLayout'] ) ? $source['textLayout'] : array() ),
				'image_layout' => $this->sanitize_layout( isset( $source['imageLayout'] ) ? $source['imageLayout'] : array() ),
			);
			if ( ! empty( $source['imageData'] ) ) {
				$upload = $this->save_artwork( $source['imageData'], isset( $source['imageName'] ) ? $source['imageName'] : '' );
				if ( is_wp_error( $upload ) ) return $upload;
				$design['sides'][ $side ]['artwork_url'] = $upload['url'];
				$design['sides'][ $side ]['artwork_name'] = $upload['name'];
				if ( isset( $upload['attachment_id'] ) ) {
					$design['sides'][ $side ]['artwork_attachment_id'] = (int) $upload['attachment_id'];
				}
			}
		}
		return $design;
	}

	private function sanitize_layout( $layout ) {
		$layout = is_array( $layout ) ? $layout : array();
		return array( 'x' => min( 100, max( 0, isset( $layout['x'] ) ? (float) $layout['x'] : 50 ) ), 'y' => min( 100, max( 0, isset( $layout['y'] ) ? (float) $layout['y'] : 45 ) ), 'scale' => min( 170, max( 45, isset( $layout['scale'] ) ? (float) $layout['scale'] : 100 ) ) );
	}

	private function sanitize_font( $font ) {
		$font = trim( sanitize_text_field( (string) $font ) );
		if ( in_array( $font, array( 'sans', 'serif', 'display', 'mono', 'custom' ), true ) ) {
			return $font;
		}
		$font = preg_replace( '/\s+/', ' ', $font );
		return preg_match( '/^[\p{L}\p{N} \-\']+$/u', $font ) ? $font : 'sans';
	}

	private function hex_colour( $colour ) {
		$colour = sanitize_text_field( (string) $colour );
		return preg_match( '/^#[a-fA-F0-9]{6}$/', $colour ) ? $colour : '#151515';
	}

	private function save_artwork( $data_uri, $original_name ) {
		if ( ! is_string( $data_uri ) || ! preg_match( '#^data:image/(png|jpeg|webp);base64,([a-zA-Z0-9+/=\\s]+)$#', $data_uri, $matches ) ) return new WP_Error( 'dropx_invalid_artwork', __( 'Artwork must be a PNG, JPG, or WebP image.', 'dropx-tshirt-designer' ) );
		$binary = base64_decode( preg_replace( '/\\s+/', '', $matches[2] ), true );
		if ( false === $binary || strlen( $binary ) > 5 * MB_IN_BYTES ) return new WP_Error( 'dropx_artwork_size', __( 'Artwork must be 5 MB or smaller.', 'dropx-tshirt-designer' ) );
		$extensions = array( 'png' => 'png', 'jpeg' => 'jpg', 'webp' => 'webp' );
		$filename = 'dropx-design-' . wp_generate_uuid4() . '.' . $extensions[ $matches[1] ];
		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) return new WP_Error( 'dropx_artwork_upload', __( 'The artwork could not be saved. Please try again.', 'dropx-tshirt-designer' ) );
		$result = array( 'url' => esc_url_raw( $upload['url'] ), 'name' => sanitize_file_name( $original_name ) ?: $filename );
		// attempt to register in media library and return attachment id
		if ( function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$file_path = $upload['file'];
			$filetype = wp_check_filetype( $file_path );
			$attachment = array(
				'post_mime_type' => $filetype['type'] ?: 'image/png',
				'post_title' => sanitize_file_name( $filename ),
				'post_content' => '',
				'post_status' => 'inherit'
			);
			$attach_id = wp_insert_attachment( $attachment, $file_path );
			if ( ! is_wp_error( $attach_id ) ) {
				$meta = wp_generate_attachment_metadata( $attach_id, $file_path );
				if ( is_array( $meta ) ) wp_update_attachment_metadata( $attach_id, $meta );
				$result['attachment_id'] = (int) $attach_id;
			}
		}
		return $result;
	}

	private function get_purchasable_variation_id( $product, $preferred_size = '' ) {
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return 0;
		}
		$preferred_size = trim( strtolower( (string) $preferred_size ) );
		$variation_ids = $product->get_children();
		$first_purchasable = 0;
		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_purchasable() ) {
				continue;
			}
			if ( ! $first_purchasable ) {
				$first_purchasable = $variation_id;
			}
			if ( $preferred_size && $this->variation_matches_size( $variation, $preferred_size ) ) {
				return $variation_id;
			}
		}
		return $first_purchasable;
	}

	private function variation_matches_size( $variation, $size ) {
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return false;
		}
		$attributes = $variation->get_attributes();
		foreach ( $attributes as $value ) {
			if ( strtolower( sanitize_text_field( (string) $value ) ) === $size ) {
				return true;
			}
		}
		return false;
	}

	private function get_variation_attributes_for_id( $variation_id ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return array();
		}
		if ( method_exists( $variation, 'get_variation_attributes' ) ) {
			return $variation->get_variation_attributes();
		}
		return $variation->get_attributes();
	}

	private function prepare_variation_data( $variation, $size ) {
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return array();
		}
		$attributes = array();
		if ( method_exists( $variation, 'get_variation_attributes' ) ) {
			$attributes = $variation->get_variation_attributes();
		} else {
			$attributes = $variation->get_attributes();
		}
		$cleaned = array();
		foreach ( $attributes as $name => $value ) {
			if ( ! $value ) {
				continue;
			}
			$cleaned[ sanitize_title( $name ) ] = sanitize_text_field( $value );
		}
		return $cleaned;
	}

	public function display_cart_design( $data, $cart_item ) {
		if ( empty( $cart_item['dropx_design'] ) || ! is_array( $cart_item['dropx_design'] ) ) return $data;
		$design = $cart_item['dropx_design'];
		$data[] = array( 'key' => __( 'T-shirt colour', 'dropx-tshirt-designer' ), 'value' => '<span class="dropx-cart-colour" style="background:' . esc_attr( $design['colour'] ) . '"></span> ' . esc_html( strtoupper( $design['colour'] ) ) );
		$data[] = array( 'key' => __( 'Size', 'dropx-tshirt-designer' ), 'value' => esc_html( $design['size'] ) );
		foreach ( array( 'front' => __( 'Front', 'dropx-tshirt-designer' ), 'back' => __( 'Back', 'dropx-tshirt-designer' ) ) as $side => $label ) {
			if ( ! empty( $design[ $side . '_preview_url' ] ) ) {
				$data[] = array( 'key' => sprintf( __( '%s design preview', 'dropx-tshirt-designer' ), $label ), 'value' => '<a href="' . esc_url( $design[ $side . '_preview_url' ] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $design[ $side . '_preview_url' ] ) . '" style="max-width:120px;height:auto;border:1px solid #eee;padding:4px" /></a>' );
			}
		}
		if ( empty( $design['front_preview_url'] ) && empty( $design['back_preview_url'] ) && ! empty( $design['preview_url'] ) ) {
			$data[] = array( 'key' => __( 'Design preview', 'dropx-tshirt-designer' ), 'value' => '<a href="' . esc_url( $design['preview_url'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $design['preview_url'] ) . '" style="max-width:120px;height:auto;border:1px solid #eee;padding:4px" /></a>' );
		}
		foreach ( array( 'front' => __( 'Front', 'dropx-tshirt-designer' ), 'back' => __( 'Back', 'dropx-tshirt-designer' ) ) as $side => $label ) {
			$print = $design['sides'][ $side ];
			if ( ! empty( $print['text'] ) && strcasecmp( trim( $print['text'] ), 'YOUR DESIGN' ) !== 0 ) {
				$data[] = array( 'key' => sprintf( __( '%s text', 'dropx-tshirt-designer' ), $label ), 'value' => esc_html( $print['text'] ) );
			}
			if ( ! empty( $print['artwork_url'] ) ) {
				$data[] = array( 'key' => sprintf( __( '%s artwork', 'dropx-tshirt-designer' ), $label ), 'value' => '<a href="' . esc_url( $print['artwork_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $print['artwork_name'] ?: __( 'Artwork file', 'dropx-tshirt-designer' ) ) . '</a>' );
			}
		}
		return $data;
	}

	public function restore_design_from_session( $session_data, $values ) {
		foreach ( array( 'dropx_design', 'dropx_design_token' ) as $key ) if ( isset( $values[ $key ] ) ) $session_data[ $key ] = $values[ $key ];
		return $session_data;
	}

	public function save_design_to_order( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['dropx_design'] ) || ! is_array( $values['dropx_design'] ) ) return;
		$design = $values['dropx_design'];
		$item->add_meta_data( __( 'T-shirt colour', 'dropx-tshirt-designer' ), strtoupper( $design['colour'] ), true );
		$item->add_meta_data( __( 'Size', 'dropx-tshirt-designer' ), $design['size'], true );
		if ( ! empty( $design['front_preview_url'] ) ) {
			$item->add_meta_data( __( 'Front design preview', 'dropx-tshirt-designer' ), $design['front_preview_url'], true );
		}
		if ( ! empty( $design['back_preview_url'] ) ) {
			$item->add_meta_data( __( 'Back design preview', 'dropx-tshirt-designer' ), $design['back_preview_url'], true );
		}
		// store the attachment ids if available for admin rendering and download
		if ( ! empty( $design['front_preview_attachment_id'] ) ) {
			$item->add_meta_data( '_dropx_preview_attachment_id_front', (int) $design['front_preview_attachment_id'], true );
		}
		if ( ! empty( $design['back_preview_attachment_id'] ) ) {
			$item->add_meta_data( '_dropx_preview_attachment_id_back', (int) $design['back_preview_attachment_id'], true );
		}
		if ( empty( $design['front_preview_url'] ) && empty( $design['back_preview_url'] ) && ! empty( $design['preview_url'] ) ) {
			$item->add_meta_data( __( 'Design preview', 'dropx-tshirt-designer' ), $design['preview_url'], true );
		}
		if ( empty( $design['front_preview_attachment_id'] ) && empty( $design['back_preview_attachment_id'] ) && ! empty( $design['preview_attachment_id'] ) ) {
			$item->add_meta_data( '_dropx_preview_attachment_id', (int) $design['preview_attachment_id'], true );
		}
		foreach ( array( 'front' => __( 'Front', 'dropx-tshirt-designer' ), 'back' => __( 'Back', 'dropx-tshirt-designer' ) ) as $side => $label ) {
			$print = $design['sides'][ $side ];
			if ( ! empty( $print['text'] ) && strcasecmp( trim( $print['text'] ), 'YOUR DESIGN' ) !== 0 ) {
				$item->add_meta_data( sprintf( __( '%s print text', 'dropx-tshirt-designer' ), $label ), $print['text'], true );
			}
			if ( ! empty( $print['artwork_url'] ) ) {
				$item->add_meta_data( sprintf( __( '%s artwork file', 'dropx-tshirt-designer' ), $label ), $print['artwork_url'], true );
			}
			if ( ! empty( $print['artwork_attachment_id'] ) ) {
				$item->add_meta_data( '_dropx_artwork_attachment_id_' . $side, (int) $print['artwork_attachment_id'], true );
			}
		}
		$item->add_meta_data( '_dropx_print_design', wp_json_encode( $design ), true );
	}

	public function register_settings_page() {
		$capability = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
		add_options_page(
			__( 'Drop X Designer', 'dropx-tshirt-designer' ),
			__( 'Drop X Designer', 'dropx-tshirt-designer' ),
			$capability,
			'dropx-designer',
			array( $this, 'settings_page' )
		);

		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page( 'woocommerce', __( 'Drop X Designer', 'dropx-tshirt-designer' ), __( 'Drop X Designer', 'dropx-tshirt-designer' ), $capability, 'dropx-designer', array( $this, 'settings_page' ) );
		}
	}

	public function register_settings() {
		register_setting( 'dropx_designer_settings', 'dropx_default_product_id', array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( 'dropx_designer_settings', 'dropx_default_title', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
	}

	public function admin_render_order_item_preview( $item_id, $item, $product ) {
		if ( ! is_object( $item ) ) return;
		// show preview attachments for front/back if present
		foreach ( array( 'front' => __( 'Front design preview', 'dropx-tshirt-designer' ), 'back' => __( 'Back design preview', 'dropx-tshirt-designer' ) ) as $side => $label ) {
			$attach_id = $item->get_meta( '_dropx_preview_attachment_id_' . $side );
			if ( $attach_id ) {
				$src = wp_get_attachment_url( $attach_id );
				if ( $src ) {
					echo '<div class="dropx-order-preview" style="margin:6px 0">';
					echo '<strong>' . esc_html( $label ) . ':</strong> ';
					echo '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener" style="display:inline-block;margin-right:8px">';
					echo '<img src="' . esc_url( $src ) . '" style="max-width:140px;height:auto;border:1px solid #eee;padding:4px" />';
					echo '</a>';
					echo '<a href="' . esc_url( $src ) . '" download class="button">' . __( 'Download design', 'dropx-tshirt-designer' ) . '</a>';
					echo '</div>';
				}
			}
		}
		// show generic preview attachment if still available
		$attach_id = $item->get_meta( '_dropx_preview_attachment_id' );
		if ( $attach_id ) {
			$src = wp_get_attachment_url( $attach_id );
			if ( $src ) {
				echo '<div class="dropx-order-preview" style="margin:6px 0">';
				echo '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener" style="display:inline-block;margin-right:8px">';
				echo '<img src="' . esc_url( $src ) . '" style="max-width:140px;height:auto;border:1px solid #eee;padding:4px" />';
				echo '</a>';
				echo '<a href="' . esc_url( $src ) . '" download class="button">' . __( 'Download design', 'dropx-tshirt-designer' ) . '</a>';
				echo '</div>';
			}
		}
		// show any artwork attachments for front/back
		foreach ( array( 'front', 'back' ) as $side ) {
			$key = '_dropx_artwork_attachment_id_' . $side;
			$aid = $item->get_meta( $key );
			if ( $aid ) {
				$src = wp_get_attachment_url( $aid );
				if ( $src ) {
					echo '<div class="dropx-order-artwork" style="margin:6px 0">';
					echo '<strong>' . esc_html( ucfirst( $side ) ) . ' artwork:</strong> ';
					echo '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener">View</a> &nbsp;';
					echo '<a href="' . esc_url( $src ) . '" download class="button">' . __( 'Download', 'dropx-tshirt-designer' ) . '</a>';
					echo '</div>';
				}
			}
		}
	}

	public function email_render_order_previews( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! is_object( $order ) ) return;
		$items = $order->get_items();
		foreach ( $items as $item ) {
			foreach ( array( 'front' => __( 'Front design preview', 'dropx-tshirt-designer' ), 'back' => __( 'Back design preview', 'dropx-tshirt-designer' ) ) as $side => $label ) {
				$attach_id = $item->get_meta( '_dropx_preview_attachment_id_' . $side );
				if ( $attach_id ) {
					$src = wp_get_attachment_url( $attach_id );
					if ( $src ) {
						if ( $plain_text ) {
							echo esc_html( $label ) . ": " . $src . "\n";
						} else {
							echo '<p><strong>' . esc_html( $label ) . ':</strong><br/><a href="' . esc_url( $src ) . '"><img src="' . esc_url( $src ) . '" style="max-width:320px;height:auto;border:1px solid #eee;padding:4px" /></a></p>';
						}
					}
				}
			}
			// artwork attachments
			foreach ( array( 'front', 'back' ) as $side ) {
				$aid = $item->get_meta( '_dropx_artwork_attachment_id_' . $side );
				if ( $aid ) {
					$src = wp_get_attachment_url( $aid );
					if ( $src ) {
						if ( $plain_text ) {
							echo ucfirst( $side ) . " artwork: " . $src . "\n";
						} else {
							echo '<p><strong>' . esc_html( ucfirst( $side ) ) . ' artwork:</strong> <a href="' . esc_url( $src ) . '">Download</a></p>';
						}
					}
				}
			}
		}
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$selected = absint( get_option( 'dropx_default_product_id', 0 ) );
		$default_title = get_option( 'dropx_default_title', '' );
		$options = $this->get_product_options();
		?><div class="wrap"><h1><?php esc_html_e( 'Drop X T-Shirt Designer', 'dropx-tshirt-designer' ); ?></h1><p><?php esc_html_e( 'Set the default product and title used when a shortcode or Elementor widget does not specify one.', 'dropx-tshirt-designer' ); ?></p><form method="post" action="options.php"><?php settings_fields( 'dropx_designer_settings' ); ?><table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="dropx_default_product_id"><?php esc_html_e( 'Default T-shirt product', 'dropx-tshirt-designer' ); ?></label></th><td><select id="dropx_default_product_id" name="dropx_default_product_id"><?php foreach ( $options as $product_id => $label ) : ?><option value="<?php echo esc_attr( $product_id ); ?>" <?php selected( (string) $selected, (string) $product_id ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><?php if ( ! class_exists( 'WooCommerce' ) ) : ?><p class="description"><?php esc_html_e( 'Install and activate WooCommerce to see your products in this dropdown.', 'dropx-tshirt-designer' ); ?></p><?php endif; ?></td></tr><tr><th scope="row"><label for="dropx_default_title"><?php esc_html_e( 'Default designer title', 'dropx-tshirt-designer' ); ?></label></th><td><input type="text" id="dropx_default_title" name="dropx_default_title" value="<?php echo esc_attr( $default_title ); ?>" class="regular-text" /><p class="description"><?php esc_html_e( 'This title appears when no custom title is supplied in the widget or shortcode.', 'dropx-tshirt-designer' ); ?></p></td></tr></tbody></table><?php submit_button(); ?></form><hr><h2><?php esc_html_e( 'Use in Elementor', 'dropx-tshirt-designer' ); ?></h2><p><?php esc_html_e( 'Drag “Drop X T-Shirt Designer” onto a page and select the product in its settings. The shortcode option is:', 'dropx-tshirt-designer' ); ?></p><code>[dropx_tshirt_designer product_id="123"]</code></div><?php
	}

	public function register_elementor_widget( $widgets_manager ) {
		if ( ! class_exists( 'Elementor\\Widget_Base' ) ) return;
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-dropx-elementor-widget.php';
		$widgets_manager->register( new DropX_Elementor_Tshirt_Widget() );
	}
}

DropX_Tshirt_Designer::instance();