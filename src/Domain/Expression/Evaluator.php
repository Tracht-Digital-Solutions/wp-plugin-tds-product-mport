<?php
/**
 * Safe mapping expression parser and evaluator.
 *
 * @package TDS\ProductImporter
 */

namespace TDS\ProductImporter\Domain\Expression;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Evaluates a deliberately small expression language without eval().
 */
final class Evaluator {
	/** @var array<int,array{type:string,value:mixed}> */
	private array $tokens = array();
	private int $position = 0;

	/**
	 * Parse and evaluate a text expression.
	 *
	 * @param array<string,mixed> $record Source record.
	 */
	public function evaluate( string $expression, array $record ): mixed {
		$ast = $this->parse( $expression );
		return $this->evaluate_ast( $ast, $record );
	}

	/**
	 * Parse an expression into the visual builder AST.
	 *
	 * @return array<string,mixed>
	 */
	public function parse( string $expression ): array {
		if ( strlen( $expression ) > 4000 ) {
			throw new InvalidArgumentException( 'Expression is too long.' );
		}
		$this->tokens   = $this->tokenize( $expression );
		$this->position = 0;
		$ast            = $this->parse_or();
		if ( 'eof' !== $this->current()['type'] ) {
			throw new InvalidArgumentException( 'Unexpected token near ' . (string) $this->current()['value'] . '.' );
		}
		return $ast;
	}

	/**
	 * Evaluate a sanitized visual AST.
	 *
	 * @param array<string,mixed> $node   Expression node.
	 * @param array<string,mixed> $record Source record.
	 */
	public function evaluate_ast( array $node, array $record, int $depth = 0 ): mixed {
		if ( $depth > 30 ) {
			throw new InvalidArgumentException( 'Expression is nested too deeply.' );
		}
		$type = (string) ( $node['type'] ?? '' );
		return match ( $type ) {
			'literal' => $node['value'] ?? null,
			'field' => $this->field( $record, (string) ( $node['name'] ?? '' ) ),
			'unary' => $this->unary(
				(string) ( $node['operator'] ?? '' ),
				$this->evaluate_ast( (array) ( $node['value'] ?? array() ), $record, $depth + 1 )
			),
			'binary' => $this->binary(
				(string) ( $node['operator'] ?? '' ),
				$this->evaluate_ast( (array) ( $node['left'] ?? array() ), $record, $depth + 1 ),
				$this->evaluate_ast( (array) ( $node['right'] ?? array() ), $record, $depth + 1 )
			),
			'call' => $this->call(
				(string) ( $node['name'] ?? '' ),
				array_map(
					fn( $arg ) => $this->evaluate_ast( (array) $arg, $record, $depth + 1 ),
					(array) ( $node['args'] ?? array() )
				)
			),
			default => throw new InvalidArgumentException( 'Unknown expression node type.' ),
		};
	}

