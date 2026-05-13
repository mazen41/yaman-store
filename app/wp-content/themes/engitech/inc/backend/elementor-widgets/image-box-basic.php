<?php
namespace Elementor; // Custom widgets must be defined in the Elementor namespace
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly (security measure)

/**
 * Widget Name: Image Box
 */
class Engitech_ImageBoxBasic extends Widget_Base{

 	// The get_name() method is a simple one, you just need to return a widget name that will be used in the code.
	public function get_name() {
		return 'ot-image-box-2';
	}

	// The get_title() method, which again, is a very simple one, you need to return the widget title that will be displayed as the widget label.
	public function get_title() {
		return __( 'OT Image Box Basic', 'engitech' );
	}

	// The get_icon() method, is an optional but recommended method, it lets you set the widget icon. you can use any of the eicon or font-awesome icons, simply return the class name as a string.
	public function get_icon() {
		return 'eicon-image';
	}

	// The get_categories method, lets you set the category of the widget, return the category name as a string.
	public function get_categories() {
		return [ 'category_engitech' ];
	}

	protected function register_controls() {

		//Content Service box
		$this->start_controls_section(
			'content_section',
			[
				'label' => __( 'Image Box', 'engitech' ),
			]
		);
		$this->add_control(
	        'image',
	        [
	            'label' => esc_html__( 'Image', 'engitech' ),
	            'type'  => Controls_Manager::MEDIA,
	            'default' => [
					'url' => Utils::get_placeholder_image_src(),
				]
		    ]
	    );
	    $this->add_group_control(
			Group_Control_Image_Size::get_type(),
			[
				'name' => 'image', // Usage: `{name}_size` and `{name}_custom_dimension`, in this case `image_size` and `image_custom_dimension`.
				'exclude' => ['1536x1536', '2048x2048'],
				'default' => 'full',
			]
		);

		$this->add_control(
			'title',
			[
				'label' => __( 'Title', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'default' => __( 'Total Control', 'engitech' ),
				'label_block' => true,
			]
		);

		$this->add_control(
			'content',
			[
				'label' => __( 'Description', 'engitech' ),
				'type' => Controls_Manager::TEXTAREA,
				'default' => __( 'Real-time notifications and detailed transaction data helps you understand your money better.', 'engitech' ),
			]
		);

		$this->add_control(
			'link',
			[
				'label' => __( 'Link', 'engitech' ),
				'type' => Controls_Manager::URL,
				'placeholder' => __( 'https://your-link.com', 'engitech' ),
				'default'	=> [],
				'separator' => 'before',
			]
		);

		$this->add_control(
			'btn_text',
			[
				'label' => __( 'Button Text', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'default' => __( '<i class="flaticon-right-arrow-1"></i> View More', 'engitech' ),
				'label_block' => 'true',
				'condition' => [
					'link[url]!' => '',
				]
			]
		);

		$this->add_control(
			'header_size',
			[
				'label' => __( 'Title HTML Tag', 'engitech' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'h1' => 'H1',
					'h2' => 'H2',
					'h3' => 'H3',
					'h4' => 'H4',
					'h5' => 'H5',
					'h6' => 'H6',
					'div' => 'div',
					'span' => 'span',
					'p' => 'p',
				],
				'default' => 'h4',
			]
		);

		$this->end_controls_section();

		//Style
		
		$this->start_controls_section(
			'style_icon_section',
			[
				'label' => __( 'Image', 'engitech' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'icon_space',
			[
				'label' => __( 'Spacing', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 200,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .ot-image-box img' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'image_border_radius',
			[
				'label' => __( 'Border Radius', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors' => [
					'{{WRAPPER}} .ot-image-box img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_content_section',
			[
				'label' => __( 'Content', 'engitech' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_responsive_control(
			'box_padding',
			[
				'label' => __( 'Padding Box', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors' => [
					'{{WRAPPER}} .ot-image-box' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_responsive_control(
			'align',
			[
				'label' => __( 'Alignment', 'engitech' ),
				'type' => Controls_Manager::CHOOSE,
				'options' => [
					'left'    => [
						'title' => __( 'Left', 'engitech' ),
						'icon' => 'eicon-text-align-left',
					],
					'center' => [
						'title' => __( 'Center', 'engitech' ),
						'icon' => 'eicon-text-align-center',
					],
					'right' => [
						'title' => __( 'Right', 'engitech' ),
						'icon' => 'eicon-text-align-right',
					]
				],
				'selectors' => [
					'{{WRAPPER}} .ot-image-box' => 'text-align: {{VALUE}};',
				]
			]
		);

		//Title
		$this->add_control(
			'heading_title',
			[
				'label' => __( 'Title', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		
		$this->add_responsive_control(
			'title_space',
			[
				'label' => __( 'Spacing Bottom', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .image-box-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'link_title',
			[
				'label'   => esc_html__( 'Link Title?', 'engitech' ),
				'type' => Controls_Manager::SWITCHER,
				'label_on' => esc_html__( 'Yes', 'engitech' ),
				'label_off' => esc_html__( 'No', 'engitech' ),
				'return_value' => 'yes',
				'default' => '',
				'condition' => [
					'link[url]!' => '',
				]
			]
		);
		$this->add_control(
			'title_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .image-box-title' => 'color: {{VALUE}};',
					'{{WRAPPER}} .image-box-title a' => 'color: {{VALUE}};',
				]
			]
		);

		$this->add_control(
			'title_color_hover',
			[
				'label' => __( 'Hover', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .image-box-title a:hover' => 'color: {{VALUE}};',
				],
				'condition' => [
					'link[url]!' => '',
					'link_title' => 'yes',
				]
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'title_typography',
				'selector' => '{{WRAPPER}} .image-box-title',
			]
		);

		//Description
		$this->add_control(
			'heading_content',
			[
				'label' => __( 'Description', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$this->add_responsive_control(
			'des_space',
			[
				'label' => __( 'Spacing', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .image-box-des' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
				'condition' => [
					'btn_text!' => '',
				]
			]
		);
		$this->add_control(
			'content_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .image-box-des' => 'color: {{VALUE}};',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'content_typography',
				'selector' => '{{WRAPPER}} .image-box-des',
			]
		);

		//Button
		$this->add_control(
			'heading_btn',
			[
				'label' => __( 'Button', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => [
					'btn_text!' => '',
				]
			]
		);
		$this->add_control(
			'btn_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .image-box-btn' => 'color: {{VALUE}};',
				],
				'condition' => [
					'btn_text!' => '',
				]
			]
		);
		$this->add_control(
			'btn_hcolor',
			[
				'label' => __( 'Hover', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .image-box-btn:hover' => 'color: {{VALUE}};',
				],
				'condition' => [
					'btn_text!' => '',
				]
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'btn_typography',
				'selector' => '{{WRAPPER}} .image-box-btn',
				'condition' => [
					'btn_text!' => '',
				]
			]
		);

		$this->end_controls_section();

	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		$image_url = $settings['image']['url'];

		$this->add_render_attribute( 'heading', 'class', 'image-box-title' );
		$title = $settings['title'];
		$title_html = sprintf( '<%1$s %2$s>%3$s</%1$s>', $settings['header_size'], $this->get_render_attribute_string( 'heading' ), $title );

		if ( !empty( $settings['link']['url'] ) ) {
			$this->add_link_attributes( 'imagebox_link', $settings['link'] );
			if( !empty( $settings['link_title'] ) ){
				$title_html = sprintf( '<%1$s %2$s><a ' .$this->get_render_attribute_string( 'imagebox_link' ). '>%3$s</a></%1$s>', $settings['header_size'], $this->get_render_attribute_string( 'heading' ), $title );
			}
		}
		if( !empty( $settings['btn_text'] ) ){
			$this->add_render_attribute( 'btn_text', 'class', 'image-box-btn' );
		}

		$this->add_render_attribute( 'image_wrapper', 'class', 'ot-image-box__image' );
		
		?>
		<div class="ot-image-box basic">
			<?php if ( !empty( $image_url ) ) : ?>
			<div <?php $this->print_render_attribute_string( 'image_wrapper' ); ?>>
				<?php Group_Control_Image_Size::print_attachment_image_html( $settings ); ?>
	        </div>
	        <?php endif; ?>
	        <div class="ot-image-box__content">
	        	<?php if( $settings['title'] ) { echo $title_html; } ?>
				<?php if( $settings['content'] ) { echo '<p class="image-box-des">' .$settings['content']. '</p>'; } ?>
				<?php if( !empty( $settings['btn_text'] ) ) { ?>
	        	<a <?php $this->print_render_attribute_string( 'imagebox_link' ); ?> <?php $this->print_render_attribute_string( 'btn_text' ); ?>><?php $this->print_unescaped_setting( 'btn_text' ); ?></a>
	        	<?php } ?>
			</div>
	    </div>
	    <?php
	}

}
// After the Engitech_ImageBoxBasic class is defined, I must register the new widget class with Elementor:
Plugin::instance()->widgets_manager->register( new Engitech_ImageBoxBasic() );