<?php 
namespace Elementor; // Custom widgets must be defined in the Elementor namespace
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly (security measure)

/**
 * Widget Name: Client Logos
 */
class Engitech_Image_Slider_Sync_Tab extends Widget_Base{

 	// The get_name() method is a simple one, you just need to return a widget name that will be used in the code.
	public function get_name() {
		return 'iimage-slider-sync-tab';
	}

	// The get_title() method, which again, is a very simple one, you need to return the widget title that will be displayed as the widget label.
	public function get_title() {
		return __( 'OT Image Slider Sync Tab', 'engitech' );
	}

	// The get_icon() method, is an optional but recommended method, it lets you set the widget icon. you can use any of the eicon or font-awesome icons, simply return the class name as a string.
	public function get_icon() {
		return 'eicon-slider-push';
	}

	// The get_categories method, lets you set the category of the widget, return the category name as a string.
	public function get_categories() {
		return [ 'category_engitech' ];
	}

	protected function register_controls() {

		$this->start_controls_section(
			'content_section',
			[
				'label' => __( 'Image Slider Sync Tab', 'engitech' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'image_slider',
			[
				'label' => __( 'Image', 'engitech' ),
				'type' => Controls_Manager::MEDIA,
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],
			]
		);
		$repeater->add_control(
			'tab_title',
			[
				'label' => __( 'Tab title', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'default' => __( 'Customer Care', 'engitech' ),
			]
		);
		$repeater->add_control(
			'tab_content',
			[
				'label' => __( 'Tab Content', 'engitech' ),
				'type' => Controls_Manager::TEXTAREA,
				'default' => __( 'Automate frequently asked questions and seamlessly connect people to a human when necessary using Facebook Inbox or other platforms.', 'engitech' ),
				'placeholder' => __( 'Tab Content', 'engitech' ),
			]
		);

		$this->add_control(
		    'ot_slider_with_tabs',
		    [
		        'label'       => '',
		        'type'        => Controls_Manager::REPEATER,
		        'show_label'  => false,
		        'default'     => [],
		        'fields'      => $repeater->get_controls(),
		        'title_field' => '{{{tab_title}}}',
		    ]
		);
		$this->add_group_control(
			Group_Control_Image_Size::get_type(),
			[
				'name' => 'ot_slider_with_tabs_size', // Usage: `{name}_size` and `{name}_custom_dimension`, in this case `thumbnail_size` and `thumbnail_custom_dimension`.
				'exclude' => ['1536x1536', '2048x2048'],
				'include' => [],
				'default' => 'full',
			]
		);

		$this->end_controls_section();

		//Style

		//Slider
		$this->start_controls_section(
			'slider_style_section',
			[
				'label' => __( 'Slider', 'engitech' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'slider_width',
			[
				'label' => __( 'Width', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 100,
						'max' => 1000,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .part-image-slider' => 'width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		// Arrow.
		$this->add_control(
			'heading_arrow',
			[
				'label' => __( 'Arrows', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$this->add_responsive_control(
			'custom_arrow_tspacing',
			[
				'label' => __( 'Spacing Top', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 200,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .custom-nav' => 'margin-top: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_responsive_control(
			'custom_arrow_bspacing',
			[
				'label' => __( 'Spacing Bottom', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 200,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .custom-nav' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'arrow_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .custom-nav > button' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_control(
			'arrow_hcolor',
			[
				'label' => __( 'Color Hover', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .custom-nav > button:hover' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_control(
			'arrow_bg_color',
			[
				'label' => __( 'Background', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .custom-nav > button' => 'background: {{VALUE}};',
				]
			]
		);
		$this->add_control(
			'arrow_bg_hcolor',
			[
				'label' => __( 'Background Hover', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .custom-nav > button:hover' => 'background: {{VALUE}};',
				]
			]
		);

		// Dots.
		$this->add_control(
			'heading_dots',
			[
				'label' => __( 'Dots Mobile', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
            'dots_bgcolor',
            [
                'label' => __( 'Color', 'engitech' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
					'{{WRAPPER}} .ot-custom-dots .owl-dot span' => 'background: {{VALUE}};',
				],
            ]
        );

        $this->add_control(
            'dots_active_bgcolor',
            [
                'label' => __( 'Color Active', 'engitech' ),
                'type' => Controls_Manager::COLOR,
                'selectors' => [
					'{{WRAPPER}} .ot-custom-dots .owl-dot.active span' => 'background: {{VALUE}};',
				],
            ]
        );

		$this->end_controls_section();

		// Tab
		$this->start_controls_section(
			'style_section',
			[
				'label' => __( 'Tabs', 'engitech' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		//Title
		$this->add_control(
			'style_title',
			[
				'label' => __( 'Title', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$this->add_responsive_control(
			'title_padding',
			[
				'label' => __( 'Padding', 'progrisaas' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors' => [
					'{{WRAPPER}} .ot-tabs .tab-link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_responsive_control(
			'title_width',
			[
				'label' => __( 'Min Width', 'progrisaas' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%', 'custom' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 300,
					],
					'%' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .ot-tabs .tab-link' => 'min-width: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_responsive_control(
			'title_space',
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
					'{{WRAPPER}} .ot-tabs .tab-link' => 'margin: 0 calc({{SIZE}}{{UNIT}}/2);',
					'{{WRAPPER}} .ot-tabs .tabs-heading' => 'margin: 0 calc(-{{SIZE}}{{UNIT}}/2);',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'title_typography',
				'selector' => '{{WRAPPER}} .ot-tabs .tab-link',
				'separator' => 'before',
			]
		);
		
		$this->start_controls_tabs( 'tabs_title_style' );

		$this->start_controls_tab(
			'tab_title_normal',
			[
				'label' => __( 'Normal', 'engitech' ),
			]
		);

		$this->add_control(
			'title_bgcolor',
			[
				'label' => __( 'Background', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-tabs .tab-link:not(.current)' => 'background: {{VALUE}};',
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
					'{{WRAPPER}} .ot-tabs .tab-link:not(.current)' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name' => 'title_border',
				'selector' => '{{WRAPPER}} .ot-tabs .tab-link',
			]
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'tab_title_active',
			[
				'label' => __( 'Active/Hover', 'engitech' ),
			]
		);

		$this->add_control(
			'title_bg_active',
			[
				'label' => __( 'Background', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-tabs .tab-link.current, {{WRAPPER}} .ot-tabs .tab-link:hover' => 'background: {{VALUE}};',
				]
			]
		);
		
		$this->add_control(
			'title_color_active',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-tabs .tab-link.current, {{WRAPPER}} .ot-tabs .tab-link:hover' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name' => 'title_border_active',
				'selector' => '{{WRAPPER}} .ot-tabs .tab-link.current, {{WRAPPER}} .ot-tabs .tab-link:hover',
			]
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		//Content
		$this->add_control(
			'style_content',
			[
				'label' => __( 'Content', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$this->add_control(
			'content_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-tabs .tab-content' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'content_typography',
				'selector' => '{{WRAPPER}} .ot-tabs .tab-content',
			]
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name' => 'content_border',
				'selector' => '{{WRAPPER}} .ot-tabs .tab-content',
			]
		);
		$this->add_responsive_control(
			'content_padding',
			[
				'label' => __( 'Padding', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors' => [
					'{{WRAPPER}} .ot-tabs .tab-content' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( empty( $settings['ot_slider_with_tabs'] ) ) {
			return;
		}
		$owl_options = [
			'slides_show_desktop'  => 1,
			'slides_show_tablet'   => 1,
			'slides_show_mobile'   => 1,
			'margin_desktop'   	   => 0,
			'margin_tablet'   	   => 0,
			'margin_mobile'   	   => 0,
			'autoplay'      	   => false,
			'loop'      		   => false,
			'arrows-custom'        => true,
			'arrows'        	   => false,
			'dots'          	   => true
		];

		$this->add_render_attribute(
			'slides', [
				'class'               => 'ot-image-slider-sync-tab',
				'data-slider_options' => wp_json_encode( $owl_options ),
			]
		);

		$slides = [];

		foreach ( $settings['ot_slider_with_tabs'] as $key => $item ) {
			$title = $item['tab_title'];
            $image_url = Group_Control_Image_Size::get_attachment_image_src( $item['image_slider']['id'], 'ot_slider_with_tabs_size', $settings );
            $image_html = '<img src="' . esc_attr( $image_url ) . '" alt="' . esc_attr( $title ) . '">';
            $link_tag = '';
            if ( ! empty( $item['image_link']['url'] ) ) {
				$this->add_render_attribute( 'link' . $key, 'href', $item['image_link']['url'] );

				if ( $item['image_link']['is_external'] ) {
					$this->add_render_attribute( 'link' . $key, 'target', '_blank' );
				}

				if ( $item['image_link']['nofollow'] ) {
					$this->add_render_attribute( 'link' . $key, 'rel', 'nofollow' );
				}
				$link_tag = '<a '.$this->get_render_attribute_string('link' . $key).'>';
			}
            
			$slide_html = $link_tag . '<figure>' . $image_html . '</figure>';

			if( $link_tag ){
				$slide_html .= '</a>';
			}
			$slide_html = '<div>' . $slide_html . '</div>';
			if( $image_url ){
				$slides[] = $slide_html;
			}
		}
		if ( empty( $slides ) ) {
			return;
		}
		?>
		
		<div <?php echo $this->get_render_attribute_string( 'slides' ); ?>>
			<div class="part-image-slider">
			    <?php echo implode( '', $slides ); ?>
		    </div>
		    <div class="part-tab">
		    	<div class="custom-nav">
					<button type="button" class="prev-nav"><i class="flaticon-back"></i></button>
					<button type="button" class="next-nav"><i class="flaticon-right-arrow-1"></i></button>
				</div>
		    	<div class="ot-tabs">
					<?php $random = rand(1,1000); if ( $settings['ot_slider_with_tabs'] ) : ?>
					<ul class="tabs-heading unstyle">
						<?php $i = 1; foreach ( $settings['ot_slider_with_tabs'] as $tabs ) { ?>
						<li class="tab-link" data-tab="tab-<?php echo esc_attr($i.$random); ?>"><?php echo $tabs['tab_title']; ?></li>
						<?php $i++; } ?>
					</ul>
					<?php $j = 1; foreach ( $settings['ot_slider_with_tabs'] as $tabs ) { ?>
					<div id="tab-<?php echo esc_attr($j.$random); ?>" class="tab-content">
						<?php echo $tabs['tab_content']; ?>
					</div>
					<?php $j++; } endif; ?>
			    </div>
		    </div>
	    </div>
		<?php 
		
	}

	public function get_keywords() {
		return [ 'slider', 'carousel' ];
	}

}
// After the Engitech_Image_Slider_Sync_Tab class is defined, I must register the new widget class with Elementor:
Plugin::instance()->widgets_manager->register( new Engitech_Image_Slider_Sync_Tab() );