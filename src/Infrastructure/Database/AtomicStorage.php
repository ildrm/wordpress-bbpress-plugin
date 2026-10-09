<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Database;
/** Read-only current-site metadata, never conversion of an operator's existing tables. */
final class AtomicStorage {
	public function __construct( private Store $store ) {}
	public function ready(): bool {
		$tables = array();
		foreach ( array( 'posts', 'postmeta', 'users', 'usermeta', 'options' ) as $name ) {
			$tables[] = $this->store->wordpressTable( $name );}
		foreach ( Schema::TABLES as $name ) {
			$tables[] = $this->store->table( $name );}
		$tables       = array_values( array_unique( $tables ) );
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$rows         = $this->store->rows( "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($placeholders)", $tables );
		if ( count( $rows ) !== count( $tables ) ) {
			return false;}
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['TABLE_NAME'], $tables, true ) || strtoupper( (string) $row['ENGINE'] ) !== 'INNODB' ) {
				return false;}
		}
		return true;
	}
}
