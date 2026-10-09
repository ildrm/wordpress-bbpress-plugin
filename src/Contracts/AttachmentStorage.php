<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
interface AttachmentStorage {
	public function store( string $temporary, string $name ): string;
	public function path( string $key ): string;
	public function delete( string $key ): void;
}
