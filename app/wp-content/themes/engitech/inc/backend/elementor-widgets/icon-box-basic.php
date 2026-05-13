<?php
namespace Elementor; // Custom widgets must be defined in the Elementor namespace
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly (security measure)

/**
 * Widget Name: Icon Box
 */
class Engitech_IconBox_Normal extends Widget_Base{

 	// The get_name() method is a simple one, you just need to return a widget name that will be used in the code.
	public function get_name() {
		return 'ot-iconbox-normal';
	}

	// The get_title() method, which again, is a very simple one, you need to return the widget title that will be displayed as the widget label.
	public function get_title() {
		return __( 'OT Icon Box Normal', 'engitech' );
	}

	// The get_icon() method, is an optional but recommended method, it lets you set the widget icon. you can use any of the eicon or font-awesome icons, simply return the class name as a string.
	public function get_icon() {
		return 'eicon-icon-box';
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
				'label' => __( 'Icon Box', 'engitech' ),
			]
		);

		$this->add_control(
			'icon_font',
			[
				'label' => __( 'Icon', 'engitech' ),
				'type' => Controls_Manager::ICONS,
				'label_block' => true,
				'fa4compatibility' => 'icon',
				'default' => [
					'value' => 'fas fa-star',
					'library' => 'fa-solid',
				],
			]
		);
		
		$this->add_control(
			'icon_view',
			[
				'label' => __( 'View Icon', 'engitech' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'default' => __( 'Default', 'engitech' ),
					'stacked' => __( 'Stacked', 'engitech' ),
				],
				'default' => 'default',
				'prefix_class' => 'ot-view-',
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
				'default' => __( 'View More <i class="flaticon-right-arrow-1"></i>', 'engitech' ),
				'label_block' => 'true',
				'condition' => [
					'link[url]!' => '',
				]
			]
		);

		$this->add_control(
			'position',
			[
				'label' => __( 'Icon Position', 'engitech' ),
				'type' => Controls_Manager::CHOOSE,
				'default' => 'top',
				'options' => [
					'left' => [
						'title' => __( 'Left', 'engitech' ),
						'icon' => 'eicon-h-align-left',
					],
					'top' => [
						'title' => __( 'Top', 'engitech' ),
						'icon' => 'eicon-v-align-top',
					],
					'right' => [
						'title' => __( 'Right', 'engitech' ),
						'icon' => 'eicon-h-align-right',
					],
				],
				'render_type' => 'template',
				'prefix_class' => 'ot-position-',
				'toggle' => false,
				'separator' => 'before',
				'conditions' => [
					'relation' => 'or',
					'terms' => [
						[
							'name' => 'icon_font[value]',
							'operator' => '!=',
							'value' => '',
						],
					],
				],
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
				'label' => __( 'Icon', 'engitech' ),
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
					'{{WRAPPER}}.ot-position-right .ot-icon-box__icon' => 'margin-left: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}}.ot-position-left .ot-icon-box__icon' => 'margin-right: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}}.ot-position-top .ot-icon-box__icon' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'icon_size',
			[
				'label' => __( 'Size', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 300,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .ot-icon-box__icon' => 'font-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'icon_padding',
			[
				'label' => __( 'Padding', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'selectors' => [
					'{{WRAPPER}} .ot-icon-box__icon' => 'padding: {{SIZE}}{{UNIT}};',
				],
				'range' => [
					'px' => [
						'min' => 5,
						'max' => 100,
					],
				],
				'condition' => [
					'icon_view' => 'stacked',
				],
			]
		);
		$this->add_control(
			'border_radius',
			[
				'label' => __( 'Border Radius', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors' => [
					'{{WRAPPER}} .ot-icon-box__icon' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'condition' => [
					'icon_view' => 'stacked',
				],
			]
		);
		$this->start_controls_tabs( 'ot_icon_colors' );

		$this->start_controls_tab(
			'ot_icon_colors_normal',
			[
				'label' => __( 'Normal', 'engitech' ),
			]
		);
		$this->add_control(
			'icon_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-icon-box__icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} svg .lineal-fill,
					 {{WRAPPER}} svg .fill-secondary' => 'fill: currentColor;',
				],
			]
		);
		$this->add_control(
			'icon_bgcolor',
			[
				'label' => __( 'Background Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ot-icon-box__icon' => 'background-color: {{VALUE}};',
				],
				'condition'	=> [
					'icon_view' => 'stacked'
				]
			]
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name' => 'icon_shadow',
				'label' => __( 'Box Shadow', 'engitech' ),
				'selector' => '{{WRAPPER}} .ot-icon-box__icon',
				'condition'	=> [
					'icon_view' => 'stacked'
				]
			]
		);
		
		$this->end_controls_tab();

		$this->start_controls_tab(
			'ot_icon_colors_hover',
			[
				'label' => __( 'Hover', 'engitech' ),
			]
		);
		$this->add_control(
			'icon_hcolor',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-icon-box:hover .ot-icon-box__icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .ot-icon-box:hover svg .lineal-fill' => 'fill: currentColor;'
				],
			]
		);

		$this->add_control(
			'icon_bghcolor',
			[
				'label' => __( 'Background Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ot-icon-box:hover .ot-icon-box__icon' => 'background-color: {{VALUE}};',
				],
				'condition'	=> [
					'icon_view' => 'stacked'
				]
			]
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name' => 'icon_hshadow',
				'label' => __( 'Box Shadow', 'engitech' ),
				'selector' => '{{WRAPPER}} .ot-icon-box:hover .ot-icon-box__icon',
				'condition'	=> [
					'icon_view' => 'stacked'
				]
			]
		);
		$this->end_controls_tab();

		$this->end_controls_tabs();

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
					'{{WRAPPER}} .ot-icon-box' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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
					'{{WRAPPER}} .ot-icon-box' => 'text-align: {{VALUE}};',
				]
			]
		);
		$this->add_control(
			'content_vertical_alignment',
			[
				'label' => __( 'Vertical Alignment', 'engitech' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'top' => __( 'Top', 'engitech' ),
					'middle' => __( 'Middle', 'engitech' ),
					'bottom' => __( 'Bottom', 'engitech' ),
				],
				'default' => 'top',
				'prefix_class' => 'ot-vertical-align-',
				'separator' => 'after',
				'condition'	=> [
					'position!' => 'top'
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
			'title_space_top',
			[
				'label' => __( 'Spacing Top', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .icon-box-title' => 'margin-top: {{SIZE}}{{UNIT}};',
				],
				'condition'	=> [
					'position!' => 'top'
				]
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
					'{{WRAPPER}} .icon-box-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
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
					'{{WRAPPER}} .icon-box-title' => 'color: {{VALUE}};',
					'{{WRAPPER}} .icon-box-title a' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .ot-icon-box .icon-box-title a:hover' => 'color: {{VALUE}};',
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
				'selector' => '{{WRAPPER}} .icon-box-title',
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
					'{{WRAPPER}} .icon-box-des' => 'margin-bottom: {{SIZE}}{{UNIT}};',
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
					'{{WRAPPER}} .icon-box-des' => 'color: {{VALUE}};',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'content_typography',
				'selector' => '{{WRAPPER}} .icon-box-des',
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
					'{{WRAPPER}} .icon-box-btn' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .icon-box-btn:hover' => 'color: {{VALUE}};',
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
				'selector' => '{{WRAPPER}} .icon-box-btn',
				'condition' => [
					'btn_text!' => '',
				]
			]
		);

		$this->end_controls_section();

	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		$this->add_render_attribute( 'heading', 'class', 'icon-box-title' );
		$title = $settings['title'];
		$title_html = sprintf( '<%1$s %2$s>%3$s</%1$s>', $settings['header_size'], $this->get_render_attribute_string( 'heading' ), $title );

		if ( !empty( $settings['link']['url'] ) ) {
			$this->add_link_attributes( 'iconbox', $settings['link'] );
			if( !empty( $settings['link_title'] ) ){
				$title_html = sprintf( '<%1$s %2$s><a ' .$this->get_render_attribute_string( 'iconbox' ). '>%3$s</a></%1$s>', $settings['header_size'], $this->get_render_attribute_string( 'heading' ), $title );
			}
		}
		if( !empty( $settings['btn_text'] ) ){
			$this->add_render_attribute( 'btn_text', 'class', 'icon-box-btn' );
		}
		
		$has_icon = false;
		
		$has_icon = ! empty( $settings['icon'] );
		if ( ! $has_icon && ! empty( $settings['icon_font']['value'] ) ) {
			$has_icon = true;
		}

		$this->add_render_attribute( 'icon_wrapper', 'class', 'ot-icon-box__icon' );
		if( $settings['position'] != 'top' ){
			$this->add_render_attribute( 'icon_wrapper', 'class', 'flex-gap' );
		}
		
		?>
		<div class="ot-icon-box box-content">
			<?php if ( $has_icon ) : ?>
			<div <?php $this->print_render_attribute_string( 'icon_wrapper' ); ?>>
				<?php Icons_Manager::render_icon( $settings['icon_font'], [ 'aria-hidden' => 'true' ] );?>
	        </div>
	        <?php endif; ?>
	        <div class="ot-icon-box__content">
	        	<?php if( $settings['title'] ) { echo $title_html; } ?>
				<?php if( $settings['content'] ) { echo '<p class="icon-box-des">' .$settings['content']. '</p>'; } ?>
				<?php if( !empty( $settings['btn_text'] ) ) { ?>
	        	<a <?php $this->print_render_attribute_string( 'iconbox' ); ?> <?php $this->print_render_attribute_string( 'btn_text' ); ?>><?php $this->print_unescaped_setting( 'btn_text' ); ?></a>
	        	<?php } ?>
			</div>	
	    </div>
	    <?php
	}

}
// After the Engitech_IconBox_Normal class is defined, I must register the new widget class with Elementor:
Plugin::instance()->widgets_manager->register( new Engitech_IconBox_Normal() );