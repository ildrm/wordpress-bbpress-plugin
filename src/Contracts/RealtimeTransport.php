<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
interface RealtimeTransport {
	/** Each poll must recheck participation; a cursor is never an access credential. */
	public function poll( int $conversation, int $user, int $cursor ): array;
}
