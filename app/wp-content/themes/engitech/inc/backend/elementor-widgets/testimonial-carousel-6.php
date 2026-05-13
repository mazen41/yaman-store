<?php
namespace Elementor; // Custom widgets must be defined in the Elementor namespace
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly (security measure)

/**
 * Widget Name: Testimonial Slider
 */
class Engitech_Testimonials6 extends Widget_Base{

 	// The get_name() method is a simple one, you just need to return a widget name that will be used in the code.
	public function get_name() {
		return 'itestimonials6';
	}

	// The get_title() method, which again, is a very simple one, you need to return the widget title that will be displayed as the widget label.
	public function get_title() {
		return __( 'Engitech Testimonial Slider 6', 'engitech' );
	}

	// The get_icon() method, is an optional but recommended method, it lets you set the widget icon. you can use any of the eicon or font-awesome icons, simply return the class name as a string.
	public function get_icon() {
		return 'eicon-testimonial-carousel';
	}

	// The get_categories method, lets you set the category of the widget, return the category name as a string.
	public function get_categories() {
		return [ 'category_engitech' ];
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_testimonials',
			[
				'label' => __( 'Testimonials', 'engitech' ),
			]
		);
		$repeater = new Repeater();
		$repeater->add_control(
			'ttitle',
			[
				'label' => __( 'Title', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'default' => __( 'Excelent!', 'engitech' ),
			]
		);
		$repeater->add_control(
			'tcontent',
			[
				'label' => __( 'Content', 'engitech' ),
				'type' => Controls_Manager::TEXTAREA,
				'rows' => '10',
				'default' => __( '"You will never fake the feeling of being in such a place. The live minimalism base on the natural materials & alive unprocessed."', 'engitech' ),
			]
		);

		$repeater->add_control(
			'timage',
			[
				'label' => __( 'Avatar', 'engitech' ),
				'type' => Controls_Manager::MEDIA,
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],
			]
		);

