<?php
namespace Elementor; // Custom widgets must be defined in the Elementor namespace
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly (security measure)

/**
 * Widget Name: Section Heading 
 */
class Engitech_Heading2 extends Widget_Base{

 	// The get_name() method is a simple one, you just need to return a widget name that will be used in the code.
	public function get_name() {
		return 'iheading2';
	}

	// The get_title() method, which again, is a very simple one, you need to return the widget title that will be displayed as the widget label.
	public function get_title() {
		return __( 'Engitech Heading 2', 'engitech' );
	}

	// The get_icon() method, is an optional but recommended method, it lets you set the widget icon. you can use any of the eicon or font-awesome icons, simply return the class name as a string.
	public function get_icon() {
		return 'eicon-heading';
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
				'label' => __( 'Content', 'engitech' ),
			]
		);

		$this->add_control(
			'iheading_title',
			[
				'label' => __( 'Subtitle & Title', 'engitech' ),
				'type' => Controls_Manager::HEADING,
			]
		);
		$this->add_control(
			'sub',
			[
				'label' => '',
				'type' => Controls_Manager::TEXT,
				'default' => __( 'Our service', 'engitech' ),
				'placeholder' => __( 'Enter your subtitle', 'engitech' ),
				'label_block' => true,
				'show_label' => false,
			]
		);

		$this->add_control(
			'title',
			[
				'label' => '',
				'type' => Controls_Manager::TEXTAREA,
				'default' => __( 'What we do', 'engitech' ),
				'placeholder' => __( 'Enter your title', 'engitech' ),
				'show_label' => false,
				'label_block' => true,
			]
		);

		$this->add_responsive_control(
			'text_align',
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
				'selectors' => [
					'{{WRAPPER}}' => 'text-align: {{VALUE}};',
				],
			]
		);
		$this->add_control(
			'tag',
			[
				'label' => __( 'HTML Tag', 'engitech' ),
				'type' => Controls_Manager::SELECT,
				'options' => [
					'h1'  => 'H1',
					'h2'  => 'H2',
					'h3'  => 'H3',
					'h4'  => 'H4',
					'h5'  => 'H5',
					'h6'  => 'H6',
					'div' => 'div',
					'p'   => 'p',
				],
				'default' => 'h2',
			]
		);

		$this->end_controls_section();

		//Style
		$this->start_controls_section(
			'style_section',
			[
				'label' => __( 'Heading', 'engitech' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		//Subtitle
		
		$this->add_control(
			'heading_stitle',
			[
				'label' => __( 'Subtitle', 'engitech' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$this->add_responsive_control(
			'stitle_bottom_space',
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
					'{{WRAPPER}} .ot-heading > span' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'stitle_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-heading > span' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_control(
			'stitle_dots',
			[
				'label'   => esc_html__( 'Is Dots', 'engitech' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => '',
			]
		);
		$this->add_responsive_control(
			'dots_space',
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
					'{{WRAPPER}} .ot-heading .stitle-dots:before' => 'left: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .ot-heading .stitle-dots:after' => 'right: {{SIZE}}{{UNIT}};',
				],
				'condition' => [
					'stitle_dots'	=> 'yes'
				]
			]
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			[
				'name' => 'stitle_dots_bg',
				'label' => esc_html__( 'Color', 'engitech' ),
				'types' => [ 'classic', 'gradient' ],
				'selector' => '{{WRAPPER}} .ot-heading .stitle-dots:before, {{WRAPPER}} .ot-heading .stitle-dots:after',
				'condition' => [
					'stitle_dots'	=> 'yes'
				]
			]
		);
		$this->add_control(
			'stitle_padd',
			[
				'label' => __( 'Padding', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px' ],
				'selectors' => [
					'{{WRAPPER}} .ot-heading > span' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_control(
			'stitle_radius',
			[
				'label' => __( 'Border Radius', 'engitech' ),
				'type' => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px' ],
				'selectors' => [
					'{{WRAPPER}} .ot-heading > span' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Background::get_type(),
			[
				'name' => 'stitle_bg',
				'label' => esc_html__( 'Background', 'engitech' ),
				'types' => [ 'classic', 'gradient' ],
				'selector' => '{{WRAPPER}} .ot-heading > span',
			]
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name' => 'stitle_border',
				'selector' => '{{WRAPPER}} .ot-heading > span',
			]
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name' => 'stitle_box_shadow',
				'label' => esc_html__( 'Box Shadow', 'engitech' ),
				'selector' => '{{WRAPPER}} .ot-heading > span',
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'stitle_typography',
				'selector' => '{{WRAPPER}} .ot-heading > span',
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
		$this->add_control(
			'title_color',
			[
				'label' => __( 'Color', 'engitech' ),
				'type' => Controls_Manager::COLOR,
				'default' => '',
				'selectors' => [
					'{{WRAPPER}} .ot-heading .main-heading' => 'color: {{VALUE}};',
				]
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name' => 'title_typography',
				'selector' => '{{WRAPPER}} .ot-heading .main-heading',
			]
		);

		$this->end_controls_section();

	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		?>
		<div class="ot-heading">
	        <?php if( !empty($settings['sub']) ) { ?>
	        <span class="<?php if( !empty($settings['stitle_dots']) ) { echo esc_attr('stitle-dots'); } ?>"><?php echo $settings['sub']; ?></span>
	        <?php }if($settings['title']) echo '<'.$settings['tag'].' class="main-heading">'.$settings['title'].'</'.$settings['tag'].'>' ?>
	    </div>
	    <?php
	}

}
// After the Engitech_Heading2 class is defined, I must register the new widget class with Elementor:
Plugin::instance()->widgets_manager->register( new Engitech_Heading2() );