	/**
	 * Tokenize expression text.
	 *
	 * @return array<int,array{type:string,value:mixed}>
	 */
	private function tokenize( string $input ): array {
		$tokens = array();
		$offset = 0;
		$length = strlen( $input );
		while ( $offset < $length ) {
			if ( preg_match( '/\G\s+/A', $input, $match, 0, $offset ) ) {
				$offset += strlen( $match[0] );
				continue;
			}
			if ( preg_match( '/\G\$([A-Za-z0-9_.:@-]+)/A', $input, $match, 0, $offset )
				|| preg_match( '/\G\[([^\]\r\n]{1,190})\]/A', $input, $match, 0, $offset ) ) {
				$tokens[] = array(
					'type'  => 'field',
					'value' => $match[1],
				);
				$offset  += strlen( $match[0] );
				continue;
			}
			if ( preg_match( '/\G([A-Za-z_][A-Za-z0-9_]*)/A', $input, $match, 0, $offset ) ) {
				$value    = $match[1];
				$lower    = strtolower( $value );
				$tokens[] = in_array( $lower, array( 'true', 'false', 'null' ), true )
					? array(
						'type'  => 'literal',
						'value' => match ( $lower ) {
													'true' => true, 'false' => false, default => null },
					)
					: array(
						'type'  => 'identifier',
						'value' => $value,
					);
				$offset += strlen( $match[0] );
				continue;
			}
			if ( preg_match( '/\G\d+(?:\.\d+)?/A', $input, $match, 0, $offset ) ) {
				$tokens[] = array(
					'type'  => 'literal',
					'value' => str_contains( $match[0], '.' ) ? (float) $match[0] : (int) $match[0],
				);
				$offset  += strlen( $match[0] );
				continue;
			}
			if ( '"' === $input[ $offset ] || "'" === $input[ $offset ] ) {
				$quote = $input[ $offset++ ];
				$value = '';
				while ( $offset < $length && $input[ $offset ] !== $quote ) {
					if ( '\\' === $input[ $offset ] && $offset + 1 < $length ) {
						++$offset;
						$value .= match ( $input[ $offset ] ) {
							'n' => "\n", 'r' => "\r", 't' => "\t", default => $input[ $offset ] };
					} else {
						$value .= $input[ $offset ];
					}
					++$offset;
				}
				if ( $offset >= $length ) {
					throw new InvalidArgumentException( 'Unterminated string literal.' );
				}
				++$offset;
				$tokens[] = array(
					'type'  => 'literal',
					'value' => $value,
				);
				continue;
			}
			$two = substr( $input, $offset, 2 );
			if ( in_array( $two, array( '==', '!=', '>=', '<=', '&&', '||' ), true ) ) {
				$tokens[] = array(
					'type'  => 'operator',
					'value' => $two,
				);
				$offset  += 2;
				continue;
			}
			$one = $input[ $offset ];
			if ( str_contains( '+-*/><!', $one ) ) {
				$tokens[] = array(
					'type'  => 'operator',
					'value' => $one,
				);
				++$offset;
				continue;
			}
			if ( str_contains( '(),', $one ) ) {
				$tokens[] = array(
					'type'  => $one,
					'value' => $one,
				);
				++$offset;
				continue;
			}
			throw new InvalidArgumentException( 'Invalid character in expression.' );
		}
		$tokens[] = array(
			'type'  => 'eof',
			'value' => null,
		);
		return $tokens;
	}

	/** @return array<string,mixed> */
	private function parse_or(): array {
		$node = $this->parse_and();
		while ( $this->accept( 'operator', '||' ) ) {
			$node = array(
				'type'     => 'binary',
				'operator' => '||',
				'left'     => $node,
				'right'    => $this->parse_and(),
			);
		}
		return $node;
	}

	/** @return array<string,mixed> */
	private function parse_and(): array {
		$node = $this->parse_equality();
		while ( $this->accept( 'operator', '&&' ) ) {
			$node = array(
				'type'     => 'binary',
				'operator' => '&&',
				'left'     => $node,
				'right'    => $this->parse_equality(),
			);
		}
		return $node;
	}

	/** @return array<string,mixed> */
	private function parse_equality(): array {
		$node = $this->parse_comparison();
		while ( 'operator' === $this->current()['type'] && in_array( $this->current()['value'], array( '==', '!=' ), true ) ) {
			$operator = (string) $this->advance()['value'];
			$node     = array(
				'type'     => 'binary',
				'operator' => $operator,
				'left'     => $node,
				'right'    => $this->parse_comparison(),
			);
		}
		return $node;
	}

	/** @return array<string,mixed> */
	private function parse_comparison(): array {
		$node = $this->parse_term();
		while ( 'operator' === $this->current()['type'] && in_array( $this->current()['value'], array( '>', '>=', '<', '<=' ), true ) ) {
			$operator = (string) $this->advance()['value'];
			$node     = array(
				'type'     => 'binary',
				'operator' => $operator,
				'left'     => $node,
				'right'    => $this->parse_term(),
			);
		}
		return $node;
	}

