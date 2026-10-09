<?php
declare(strict_types=1);
namespace ForumIntelligence\Frontend;
final class Pwa {
	public function __construct( private Frontend $frontend, private string $file, private bool $installable ) {}
	public function register(): void {
		if ( $this->installable ) {
			add_action( 'wp_enqueue_scripts', array( $this->frontend, 'assets' ) );}
		add_action(
			'wp_head',
			static function (): void {
				echo '<link rel="manifest" href="' . esc_url( add_query_arg( 'fi_manifest', '1', home_url( '/' ) ) ) . '">';
			}
		);
		add_action(
			'template_redirect',
			function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
				if ( isset( $_GET['fi_manifest'] ) ) {
					nocache_headers();
					header( 'Content-Type: application/manifest+json; charset=utf-8' );
					echo wp_json_encode(
						array(
							'name'             => get_bloginfo( 'name' ),
							'short_name'       => get_bloginfo( 'name' ),
							'start_url'        => home_url( '/' ),
							'scope'            => wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
							'display'          => 'standalone',
							'background_color' => '#ffffff',
							'theme_color'      => '#1d2327',
							'icons'            => array_map(
								fn( int $size ): array=>array(
									'src'     => get_site_icon_url( $size ) ?: plugins_url( 'assets/icons/community-' . $size . '.png', $this->file ),
									'sizes'   => $size . 'x' . $size,
									'type'    => 'image/png',
									'purpose' => 'any',
								),
								array( 192, 512 )
							),
						)
					);
					exit;
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
				if ( isset( $_GET['fi_worker'] ) ) {
					nocache_headers();
					header( 'Content-Type: application/javascript; charset=utf-8' );
					header( 'Service-Worker-Allowed: ' . wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
					$offline = wp_json_encode( __( 'You are offline. Reconnect to read discussions or send messages.', 'forum-intelligence-for-bbpress' ), JSON_HEX_TAG );
					$home    = wp_json_encode( home_url( '/' ), JSON_HEX_TAG );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript response uses JSON_HEX_TAG encoded values and an application/javascript content type.
					echo "self.addEventListener('install',()=>self.skipWaiting());self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));self.addEventListener('fetch',event=>{if(event.request.mode==='navigate')event.respondWith(fetch(event.request).catch(()=>new Response($offline,{headers:{'Content-Type':'text/plain; charset=utf-8'}})));});self.addEventListener('push',event=>{let data={};try{data=event.data?event.data.json():{};}catch{}event.waitUntil(self.registration.showNotification(data.title||'Community',{body:data.body||'',data:{url:$home}}));});self.addEventListener('notificationclick',event=>{event.notification.close();event.waitUntil(self.clients.openWindow($home));});";
					exit;
				}
			},
			1
		);
	}
}
