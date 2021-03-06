<?php

class SimpleLocalAvatarsTest extends \WP_Mock\Tools\TestCase {
	private $instance;

	public function setUp(): void {
		parent::setUp();

		$this->instance = Mockery::mock( 'Simple_Local_Avatars' )->makePartial();
		$user           = (object) [
			'ID' => 1
		];

		WP_Mock::userFunction( 'get_user_by' )
			->with( 'email', '' )
			->andReturn( false );

		WP_Mock::userFunction( 'get_user_by' )
			->with( 'email', Mockery::type( 'string' ) )
			->andReturn( $user );

		WP_Mock::userFunction( 'get_user_meta' )
			->with( 1, 'simple_local_avatar', true )
			->andReturn( [
				'media_id' => 101,
				'full'     => 'https://example.com/avatar.png',
				'96'       => 'https://example.com/avatar-96x96.png',
			] )
			->byDefault();

		WP_Mock::userFunction( 'get_user_meta' )
			->with( Mockery::type( 'numeric' ), 'simple_local_avatar_rating', true )
			->andReturn( 'G' );

		WP_Mock::userFunction( 'get_attached_file' )
			->with( 101 )
			->andReturn( '/avatar.png' );

	}

	public function tearDown(): void {
		$this->addToAssertionCount(
			Mockery::getContainer()->mockery_getExpectationCount()
		);
		parent::tearDown();
	}

	public function test_add_hooks() {
		WP_Mock::expectFilterAdded( 'pre_get_avatar_data', [ $this->instance, 'get_avatar_data'], 10, 2 );

		WP_Mock::expectActionAdded( 'admin_init', [ $this->instance, 'admin_init' ] );

		WP_Mock::expectActionAdded( 'admin_enqueue_scripts', [ $this->instance, 'admin_enqueue_scripts' ] );
		WP_Mock::expectActionAdded( 'show_user_profile', [ $this->instance, 'edit_user_profile' ] );
		WP_Mock::expectActionAdded( 'edit_user_profile', [ $this->instance, 'edit_user_profile' ] );

		WP_Mock::expectActionAdded( 'personal_options_update', [ $this->instance, 'edit_user_profile_update' ] );
		WP_Mock::expectActionAdded( 'edit_user_profile_update', [ $this->instance, 'edit_user_profile_update' ] );
		WP_Mock::expectActionAdded( 'admin_action_remove-simple-local-avatar', [ $this->instance, 'action_remove_simple_local_avatar' ] );
		WP_Mock::expectActionAdded( 'wp_ajax_assign_simple_local_avatar_media', [ $this->instance, 'ajax_assign_simple_local_avatar_media' ] );
		WP_Mock::expectActionAdded( 'wp_ajax_remove_simple_local_avatar', [ $this->instance, 'action_remove_simple_local_avatar' ] );
		WP_Mock::expectActionAdded( 'user_edit_form_tag', [ $this->instance, 'user_edit_form_tag' ] );

		WP_Mock::expectActionAdded( 'rest_api_init', [ $this->instance, 'register_rest_fields' ] );
		$this->instance->add_hooks();
	}

	public function test_get_avatar() {
		$img          = '<img src="https://example.com/avatar.png" />';
		$filtered_img = '<img src="https://example.com/avatar-filtered.png" />';
		WP_Mock::userFunction( 'get_avatar')->andReturn( $img );
		WP_Mock::expectFilter( 'simple_local_avatar', $img );

		$this->assertEquals( $img, $this->instance->get_avatar() );

		WP_Mock::onFilter( 'simple_local_avatar' )
			->with( $img )
			->reply( $filtered_img );

		$this->assertEquals( $filtered_img, $this->instance->get_avatar() );
	}

	public function test_get_avatar_data() {
			$avatar_data = $this->instance->get_avatar_data( [ 'size' => 96 ], 1 );
			$this->assertEquals( 'https://example.com/avatar-96x96.png', $avatar_data['url'] );
	}

	public function test_get_simple_local_avatar_url_with_empty_id() {
		$this->assertEmpty( $this->instance->get_simple_local_avatar_url( '', 96 ) );
	}

	public function test_get_simple_local_avatar_url_user_with_no_avatar() {
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 2, 'simple_local_avatar', true )
			->andReturn( [] );
		$this->assertEquals( '', $this->instance->get_simple_local_avatar_url( 2, 96 ) );
	}

	public function test_get_simple_local_avatar_url_media_file_deleted() {
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 2, 'simple_local_avatar', true )
			->andReturn( ['media_id' => 102 ] );
		WP_Mock::userFunction( 'get_attached_file' )
			->with( 102 )
			->andReturn( false );
		$this->assertEquals( '', $this->instance->get_simple_local_avatar_url( 2, 96 ) );
	}

	public function test_get_simple_local_avatar_url() {
		$this->assertEquals( 'https://example.com/avatar-96x96.png', $this->instance->get_simple_local_avatar_url( 1, 96 ) );
	}

	public function test_admin_init() {
		WP_Mock::userFunction( 'get_option' )
			->with( 'simple_local_avatars_caps' )
			->andReturn( false );

		WP_Mock::userFunction( 'register_setting' );
		WP_Mock::userFunction( 'add_settings_field' );

		$this->instance->admin_init();
	}

	public function test_admin_enqueue_scripts_wrong_screen() {
		WP_Mock::userFunction( 'current_user_can' )->never();
		$this->instance->admin_enqueue_scripts( 'index.php' );
	}

	public function test_sanitize_options() {
		$input = [
			'caps' => true,
		];
		$new_input = $this->instance->sanitize_options( $input );
		$this->assertArrayHasKey( 'caps', $new_input );
		$this->assertArrayHasKey( 'only', $new_input );
		$this->assertSame( 1, $new_input['caps'] );
		$this->assertSame( 0, $new_input['only'] );
	}

	public function test_user_edit_form_tag() {
		ob_start();
		$this->instance->user_edit_form_tag();
		$output = ob_get_clean();
		$this->assertEquals( 'enctype="multipart/form-data"', $output );
	}

	public function test_upload_size_limit() {
		WP_Mock::onFilter( 'simple_local_avatars_upload_limit' )
			->with( 2048 )
			->reply( 4096 );
		$this->assertEquals( 4096, $this->instance->upload_size_limit( 2048 ) );
	}

	public function test_avatar_delete() {
		WP_Mock::userFunction( 'get_user_meta' )
			->with( 1, 'simple_local_avatar', true )
			->andReturn( [] );

		WP_Mock::userFunction( 'delete_user_meta' )
			->never();

		$this->instance->avatar_delete( 1 );
	}
}