	/** @return array<string,mixed> */
	private function parse_term(): array {
		$node = $this->parse_factor();
		while ( 'operator' === $this->current()['type'] && in_array( $this->current()['value'], array( '+', '-' ), true ) ) {
			$operator = (string) $this->advance()['value'];
			$node     = array(
				'type'     => 'binary',
				'operator' => $operator,
				'left'     => $node,
				'right'    => $this->parse_factor(),
			);
		}
		return $node;
	}

	/** @return array<string,mixed> */
	private function parse_factor(): array {
		$node = $this->parse_unary();
		while ( 'operator' === $this->current()['type'] && in_array( $this->current()['value'], array( '*', '/' ), true ) ) {
			$operator = (string) $this->advance()['value'];
			$node     = array(
				'type'     => 'binary',
				'operator' => $operator,
				'left'     => $node,
				'right'    => $this->parse_unary(),
			);
		}
		return $node;
	}

	/** @return array<string,mixed> */
	private function parse_unary(): array {
		if ( 'operator' === $this->current()['type'] && in_array( $this->current()['value'], array( '!', '-' ), true ) ) {
			return array(
				'type'     => 'unary',
				'operator' => (string) $this->advance()['value'],
				'value'    => $this->parse_unary(),
			);
		}
		return $this->parse_primary();
	}

	/** @return array<string,mixed> */
	private function parse_primary(): array {
		$token = $this->current();
		if ( 'literal' === $token['type'] ) {
			$this->advance();
			return array(
				'type'  => 'literal',
				'value' => $token['value'],
			);
		}
		if ( 'field' === $token['type'] ) {
			$this->advance();
			return array(
				'type' => 'field',
				'name' => $token['value'],
			);
		}
		if ( 'identifier' === $token['type'] ) {
			$name = (string) $this->advance()['value'];
			$this->expect( '(' );
			$args = array();
			if ( ! $this->accept( ')' ) ) {
				do {
					$args[] = $this->parse_or();
				} while ( $this->accept( ',' ) );
				$this->expect( ')' );
			}
			return array(
				'type' => 'call',
				'name' => $name,
				'args' => $args,
			);
		}
		if ( $this->accept( '(' ) ) {
			$node = $this->parse_or();
			$this->expect( ')' );
			return $node;
		}
		throw new InvalidArgumentException( 'Expected a value in expression.' );
	}