		$repeater->add_control(
			'tname',
			[
				'label' => __( 'Name', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'default' => __( 'Anna Paulina', 'engitech' ),
			]
		);

		$repeater->add_control(
			'tjob',
			[
				'label' => __( 'Job', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'default' => __( 'Client of Company', 'engitech' ),
			]
		);

		$this->add_control(
		    'testi_slider',
		    [
		        'label'       => '',
		        'type'        => Controls_Manager::REPEATER,
		        'show_label'  => false,
		        'default'     => [
		            [
		             	'tcontent' => __( '"You will never fake the feeling of being in such a place. The live minimalism base on the natural materials & alive unprocessed."', 'engitech' ),
		                'timage'  => [
							'url' => Utils::get_placeholder_image_src(),
						],
						'tname'	  => __( 'Anna Paulina', 'engitech' ),
						'tjob'	  => __( 'Client of Company', 'engitech' ),
		            ],
		            [
		             	'tcontent' => __( '"You will never fake the feeling of being in such a place. The live minimalism base on the natural materials & alive unprocessed."', 'engitech' ),
		                'timage'  => [
							'url' => Utils::get_placeholder_image_src(),
						],
						'tname'	  => __( 'Anna Paulina', 'engitech' ),
						'tjob'	  => __( 'Client of Company', 'engitech' ),
		            ],
		            [
		             	'tcontent' => __( '"You will never fake the feeling of being in such a place. The live minimalism base on the natural materials & alive unprocessed."', 'engitech' ),
		                'timage'  => [
							'url' => Utils::get_placeholder_image_src(),
						],
						'tname'	  => __( 'Anna Paulina', 'engitech' ),
						'tjob'	  => __( 'Client of Company', 'engitech' ),
		            ]
		        ],
		        'fields'      => $repeater->get_controls(),
		        'title_field' => '{{{tname}}}',
		    ]
		);

		$this->add_control(
			'testi_slider_image_position',
			[
				'label' => __( 'Avatar Position', 'engitech' ),
				'type' => Controls_Manager::SELECT,
				'default' => 'aside',
				'options' => [
					'aside' => __( 'Aside', 'engitech' ),
					'top' => __( 'Top', 'engitech' ),
				],
				'style_transfer' => true,
				'separator' => 'before',
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

		$slides_show = range( 1, 10 );
		$slides_show = array_combine( $slides_show, $slides_show );
		$this->add_control(
			'heading_slider_option',
			[
				'label' => __( 'Slider Option', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
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

		// Styling.
		$this->start_controls_section(
			'style_tcontent',
			[
				'label' => __( 'General', 'engitech' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'ttitle_heading',
			[
				'label' => __( 'Title', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
			'ttitle_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tcontent .ttitle' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'ttitle_typography',
				'selector' => '{{WRAPPER}} .ot-testimonial-s1__item .tcontent .ttitle',
			]
		);

		$this->add_control(
			'heading_cbox',
			[
				'label' => __( 'Content Box', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$this->add_control(
			'tcontent_bg',
			[
				'label' => __( 'Background Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tcontent, {{WRAPPER}} .ot-testimonial-s1__item .tcontent:after' => 'background: {{VALUE}};',
				],
			]
		);
		$this->add_control(
			'tborder_color',
			[
				'label' => __( 'Border Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tcontent, {{WRAPPER}} .ot-testimonial-s1__item .tcontent:after' => 'border-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'tcontent_color',
			[
				'label' => __( 'Content Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tcontent' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'content_typography',
				'selector' => '{{WRAPPER}} .ot-testimonial-s1__item .tcontent',
			]
		);

		$this->add_control(
			'tcontent_padding',
			[
				'label' => __( 'Padding', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tcontent' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'ticon',
			[
				'label'   => esc_html__( 'Is Icon', 'engitech' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
			]
		);
		$this->add_control(
			'ticon_color',
			[
				'label' => __( 'Icon Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tcontent .ticon' => 'color: {{VALUE}};',
				],
				'condition'	=>[
					'ticon!'	=> ''
				]
			]
		);

		$this->end_controls_section();

		// Image.
		$this->start_controls_section(
			'style_timage',
			[
				'label' => __( 'Avatars', 'engitech' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'spacing_img',
			[
				'label' => __( 'Spacing', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 200,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .image-position-aside .tmeta > img' => 'margin-right: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .image-position-top .tmeta > img' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'image_size',
			[
				'label' => __( 'Width', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range' => [
					'px' => [
						'min' => 30,
						'max' => 200,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tmeta > img' => 'width: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .image-position-top .tmeta > img' => 'margin-top: calc(-{{SIZE}}{{UNIT}}/2);',
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
					'{{WRAPPER}} .ot-testimonial-s1__item .tmeta > img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// Name.
		$this->start_controls_section(
			'style_tname',
			[
				'label' => __( 'Name', 'engitech' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'name_color',
			[
				'label' => __( 'Text Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tmeta h6' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'name_typography',
				'selector' => '{{WRAPPER}} .ot-testimonial-s1__item .tmeta h6',
			]
		);

		$this->add_control(
			'spacing_name',
			[
				'label' => __( 'Spacing', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range' => [
					'px' => [
						'min' => 0,
						'max' => 200,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tmeta h6' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// Job.
		$this->start_controls_section(
			'style_tjob',
			[
				'label' => __( 'Job', 'engitech' ),
				'tab' => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'job_color',
			[
				'label' => __( 'Text Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-testimonial-s1__item .tmeta span' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'job_typography',
				'selector' => '{{WRAPPER}} .ot-testimonial-s1__item .tmeta span',
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
			'spacing_dots',
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
		$has_icon = 'yes' === $settings['ticon'];

		$dots   = ( in_array( $settings['navigation'], [ 'dots', 'both' ] ) );
		$arrows = ( in_array( $settings['navigation'], [ 'arrows', 'both' ] ) );
		$shows  = !empty( $settings['tshow'] ) ? $settings['tshow'] : 3;
		$tshows = !empty( $settings['tshow_tablet'] ) ? $settings['tshow_tablet'] : $shows;
		$mshows = !empty( $settings['tshow_mobile'] ) ? $settings['tshow_mobile'] : $tshows;
		$owl_options = [
			'slides_show_desktop'  => absint( $shows ),
			'slides_show_tablet'   => absint( $tshows ),
			'slides_show_mobile'   => absint( $mshows ),
			'autoplay'      	   => $settings['autoplay'] ? $settings['autoplay'] : 'no',
			'loop'      		   => $settings['loop'] ? $settings['loop'] : 'no' ,
			'arrows'        	   => $arrows,
			'dots'          	   => $dots
		];

		$this->add_render_attribute(
			'slides', [
				'class'               => 'ot-testimonial-slider ot-testimonial-s1 image-position-'.$settings['testi_slider_image_position'] ,
				'data-slider_options' => wp_json_encode( $owl_options ),
			]
		);
		?>

		<div <?php echo $this->get_render_attribute_string( 'slides' ); ?>>
			<?php if ( ! empty( $settings['testi_slider'] ) ) : foreach ( $settings['testi_slider'] as $testi ) : 
				if( $testi['timage']['id'] ){
					$image_html = wp_get_attachment_image( $testi['timage']['id'], 'thumbnail' ); // Get Value widget Image Size Control by name widget:  $settings['timage_size_size']
				}else{
					$image_html = '<img src="' . esc_attr( $testi['timage']['url'] ) . '" alt="' . esc_attr( $testi['tname'] ) . '">';
				}
				?>
				<div>
					<div class="ot-testimonial-s1__item">
				        <div class="tcontent">
				        	<?php if( $has_icon ){ ?><div class="ticon"><i class="flaticon-edit-tools-1"></i></div><?php } ?>
				        	<?php if( $testi['ttitle'] ){ ?><h4 class="ttitle"><?php echo $testi['ttitle']; ?></h4><?php } ?>
				        	<div class="tdesc"><?php echo $testi['tcontent']; ?></div>
				        </div>
				        <div class="tmeta flex-middle">
							<?php if($testi['timage']['url']) { echo $image_html; } ?>
				        	<div class="tinfo">
				        		<h6><?php echo $testi['tname']; ?></h6>
				        		<?php if($testi['tjob']) { ?><span><?php echo $testi['tjob']; ?></span><?php } ?>
				        		<?php if( $settings['is_rate'] && !empty( $settings['image_rate']['url'] ) ) { ?>
				        			<div class="star-rate"><img src="<?php echo esc_attr( $settings['image_rate']['url'] ); ?>" alt="<?php echo esc_attr( $testi['tname'] ); ?>"></div>
				        		<?php } ?>
				        	</div>
			        	</div>
		        	</div>
				</div>
			<?php endforeach; endif; ?>			
	    </div>

	    <?php
	}

}
// After the Engitech_Testimonials6 class is defined, I must register the new widget class with Elementor:
Plugin::instance()->widgets_manager->register( new Engitech_Testimonials6() );