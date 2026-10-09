<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Queue;
use ForumIntelligence\Contracts\RealtimeTransport;
use ForumIntelligence\Domain\Messaging\Messaging;
use ForumIntelligence\Infrastructure\Database\Store;
final class PollingTransport implements RealtimeTransport {
	public function __construct( private Messaging $messages, private Store $store ) {}
	public function poll( int $conversation, int $user, int $cursor ): array {
		$this->store->limit( $user, 'chat_poll', 30, 60 );
		return $this->messages->messages( $conversation, $user, max( 0, $cursor ) ) + array( 'next_poll_ms' => 10000 );
	}
}
