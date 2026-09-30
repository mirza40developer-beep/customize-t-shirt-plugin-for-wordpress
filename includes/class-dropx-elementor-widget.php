<?php

defined( 'ABSPATH' ) || exit;

class DropX_Elementor_Tshirt_Widget extends \Elementor\Widget_Base {
	public function get_name() { return 'dropx_tshirt_designer'; }
	public function get_title() { return __( 'Drop X T-Shirt Designer', 'dropx-tshirt-designer' ); }
	public function get_icon() { return 'eicon-product-add-to-cart'; }
	public function get_categories() { return array( 'general' ); }
	public function get_keywords() { return array( 't-shirt', 'shirt', 'designer', 'print', 'custom' ); }

	protected function register_controls() {
		$this->start_controls_section( 'dropx_content', array( 'label' => __( 'T-Shirt Designer', 'dropx-tshirt-designer' ) ) );
		$product_options = array( '0' => __( 'Use default selected in Drop X Designer settings', 'dropx-tshirt-designer' ) );
		if ( method_exists( 'DropX_Tshirt_Designer', 'instance' ) ) {
			$product_options = $product_options + DropX_Tshirt_Designer::instance()->get_product_options();
		}
		$this->add_control( 'product_id', array( 'label' => __( 'Select a WooCommerce T-shirt product', 'dropx-tshirt-designer' ), 'type' => \Elementor\Controls_Manager::SELECT2, 'options' => $product_options, 'default' => '0', 'description' => __( 'Choose the product used for checkout. Leave as default to use the product selected under WooCommerce → Drop X Designer.', 'dropx-tshirt-designer' ) ) );
		$this->add_control( 'title', array( 'label' => __( 'Designer title (optional)', 'dropx-tshirt-designer' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '' ) );
		$this->end_controls_section();
	}

	protected function render() {
		echo DropX_Tshirt_Designer::instance()->render_designer( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}