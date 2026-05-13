<?php
namespace Elementor; // Custom widgets must be defined in the Elementor namespace
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly (security measure)

/**
 * Widget Name: Image Box Carousel
 */
class Engitech_ImageBox_Carousel extends Widget_Base{

 	// The get_name() method is a simple one, you just need to return a widget name that will be used in the code.
	public function get_name() {
		return 'ot-imagebox-carousel';
	}

	// The get_title() method, which again, is a very simple one, you need to return the widget title that will be displayed as the widget label.
	public function get_title() {
		return __( 'Engitech Testimonial Slider 7', 'engitech' );
	}

	// The get_icon() method, is an optional but recommended method, it lets you set the widget icon. you can use any of the eicon or font-awesome icons, simply return the class name as a string.
	public function get_icon() {
		return 'eicon-slider-album';
	}

	// The get_categories method, lets you set the category of the widget, return the category name as a string.
	public function get_categories() {
		return [ 'category_engitech' ];
	}

	protected function register_controls() {

		//Content Image box carousel
		$this->start_controls_section(
			'content_section',
			[
				'label' => __( 'Image Box Carousel', 'engitech' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'image_box',
			[
				'label' => __( 'Choose Image', 'engitech' ),
				'type' => Controls_Manager::MEDIA,
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],
			]
		);

		$repeater->add_control(
			'title',
			[
				'label' => __( 'Title/Name', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'default' => __( 'Peter Smith', 'engitech' ),
				'label_block' => true,
			]
		);

		$repeater->add_control(
			'desc',
			[
				'label' => 'Description',
				'type' => Controls_Manager::TEXTAREA,
			]
		);
		$repeater->add_control(
			'vlink',
			[
				'label' => __( 'Link Video Popup', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'placeholder' => __( 'https://your-link.com', 'engitech' ),
			]
		);
		$repeater->add_control(
			'link',
			[
				'label' => __( 'Link to Detail', 'engitech' ),
				'type' => Controls_Manager::URL,
				'placeholder' => __( 'https://your-link.com', 'engitech' ),
			]
		);

		$this->add_control(
		    'imagebox_carousel',
		    [
		        'label'       => '',
		        'type'        => Controls_Manager::REPEATER,
		        'show_label'  => false,
		        'default'     => [],
		        'fields'      => $repeater->get_controls(),
		        'title_field' => '{{{title}}}',
		        'prevent_empty' => false
		    ]
		);

		$this->add_control(
			'is_rate',
			[
				'label'   => esc_html__( 'Rate', 'engitech' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			]
		);

		$this->add_control(
			'image_rate',
			[
				'label' => __( 'Image Rate', 'engitech' ),
				'type' => Controls_Manager::MEDIA,
				'condition' => [
					'is_rate'	=> 'yes'
				]
			]
		);

		$this->add_control(
			'add_settings',
			[
				'label' => __( 'Additional settings', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$slides_show = range( 1, 10 );
		$slides_show = array_combine( $slides_show, $slides_show );

		$this->add_responsive_control(
			'tshow',
			[
				'label' => __( 'Slides To Show', 'engitech' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'' => __( 'Default', 'engitech' ),
				] + $slides_show,
				'default' => '',
			]
		);

		$this->add_control(
			'visible_outside',
			[
				'label'   => esc_html__( 'Visible Item Outside', 'engitech' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
				'selectors_dictionary' => [
				    'yes' => 'overflow: visible',
				],
				'selectors' => [
				    '{{WRAPPER}} .ot-testimonial-slider .slick-list' => '{{VALUE}}',
				]
			]
		);
		$this->add_control(
			'loop',
			[
				'label'   => esc_html__( 'Loop', 'engitech' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);
		$this->add_control(
			'autoplay',
			[
				'label'   => esc_html__( 'Autoplay', 'engitech' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);
		$this->add_control(
			'timeout',
			[
				'label' => __( 'Autoplay Timeout', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min'  => 1000,
						'max'  => 20000,
						'step' => 1000,
					],
				],
				'default' => [
					'size' => 7000,
				],
				'condition' => [
					'autoplay' => 'yes',
				]
			]
		);

		$this->add_control(
			'navigation',
			[
				'label' => __( 'Navigation', 'engitech' ),
				'type' => Controls_Manager::SELECT,
				'default' => 'none',
				'options' => [
					'both' => __( 'Arrows and Dots', 'engitech' ),
					'arrows' => __( 'Arrows', 'engitech' ),
					'dots' => __( 'Dots', 'engitech' ),
					'none' => __( 'None', 'engitech' ),
				],
			]
		);

		$this->end_controls_section();

		//Style
		
		$this->start_controls_section(
			'style_image_section',
			[
				'label' => __( 'Image', 'engitech' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'image_space',
			[
				'label' => __( 'Spacing', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 200,
					],
				],
				'default' => [
					'size' => 15,
				],
				'selectors' => [
					'{{WRAPPER}} figure.ot-testimonial-s7__item__img' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'image_size',
			[
				'label' => __( 'Size', 'engitech' ) . ' (%)',
				'type' => Controls_Manager::SLIDER,
				'default' => [
					'unit' => '%',
				],
				'tablet_default' => [
					'unit' => '%',
				],
				'mobile_default' => [
					'unit' => '%',
				],
				'size_units' => [ '%' ],
				'range' => [
					'%' => [
						'min' => 5,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s7__item__img' => 'width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'btn_video_color',
			[
				'label' => __( 'Button Video Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s7__item__video .btn-play' => 'color: {{VALUE}};',
				]
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name' => 'image_box_border',
				'selector' => '{{WRAPPER}} .ot-testimonial-s7__item__img img',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'image_box_radius',
			[
				'label' => __( 'Border Radius', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em' ],
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s7__item__img img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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
					'{{WRAPPER}} .ot-testimonial-s7__item' => 'text-align: {{VALUE}};',
				],
				'default' => 'left',
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
				'label' => __( 'Spacing', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-client' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'title_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-client' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .ot-testimonial-client a:hover' => 'color: {{VALUE}};',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'title_typography',
				'selector' => '{{WRAPPER}} .ot-testimonial-client',
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
		
		$this->add_control(
			'content_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-extra' => 'color: {{VALUE}};',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'content_typography',
				'selector' => '{{WRAPPER}} .ot-testimonial-extra',
			]
		);

		$this->end_controls_section();

		// Dots.
		$this->start_controls_section(
			'navigation_section',
			[
				'label' => __( 'Dots', 'engitech' ),
				'tab' => Controls_Manager::TAB_STYLE,
				'condition' => [
					'navigation' => [ 'dots', 'both' ],
				],
			]
		);

		$this->add_responsive_control(
			'dots_align',
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
					],
				],
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .slick-dots' => 'text-align: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'dots_spacing',
			[
				'label' => __( 'Spacing', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'range' => [
					'px' => [
						'min' => -200,
						'max' => 200,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .slick-dots' => 'bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'dots_bgcolor',
			[
				'label' => __( 'Background', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .slick-dots button:before' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_control(
			'dots_active_bgcolor',
			[
				'label' => __( 'Background Active', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .slick-active button:before' => 'color: {{VALUE}};',
				]
			]
		);

        $this->end_controls_section();

        // Arrow.
		$this->start_controls_section(
			'style_nav',
			[
				'label' => __( 'Arrows', 'engitech' ),
				'tab' => Controls_Manager::TAB_STYLE,
				'condition' => [
					'navigation' => [ 'arrows', 'both' ],
				]
			]
		);
		$this->add_control(
			'spacing_nav',
			[
				'label' => __( 'Spacing', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range' => [
					'px' => [
						'min' => -200,
						'max' => 200,
					],
					'%' => [
						'min' => -100,
						'max' => 200,
					],
				],
				'selectors' => [					
					'{{WRAPPER}} .prev-nav' => 'left: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .next-nav' => 'right: {{SIZE}}{{UNIT}};',
				]
			]
		);
		$this->add_control(
			'arrow_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .slick-arrow' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_control(
			'arrow_hcolor',
			[
				'label' => __( 'Hover Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .slick-arrow:hover' => 'color: {{VALUE}};',
				]
			]
		);

		$this->end_controls_section();

	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( empty( $settings['imagebox_carousel'] ) ) {
			return;
		}
		$shows  = !empty( $settings['tshow'] ) ? $settings['tshow'] : 3;
		$tshows = !empty( $settings['tshow_tablet'] ) ? $settings['tshow_tablet'] : $shows;
		$mshows = !empty( $settings['tshow_mobile'] ) ? $settings['tshow_mobile'] : $tshows;
		$timeout  = isset( $settings['timeout']['size'] ) ? $settings['timeout']['size'] : '';
		$dots   = ( in_array( $settings['navigation'], [ 'dots', 'both' ] ) );
		$arrows = ( in_array( $settings['navigation'], [ 'arrows', 'both' ] ) );
		
		$owl_options = [
			'slides_show_desktop'  => absint( $shows ),
			'slides_show_tablet'   => absint( $tshows ),
			'slides_show_mobile'   => absint( $mshows ),
			'autoplay'      	   => $settings['autoplay'] ? $settings['autoplay'] : 'no',
			'autoplay_time_out'    => absint( $timeout ),
			'loop'      		   => $settings['loop'] ? $settings['loop'] : 'no' ,
			'arrows'        	   => $arrows,
			'dots'          	   => $dots
		];

		$this->add_render_attribute(
			'slides', [
				'class'               => 'ot-testimonial-slider ot-testimonial-s7',
				'data-slider_options' => wp_json_encode( $owl_options ),
			]
		);
		
		?>
		<div <?php echo $this->get_render_attribute_string( 'slides' ); ?>>
			<?php if ( ! empty( $settings['imagebox_carousel'] ) ) : foreach ( $settings['imagebox_carousel'] as $key => $item ) : 
				$title = $item['title'];
				if ( ! empty( $item['link']['url'] ) ) {
					$this->add_link_attributes( 'link' . $key, $item['link'] );
					$title = '<a '.$this->get_render_attribute_string('link' . $key).'>'. $title .'</a>';
				}
			?> 
			<?php if( !empty($item['image_box']['url']) ){ ?>
			<div>
				<div class="ot-testimonial-s7__item">
					<figure class="ot-testimonial-s7__item__img">
						<?php if( !empty($item['vlink']) ){ ?>
						<div class="ot-testimonial-s7__item__video video-popup">        
							<a class="btn-play" href="<?php echo esc_url( $item['vlink'] ); ?>">
								<i class="flaticon-play"></i>
							</a> 
						</div>
						<?php } ?>
						<?php echo wp_get_attachment_image( $item['image_box']['id'], 'full' ); ?>
					</figure>
					<div class="ot-testimonial-s7__item__content">
						<?php if( $settings['is_rate'] && !empty( $settings['image_rate']['url'] ) ) { ?>
		        			<div class="star-rate"><?php echo wp_get_attachment_image( $settings['image_rate']['id'], 'full' ); ?></div>
		        		<?php } ?>
						<h5 class="ot-testimonial-client"><?php echo $title; ?></h5>
						<?php if( $item['desc'] ) { echo '<div class="ot-testimonial-extra">' .$item['desc']. '</div>'; } ?>
					</div>
				</div>
				<?php } ?>
			</div>
			<?php endforeach; endif; ?>
		</div>
		
		<?php
	}

}
// After the Engitech_ImageBox_Carousel class is defined, I must register the new widget class with Elementor:
Plugin::instance()->widgets_manager->register( new Engitech_ImageBox_Carousel() );