	/**
	 * Read a record field, including dotted nested paths.
	 *
	 * @param array<string,mixed> $record Record.
	 */
	private function field( array $record, string $path ): mixed {
		if ( array_key_exists( $path, $record ) ) {
			return $record[ $path ];
		}
		$value = $record;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	private function unary( string $operator, mixed $value ): mixed {
		return match ( $operator ) {
			'!' => ! $this->truthy( $value ),
			'-' => -1 * (float) $value,
			default => throw new InvalidArgumentException( 'Unsupported unary operator.' ),
		};
	}

	private function binary( string $operator, mixed $left, mixed $right ): mixed {
		return match ( $operator ) {
			'||' => $this->truthy( $left ) || $this->truthy( $right ),
			'&&' => $this->truthy( $left ) && $this->truthy( $right ),
			'==' => $left == $right, // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			'!=' => $left != $right, // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			'>' => $left > $right,
			'>=' => $left >= $right,
			'<' => $left < $right,
			'<=' => $left <= $right,
			'+' => (float) $left + (float) $right,
			'-' => (float) $left - (float) $right,
			'*' => (float) $left * (float) $right,
			'/' => 0.0 === (float) $right ? throw new InvalidArgumentException( 'Division by zero.' ) : (float) $left / (float) $right,
			default => throw new InvalidArgumentException( 'Unsupported binary operator.' ),
		};
	}

	/**
	 * Invoke a whitelisted function.
	 *
	 * @param mixed[] $args Arguments.
	 */
	private function call( string $name, array $args ): mixed {
		$name = strtolower( $name );
		return match ( $name ) {
			'concat' => implode( '', array_map( fn( $value ) => $this->string( $value ), $args ) ),
			'coalesce' => $this->coalesce( $args ),
			'trim' => trim( $this->string( $args[0] ?? '' ) ),
			'lower' => mb_strtolower( $this->string( $args[0] ?? '' ) ),
			'upper' => mb_strtoupper( $this->string( $args[0] ?? '' ) ),
			'replace' => str_replace( $this->string( $args[1] ?? '' ), $this->string( $args[2] ?? '' ), $this->string( $args[0] ?? '' ) ),
			'regex_replace' => $this->regex_replace( $args ),
			'split' => explode( $this->string( $args[1] ?? ',' ), $this->string( $args[0] ?? '' ), 1000 ),
			'join' => implode( $this->string( $args[1] ?? ',' ), is_array( $args[0] ?? null ) ? $args[0] : array( $args[0] ?? '' ) ),
			'number' => $this->number( $args[0] ?? 0, $this->string( $args[1] ?? '.' ), $this->string( $args[2] ?? ',' ) ),
			'date' => $this->date( $args ),
			'if' => $this->truthy( $args[0] ?? false ) ? ( $args[1] ?? null ) : ( $args[2] ?? null ),
			default => throw new InvalidArgumentException( 'Unknown expression function: ' . $name ),
		};
	}

	/** @param mixed[] $args */
	private function coalesce( array $args ): mixed {
		foreach ( $args as $value ) {
			if ( null !== $value && '' !== $value ) {
				return $value;
			}
		}
		return null;
	}

	/** @param mixed[] $args */
	private function regex_replace( array $args ): string {
		$subject = $this->string( $args[0] ?? '' );
		$pattern = $this->string( $args[1] ?? '' );
		if ( strlen( $pattern ) > 200 || strlen( $subject ) > 100000 ) {
			throw new InvalidArgumentException( 'Regular expression input exceeds safety limits.' );
		}
		if ( strlen( $pattern ) < 3 || ctype_alnum( $pattern[0] ) || '\\' === $pattern[0] ) {
			throw new InvalidArgumentException( 'Invalid regular expression delimiter.' );
		}
		$safe_pattern = $pattern[0] . '(*LIMIT_MATCH=100000)(*LIMIT_RECURSION=1000)' . substr( $pattern, 1 );
		$result       = @preg_replace( $safe_pattern, $this->string( $args[2] ?? '' ), $subject ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid administrator-supplied patterns are converted to validation errors.
		if ( null === $result ) {
			throw new InvalidArgumentException( 'Invalid regular expression.' );
		}
		return $result;
	}

	private function number( mixed $value, string $decimal, string $thousands ): float {
		$value = str_replace( $thousands, '', $this->string( $value ) );
		$value = str_replace( $decimal, '.', $value );
		return (float) $value;
	}

	/** @param mixed[] $args */
	private function date( array $args ): string {
		$value  = $this->string( $args[0] ?? '' );
		$format = $this->string( $args[1] ?? 'Y-m-d' );
		try {
			return ( new DateTimeImmutable( $value ) )->format( $format );
		} catch ( \Exception ) {
			throw new InvalidArgumentException( 'Invalid date value.' );
		}
	}

	private function string( mixed $value ): string {
		if ( is_array( $value ) ) {
			return implode( ',', array_map( 'strval', $value ) );
		}
		return null === $value ? '' : (string) $value;
	}

	private function truthy( mixed $value ): bool {
		return ! in_array( $value, array( false, null, '', 0, '0', array() ), true );
	}

	/** @return array{type:string,value:mixed} */
	private function current(): array {
		return $this->tokens[ $this->position ];
	}

	/** @return array{type:string,value:mixed} */
	private function advance(): array {
		return $this->tokens[ $this->position++ ];
	}

	private function accept( string $type, ?string $value = null ): bool {
		$current = $this->current();
		if ( $current['type'] === $type && ( null === $value || $current['value'] === $value ) ) {
			++$this->position;
			return true;
		}
		return false;
	}

	private function expect( string $type ): void {
		if ( ! $this->accept( $type ) ) {
			throw new InvalidArgumentException( 'Expected ' . $type . '.' );
		}
	}
}
