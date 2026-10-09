<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain;
final class Failure extends \RuntimeException {
	public function __construct( public readonly string $reason, string $message, public readonly int $status = 400 ) {
		parent::__construct( $message ); }
}
