<?php
namespace Elementor; // Custom widgets must be defined in the Elementor namespace
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly (security measure)

/**
 * Widget Name: Text Marquee
 */
class Engitech_Text_Marquee extends Widget_Base{

 	// The get_name() method is a simple one, you just need to return a widget name that will be used in the code.
	public function get_name() {
		return 'itext-marquee';
	}

	// The get_title() method, which again, is a very simple one, you need to return the widget title that will be displayed as the widget label.
	public function get_title() {
		return __( 'Engitech Text Marquee', 'engitech' );
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
			'section_industries',
			[
				'label' => __( 'Text', 'engitech' ),
			]
		);
		$repeater = new Repeater();
		
		$repeater->add_control(
			'ititle',
			[
				'label' => __( 'Title', 'engitech' ),
				'type' => Controls_Manager::TEXT,
				'default' => __( 'Business & Finance', 'engitech' ),
			]
        );
        $repeater->add_control(
			'dots_color',
			[
				'label' => esc_html__( 'Dot Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} {{CURRENT_ITEM}} h6:before' => 'background: {{VALUE}};',
				],
			]
		);

		$this->add_control(
		    'indus_marquee',
		    [
		        'label'       => '',
		        'type'        => Controls_Manager::REPEATER,
		        'show_label'  => false,
		        'default'     => [
		            [
						'ititle'	  => __( 'Helthcare', 'engitech' ),
		            ],
		            [
                       'ititle'	  => __( 'Manufacturing', 'engitech' ),
                    ],
                    [
                    	'ititle'	  => __( 'Logistics', 'engitech' ),
                    ]
               	],
		        'fields'      => $repeater->get_controls(),
		        'title_field' => '{{{ititle}}}',
		    ]
        );

		$slides_show = range( 1, 10 );
		$slides_show = array_combine( $slides_show, $slides_show );

		$this->add_control(
			'marquee_show',
			[
				'label' => __( 'Marquee To Show', 'engitech' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'' => __( 'Default', 'engitech' ),
				] + $slides_show,
				'separator' => 'before',
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
		$this->add_responsive_control(
			'height',
			[
				'label' => esc_html__( 'Height', 'engitech' ),
				'type' => Controls_Manager::SLIDER,
				'default' => [
					'unit' => 'px',
				],
				'tablet_default' => [
					'unit' => 'px',
				],
				'mobile_default' => [
					'unit' => 'px',
				],
				'size_units' => [ 'px', 'vh' ],
				'range' => [
					'px' => [
						'min' => 1,
						'max' => 500,
					],
					'vh' => [
						'min' => 1,
						'max' => 100,
					],
				],
				'selectors' => [
					'{{WRAPPER}} .ot-industries-marquee' => 'height: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'item_bg',
			[
				'label' => __( 'Background Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .industries-marquee-content li' => 'background: {{VALUE}};',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name' => 'item_border',
				'selector' => '{{WRAPPER}} .industries-marquee-content li',
			]
		);
		$this->add_responsive_control(
			'item_radius',
			[
				'label' => __( 'Border Radius', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors' => [
					'{{WRAPPER}} .industries-marquee-content li' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_responsive_control(
			'item_padding',
			[
				'label' => __( 'Padding Box', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors' => [
					'{{WRAPPER}} .industries-marquee-content li' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'tcontent_color',
			[
				'label' => __( 'Text Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .industries-marquee-content li h6' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'content_typography',
				'selector' => '{{WRAPPER}} .industries-marquee-content li h6',
			]
		);
		
		$this->end_controls_section();

	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$show = !empty( $settings['marquee_show'] ) ? $settings['marquee_show'] : 3;
		?>
		<div class="ot-industries-marquee" data-display="<?php echo esc_attr( $show ); ?>">	
			<ul class="industries-marquee-content unstyle">
			<?php if ( ! empty( $settings['indus_marquee'] ) ) : foreach ( $settings['indus_marquee'] as $indus ) : ?>
				<li class="indus-title elementor-repeater-item-<?php echo esc_attr( $indus['_id'] ) ?>">
                    <h6><?php echo $indus['ititle']; ?></h6>
	        	</li>
			<?php endforeach; endif; ?>
			</ul>
	    </div>
	    <?php
	}

}
// After the Engitech_Text_Marquee class is defined, I must register the new widget class with Elementor:
Plugin::instance()->widgets_manager->register( new Engitech_Text_Marquee() );