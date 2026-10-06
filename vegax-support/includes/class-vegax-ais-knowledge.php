<?php
/**
 * Builds the catalogue + policy payload that Vegax uses to answer questions.
 *
 * Products come from the WooCommerce CRUD API (HPOS safe), policies from the
 * store's own pages. Only store data is sent - never customer data.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue/policy payload builder.
 */
class Vegax_AIS_Knowledge {

	/**
	 * Page slugs we try to pick up as policies.
	 *
	 * @return array
	 */
	public static function policy_slugs() {
		return array(
			'shipping'         => 'Shipping',
			'shipping-returns' => 'Shipping and returns',
			'returns'          => 'Returns',
			'refund_returns'   => 'Refunds and returns',
			'refund-policy'    => 'Refund policy',
			'warranty'         => 'Warranty',
			'faq'              => 'FAQ',
			'terms'            => 'Terms',
			'privacy'          => 'Privacy',
		);
	}

	/**
	 * Number of published products.
	 *
	 * @return int
	 */
	public static function product_count() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		$counts = wp_count_posts( 'product' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Full payload.
	 *
	 * @return array
	 */
	public static function build() {
		$settings = Vegax_AIS_Settings::all();
		$limit    = max( 1, min( 2000, (int) $settings['max_products'] ) );
		$products = array();

		if ( function_exists( 'wc_get_products' ) ) {
			$items = wc_get_products(
				array(
					'limit'   => $limit,
					'status'  => 'publish',
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);
			foreach ( $items as $product ) {
				$products[] = self::map_product( $product );
			}
		}

		return array(
			'store'                => Vegax_AIS_Settings::store_id(),
			'ts'                   => time(),
			'plugin_version'       => VEGAX_AIS_VERSION,
			'platform'             => 'woocommerce',
			'product_total'        => self::product_count(),
			'product_total_source' => 'WooCommerce catalogue (plugin sync)',
			'products'             => $products,
			'policies'             => self::policies(),
			'faqs'                 => self::faqs(),
			'custom_rules'         => self::custom_rules(),
			'site'                 => array(
				'name'       => get_bloginfo( 'name' ),
				'url'        => home_url(),
				'currency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'locale'     => get_locale(),
				'timezone'   => wp_timezone_string(),
				'wc_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'wp_version' => get_bloginfo( 'version' ),
				'hpos'       => Vegax_AIS_Orders::hpos_enabled(),
			),
		);
	}

	/**
	 * Map one WC_Product to the flat shape Vegax expects.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	/**
	 * Product attributes as {name, options} - the specs a shopper actually asks about
	 * ("what is the wattage?", "what size is it?").
	 *
	 * Taxonomy attributes (pa_*) MUST be resolved to their TERM NAMES: get_options() returns term IDs
	 * for those, which would hand the assistant a meaningless number.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	protected static function map_attributes( $product ) {
		$out = array();
		$all = $product->get_attributes();
		if ( ! is_array( $all ) ) {
			return $out;
		}
		foreach ( $all as $attr ) {
			if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_name' ) ) {
				continue;
			}
			$name = (string) $attr->get_name();
			if ( '' === $name ) {
				continue;
			}
			if ( $attr->is_taxonomy() && function_exists( 'wc_get_product_terms' ) ) {
				$terms = wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) );
				$opts  = is_array( $terms ) ? $terms : array();
				if ( function_exists( 'wc_attribute_label' ) ) {
					$label = wc_attribute_label( $name );
					if ( $label ) {
						$name = $label;
					}
				}
			} else {
				$opts = (array) $attr->get_options();
			}
			$clean = array();
			foreach ( $opts as $one ) {
				$one = trim( (string) $one );
				if ( '' !== $one ) {
					$clean[] = $one;
				}
			}
			if ( $clean ) {
				$out[] = array( 'name' => $name, 'options' => $clean );
			}
		}
		return $out;
	}

	protected static function map_product( $product ) {
		$cats  = array();
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$cats[] = $term->name;
			}
		}
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

		$desc = wp_strip_all_tags( (string) $product->get_short_description() );
		if ( '' === trim( $desc ) ) {
			$desc = wp_strip_all_tags( (string) $product->get_description() );
		}
		$desc = function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, 600 ) : substr( $desc, 0, 600 );

		$stock_qty = $product->get_stock_quantity();

		// 01/10/2026 (Julian): a variable product told the assistant NOTHING about its variations, so
		// "do you have it in medium?" had no data behind it. Send each variation's choices with its own
		// price and stock. Capped at 12 and the real total is sent alongside, so the assistant can see
		// the list was truncated instead of quietly assuming it is complete.
		$variations     = array();
		$variation_total = 0;
		if ( $product->is_type( 'variable' ) ) {
			$children        = $product->get_children();
			$variation_total = count( $children );
			foreach ( array_slice( $children, 0, 12 ) as $child_id ) {
				$v = function_exists( 'wc_get_product' ) ? wc_get_product( $child_id ) : null;
				if ( ! $v ) {
					continue;
				}
				$choices = array();
				foreach ( (array) $v->get_variation_attributes() as $attr_key => $attr_val ) {
					$label     = str_replace( array( 'attribute_', 'pa_' ), '', (string) $attr_key );
					$choices[] = $label . ': ' . ( '' === (string) $attr_val ? 'any' : (string) $attr_val );
				}
				$variations[] = array(
					'label'        => implode( ' / ', $choices ),
					'price'        => $v->get_price(),
					'sale_price'   => $v->get_sale_price(),
					'stock_status' => $v->get_stock_status(),
					'in_stock'     => $v->is_in_stock() ? 1 : 0,
				);
			}
		}

		$data = array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'sku'               => $product->get_sku(),
			'type'              => $product->get_type(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'on_sale'           => $product->is_on_sale() ? 1 : 0,
			'stock_status'      => $product->get_stock_status(),
			'in_stock'          => $product->is_in_stock() ? 1 : 0,
			'stock_qty'         => ( null === $stock_qty ) ? null : (int) $stock_qty,
			'variations'        => $variations,
			'variation_total'   => $variation_total,
			'categories'        => $cats,
			'permalink'         => get_permalink( $product->get_id() ),
			'image'             => $image_url ? $image_url : '',
			'short_description' => $desc,
			// 01/10/2026 - specs: without these the assistant cannot answer a technical question
			'attributes'        => self::map_attributes( $product ),
			'weight'            => (string) $product->get_weight(),
			'dimensions'        => array(
				'length' => (string) $product->get_length(),
				'width'  => (string) $product->get_width(),
				'height' => (string) $product->get_height(),
				'unit'   => (string) get_option( 'woocommerce_dimension_unit', '' ),
			),
		);

		/**
		 * Filter one product before it is sent to the Vegax service.
		 *
		 * Lets a site add, rename or drop fields for a product (for example append a size
		 * chart, or remove a field the merchant considers private) without editing the plugin.
		 *
		 * @param array      $data    Product payload about to be serialised.
		 * @param WC_Product $product The product.
		 */
		return (array) apply_filters( 'vegax_ais_knowledge_product', $data, $product );
	}

	/**
	 * Merchant FAQs — one per line in the settings: "question | answer".
	 * These are the exact answers the assistant should reuse.
	 *
	 * @return array
	 */
	public static function faqs() {
		$s   = Vegax_AIS_Settings::all();
		$raw = (string) ( $s['faqs_text'] ?? '' );
		$out = array();
		foreach ( preg_split( "/\r\n|\r|\n/", $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $q, $a ) = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out[] = array( 'q' => mb_substr( $q, 0, 300 ), 'a' => mb_substr( $a, 0, 1200 ) );
		}
		return array_slice( $out, 0, 60 );
	}

	/**
	 * Merchant store rules — one per line. Hard rules the assistant must follow.
	 *
	 * @return array
	 */
	public static function custom_rules() {
		$s   = Vegax_AIS_Settings::all();
		$raw = (string) ( $s['custom_rules_text'] ?? '' );
		$out = array();
		foreach ( preg_split( "/\r\n|\r|\n/", $raw ) as $line ) {
			$line = trim( ltrim( trim( $line ), "-*" ) );
			if ( '' !== $line ) {
				$out[] = mb_substr( $line, 0, 300 );
			}
		}
		return array_slice( $out, 0, 40 );
	}

	/**
	 * Store policies (ship/returns/warranty/...) taken from the site's own pages.
	 *
	 * @return array
	 */
	protected static function policies() {
		$out = array();
		foreach ( self::policy_slugs() as $slug => $title ) {
			$page = get_page_by_path( $slug );
			if ( ! $page || 'publish' !== $page->post_status ) {
				continue;
			}
			$content = wp_strip_all_tags( strip_shortcodes( (string) $page->post_content ) );
			$content = trim( preg_replace( '/\s+/', ' ', $content ) );
			if ( '' === $content ) {
				continue;
			}
			$content = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 2000 ) : substr( $content, 0, 2000 );
			$out[]   = array(
				'title'   => $title,
				'slug'    => $slug,
				'url'     => get_permalink( $page->ID ),
				'content' => $content,
			);
		}
		return $out;
	}
}
