<?php
/**
 * Hooks for importer
 *
 * @package Engitech
 */


/**
 * Importer the demo content
 *
 * @since  1.0
 *
 */
function engitech_importer() {
	return array(
		array(
			'name'       => 'Home 1 - Main Home',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home1.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home1-8/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 1',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 2 - IT Agency',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home2.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home1-8/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 2',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 3 - Software Company',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home3.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home1-8/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 3',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 4 - Web Development',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home4.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home1-8/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 4',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 5 - Startup Home',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home5.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home1-8/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 5',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 6 - Data Science',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home6.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home1-8/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 6',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 7 - SaaS App Landing',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home7.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home1-8/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 7',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 8 - Help Desk',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home8.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home1-8/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 8',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 9 - App Development',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home9.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home9/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 9',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 10 - Cyber Security',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home10.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home10/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 10',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 11 - Hosting',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home11.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home11/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 11',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 12 - Hardware Services',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home12.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home12/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 12',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 13 - ICO Landing Page (White)',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home13.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home13/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 13',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 14 - ICO Landing Page (Purple)',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home14.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home14/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 14',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 15 - IT Solutions',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home15.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home15/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 15',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 16 - Payments Solution',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home16.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home16/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 16',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 17 - AI Solution',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home17.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home17/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 17',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 18 - NFT Marketplace',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home18.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home18/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 18',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 19 - Investment Solution',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home19.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home19/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 19',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 20 - SaaS Landing',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home20.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home20/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 20',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 21 - Mobile App',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home21.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home21/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 21',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 22 - Software',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home22.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home22/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 22',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 23 - HR Managment',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home23.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home23/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 23',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 24 - Analytics Software',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home24.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home24/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 24',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 25 - Support Chat Platform',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home25.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home25/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 25',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
		array(
			'name'       => 'Home 26 - Webinar Home',
			'preview'    => 'https://engitech.s3.amazonaws.com/demo-content/home26.jpg',
			'content'    => 'https://engitech.s3.amazonaws.com/demo-content/home26/demo-content.xml',
			'customizer' => 'https://engitech.s3.amazonaws.com/demo-content/customizer.dat',
			'widgets'    => 'https://engitech.s3.amazonaws.com/demo-content/widgets.wie',
			'sliders'    => 'https://engitech.s3.amazonaws.com/demo-content/sliders.zip',
			'pages'      => array(
				'front_page' => 'Home 26',
				'blog'       => 'Blog',
				'shop'       => 'Shop',
				'cart'       => 'Cart',
				'checkout'   => 'Checkout',
				'my_account' => 'My Account',
			),
			'menus'      => array(
				'primary'   => 'main-menu',
			)
		),
	);
}

add_filter( 'soo_demo_packages', 'engitech_importer', 30 );